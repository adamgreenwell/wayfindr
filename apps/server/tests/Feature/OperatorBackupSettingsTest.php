<?php

// Operator backup GUI (ADR 0011 slice 3): configure the offsite mirror disk,
// retention, prefix, and its S3 connection; test the connection; and queue a
// "run a backup now" (RunBackupJob) that records outcomes to backup_runs.

use App\Enums\AccountRole;
use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupService;
use App\Support\Backup\PostgresDatabaseDumper;
use App\Support\Backup\RestoreService;
use App\Support\Queue\QueueConsumerHeartbeat;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// The suite runs on the array cache in a single process, which the in-GUI
// restore's durability guard rejects by default (array is process-local), and on
// the file maintenance driver, which the guard accepts only when shared storage
// is asserted. Opt both in so the durable-path tests exercise the restore flow;
// the rejection tests override these back to the secure defaults.
beforeEach(function (): void {
    config()->set('wayfindr.backup.restore_safe_cache_drivers', ['redis', 'memcached', 'dynamodb', 'array']);
    config()->set('wayfindr.backup.restore_file_maintenance_shared', true);
    // No real drain sleep in tests.
    config()->set('wayfindr.backup.restore_drain_seconds', 0);
});

function backupOperator(): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
}

function backupSettings(): OperatorSettings
{
    return app(OperatorSettings::class);
}

test('a non-operator cannot reach the backup settings', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.settings.backups.edit'))->assertForbidden();
});

test('the operator sees the backup settings form', function (): void {
    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('Backups')
        ->assertSee('Run a backup now')
        ->assertSee('Backup destination')
        ->assertSee('Test the offsite connection')
        ->assertSee('Back to operator console');
});

test('saving offsite backup settings stores them, with int and bool casts applied', function (): void {
    $settings = backupSettings();

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.update'), [
            'disk' => 'backups',
            'retention_days' => '30',
            'prefix' => 'inst-a',
            'bucket' => 'my-backups',
            'region' => 'us-east-2',
            'endpoint' => 'https://s3.us-east-2.amazonaws.com',
            'acl' => 'private',
            's3_access_key' => 'AKIA-test',
            's3_secret_key' => 's3cr3t',
            'use_path_style' => '1',
        ])
        ->assertRedirect(route('operator.settings.backups.edit'))
        ->assertSessionHas('status');

    expect($settings->get('backup.disk'))->toBe('backups')
        ->and($settings->get('backup.prefix'))->toBe('inst-a')
        ->and($settings->get('backup.s3_bucket'))->toBe('my-backups')
        ->and($settings->isSet('backup.s3_key'))->toBeTrue();

    $settings->applyOverrides();
    expect(config('wayfindr.backup.disk'))->toBe('backups')
        ->and(config('wayfindr.backup.retention_days'))->toBe(30) // int cast
        ->and(config('filesystems.disks.backups.bucket'))->toBe('my-backups')
        ->and(config('filesystems.disks.backups.use_path_style_endpoint'))->toBeTrue();
});

test('retention must be an integer within range', function (): void {
    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.update'), ['disk' => '', 'retention_days' => 'lots'])
        ->assertSessionHasErrors('retention_days');
});

test('the offsite disk requires a bucket, region, and ACL', function (): void {
    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.update'), ['disk' => 'backups'])
        ->assertSessionHasErrors(['bucket', 'region', 'acl']);
});

test('switching offsite off does not blank env S3 credentials', function (): void {
    $settings = backupSettings();

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.update'), ['disk' => '', 'retention_days' => '7'])
        ->assertRedirect();

    expect($settings->get('backup.disk'))->toBe('')
        ->and($settings->isSet('backup.s3_bucket'))->toBeFalse()
        ->and($settings->isSet('backup.s3_key'))->toBeFalse();
});

test('the stored backup access keys are never echoed', function (): void {
    $settings = backupSettings();
    $settings->set('backup.s3_key', 'super-secret-key');
    $settings->set('backup.s3_secret', 'super-secret-secret');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertDontSee('super-secret-key')
        ->assertDontSee('super-secret-secret');
});

test('replacing only one backup credential is rejected', function (): void {
    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.update'), [
            'disk' => 'backups',
            'bucket' => 'b', 'region' => 'r', 'acl' => 'private',
            's3_access_key' => 'only-key',
        ])
        ->assertSessionHasErrors('s3_access_key');
});

test('saving backup settings records an instance-scoped audit', function (): void {
    $this->actingAs(backupOperator())->post(route('operator.settings.backups.update'), [
        'disk' => 'backups',
        'retention_days' => '14',
        'bucket' => 'audit-bucket', 'region' => 'us-east-1', 'acl' => 'private',
        's3_access_key' => 'key-not-logged', 's3_secret_key' => 'secret-not-logged',
    ]);

    $event = AuditEvent::query()->where('action', 'operator_settings.backup.updated')->firstOrFail();

    expect($event->account_id)->toBeNull()
        ->and($event->metadata['offsite_disk'])->toBe('backups')
        ->and($event->metadata['retention_days'])->toBe(14)
        ->and(json_encode($event->metadata))->not->toContain('secret-not-logged');
});

test('a prefix with a traversal segment is rejected', function (): void {
    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.update'), [
            'disk' => '',
            'prefix' => 'tenant/../other', // BackupService would throw on this at runtime
        ])
        ->assertSessionHasErrors('prefix');
});

test('the backup job runs once with a generous timeout and fails on timeout', function (): void {
    config()->set('wayfindr.backup.job_timeout', 1800);
    $job = new RunBackupJob(1);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(1800)
        // Opts into immediate failed() on timeout, so a killed backup is not
        // left 'running' until retry_after (which is longer than the timeout).
        ->and($job->failOnTimeout)->toBeTrue();
});

test('an overlapping backup run is finalized as skipped, not run', function (): void {
    // Hold the shared backup lock so the runner sees another backup in progress.
    // The lock lives in BackupRunner so BOTH entry points (queued job and
    // scheduled command) serialize through it.
    $lock = Cache::lock('wayfindr:backup', 60);
    $lock->get();

    $service = Mockery::mock(BackupService::class);
    $service->shouldNotReceive('create'); // the actual backup must not run while locked

    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);
    $result = (new BackupRunner($service))->run($run, '/dest');

    expect($result)->toBeNull()
        ->and($run->fresh()->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->fresh()->message)->toContain('another backup was already running');

    $lock->release();
});

test('the scheduled backup command skips (does not run) when another backup holds the lock', function (): void {
    $lock = Cache::lock('wayfindr:backup', 60);
    $lock->get();

    // create() must not run; the command still reports success because the
    // in-progress backup covers the data, and records the skip in the history.
    $service = Mockery::mock(BackupService::class);
    $service->shouldNotReceive('create');
    $this->app->instance(BackupService::class, $service);

    $this->artisan('wayfindr:backup')->assertSuccessful();

    $run = BackupRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->message)->toContain('another backup was already running');

    $lock->release();
});

test('the backup job runs on the dedicated backups connection with a retry window past its timeout', function (): void {
    // The re-release guard: a slow backup must never be handed to a second
    // worker, so its connection's retry_after must exceed the job timeout.
    $job = new RunBackupJob(1);
    $retryAfter = (int) config('queue.connections.backups.retry_after');

    expect($job->connection)->toBe('backups')
        ->and($retryAfter)->toBeGreaterThan($job->timeout);
});

test('a failed dispatch finalizes the run instead of leaving it running', function (): void {
    // Empty the whole connection table so the dispatch throws whichever
    // connection it resolves to. Overriding queue.default alone does NOT reach
    // this dispatch -- RunBackupJob pins itself to 'backups' in its constructor
    // -- so the throw would come only from the backup queue's backend being
    // unreachable: green on CI, red on any machine with Redis running. Naming
    // 'backups' here instead would work today but silently stop forcing the
    // failure if that pin ever moved, leaving the test to pass on whatever the
    // default connection happened to do. With no connection configured at all,
    // the QueueManager throws for the pinned name and the default alike.
    config()->set('queue.connections', []); // dispatch will throw

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.run'))
        ->assertSessionHas('error');

    $run = BackupRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->message)->toContain('Could not queue');
});

test('the offsite test rejects an attachment disk as a backup target', function (): void {
    config()->set('wayfindr.backup.disk', 'attachments-s3'); // a real, but disallowed, disk

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertSessionHas('error');
});

test('run a backup now creates the run, queues the job, and audits the trigger', function (): void {
    Bus::fake();
    $operator = backupOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.backups.run'))
        ->assertRedirect(route('operator.settings.backups.edit'))
        ->assertSessionHas('status');

    $run = BackupRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(BackupRun::STATUS_RUNNING)
        ->and($run->triggered_by_id)->toBe($operator->id);

    Bus::assertDispatched(RunBackupJob::class);
    expect(AuditEvent::query()->where('action', 'operator_settings.backup.triggered')->exists())->toBeTrue();
});

test('the backup runner records a succeeded run', function (): void {
    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->once()->andReturn([
        'path' => '/backups/wayfindr-20260728.tar.gz',
        'size' => 2_097_152,
        'manifest' => [],
        'remote' => ['disk' => 'backups', 'key' => 'inst-a/wayfindr-20260728.tar.gz'],
    ]);
    $service->shouldReceive('pruneExpired')->once()->andReturn(['days' => 30, 'local' => 1, 'remote' => 1]);

    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);
    (new BackupRunner($service))->run($run, '/dest');

    expect($run->fresh()->status)->toBe(BackupRun::STATUS_SUCCEEDED)
        ->and($run->fresh()->size_bytes)->toBe(2_097_152)
        ->and($run->fresh()->offsite_key)->toBe('inst-a/wayfindr-20260728.tar.gz')
        ->and($run->fresh()->pruned_local)->toBe(1);
});

test('the backup runner records an offsite-upload failure as failed and does not prune', function (): void {
    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->once()->andReturn([
        'path' => '/backups/x.tar.gz',
        'size' => 1024,
        'manifest' => [],
        'remote' => ['disk' => 'backups', 'error' => 'access denied'],
    ]);
    $service->shouldNotReceive('pruneExpired'); // never prune after a failed offsite upload

    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);
    (new BackupRunner($service))->run($run, '/dest');

    expect($run->fresh()->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->fresh()->message)->toContain('access denied');
});

test('the backup runner records a create failure as failed and re-throws', function (): void {
    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->once()->andThrow(new RuntimeException('pg_dump not found'));

    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);

    expect(fn () => (new BackupRunner($service))->run($run, '/dest'))->toThrow(RuntimeException::class);

    expect($run->fresh()->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->fresh()->message)->toContain('pg_dump not found');
});

test('the backup job runs the pre-created record through the runner', function (): void {
    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);
    $runner = Mockery::mock(BackupRunner::class);
    $runner->shouldReceive('run')->once()->with(Mockery::on(fn ($r) => $r->id === $run->id), Mockery::any());

    (new RunBackupJob($run->id))->handle($runner);
});

test('a timed-out backup job marks its still-running record as failed', function (): void {
    // The run id is a constructor arg (part of the serialized payload), so
    // failed() finds it even though it runs on a fresh instance.
    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);

    (new RunBackupJob($run->id))->failed(new RuntimeException('timed out'));

    expect($run->fresh()->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->fresh()->message)->toContain('timed out');
});

test('the failed callback does not clobber an already-recorded failure', function (): void {
    $run = BackupRun::query()->create([
        'status' => BackupRun::STATUS_FAILED,
        'message' => 'the real error',
        'started_at' => now(),
    ]);

    (new RunBackupJob($run->id))->failed(new RuntimeException('timed out'));

    expect($run->fresh()->message)->toBe('the real error'); // left as BackupRunner recorded it
});

test('the offsite test reports when no offsite disk is configured', function (): void {
    config()->set('wayfindr.backup.disk', ''); // local only

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertSessionHas('error');
});

test('the offsite test passes on a working disk', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    Storage::fake('backups');

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertRedirect(route('operator.settings.backups.edit'))
        ->assertSessionHas('status');
});

test('backup_runs data is excluded from the dump so no phantom running run rides in', function (): void {
    expect((new PostgresDatabaseDumper)->excludedTableData())
        ->toContain('public.backup_runs');
});

test('the scheduled backup command records a run in the history', function (): void {
    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->andReturn([
        'path' => '/backups/x.tar.gz',
        'size' => 1024,
        'manifest' => [
            'attachment_storage_disk' => 'attachments',
            'wayfindr_version' => 'test',
            'includes_local_attachment_binaries' => false,
        ],
        'remote' => null,
    ]);
    $service->shouldReceive('pruneExpired')->andReturn(['days' => 0, 'local' => 0, 'remote' => 0]);
    $this->app->instance(BackupService::class, $service);

    $this->artisan('wayfindr:backup')->assertSuccessful();

    $run = BackupRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(BackupRun::STATUS_SUCCEEDED)
        ->and($run->triggered_by_id)->toBeNull(); // scheduler / CLI, not an operator
});

test('the latest backup run shows on the settings page', function (): void {
    $operator = backupOperator();
    BackupRun::query()->create([
        'status' => BackupRun::STATUS_SUCCEEDED,
        'archive_path' => '/backups/x.tar.gz',
        'size_bytes' => 1_048_576,
        'triggered_by_id' => $operator->id,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('Latest run')
        ->assertSee('Succeeded');
});

test('the latest run breaks a same-second tie by id, not started_at alone', function (): void {
    $operator = backupOperator();
    $moment = now(); // both runs share the same second-precision started_at

    // Older run (lower id): a success recorded in the same second.
    BackupRun::query()->create([
        'status' => BackupRun::STATUS_SUCCEEDED,
        'started_at' => $moment,
        'finished_at' => $moment,
    ]);

    // Newer run (higher id) triggered in the same second: still running.
    BackupRun::query()->create([
        'status' => BackupRun::STATUS_RUNNING,
        'message' => 'freshly-queued-run',
        'started_at' => $moment,
    ]);

    // The page must show the newer run, not the older same-second success.
    $this->actingAs($operator)
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('freshly-queued-run')
        ->assertDontSee('Succeeded');
});

test('the shipped backup queue connection targets redis with a valid connection name', function (): void {
    // Default install: the backup connection rides the same Redis the default
    // queue uses, so its connection name must be a real Redis connection.
    expect(config('queue.connections.backups.driver'))->toBe('redis')
        ->and(config('queue.connections.backups.connection'))->toBe('default');
});

test('a database-driven backup queue defaults its connection to the default DB connection', function (): void {
    // With the database driver, "connection" names a DATABASE connection, so the
    // default must be null (→ the default DB connection), not the Redis name.
    Env::getRepository()->set('BACKUP_QUEUE_DRIVER', 'database');

    try {
        $config = require base_path('config/queue.php');

        expect($config['connections']['backups']['driver'])->toBe('database')
            ->and($config['connections']['backups']['connection'])->toBeNull();
    } finally {
        Env::getRepository()->clear('BACKUP_QUEUE_DRIVER');
    }
});

test('a custom database-backed backup queue table is excluded from the dump', function (): void {
    config()->set('queue.connections.backups.table', 'backup_jobs');

    expect((new PostgresDatabaseDumper)->excludedTableData())
        ->toContain('public.backup_jobs');
});

test('a custom database-backed backup queue table counts as bookkeeping, not real data', function (): void {
    config()->set('queue.connections.backups.table', 'backup_jobs');

    $method = new ReflectionMethod(RestoreService::class, 'bookkeepingTables');

    expect($method->invoke(app(RestoreService::class)))->toContain('backup_jobs');
});

test('the backup lock lifetime exceeds the job timeout so a full-length backup stays serialized', function (): void {
    expect((int) config('wayfindr.backup.lock_ttl'))
        ->toBeGreaterThan((int) config('wayfindr.backup.job_timeout'));
});

test('a lock-acquisition failure finalizes the run instead of leaving it running', function (): void {
    // The cache backend is unreachable, so acquiring the lock throws.
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->andThrow(new RuntimeException('redis unreachable'));
    Cache::partialMock()->shouldReceive('lock')->andReturn($lock);

    $service = Mockery::mock(BackupService::class);
    $service->shouldNotReceive('create'); // never reached

    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);

    // Re-thrown so the CLI reports failure and a queued job is marked failed…
    expect(fn () => (new BackupRunner($service))->run($run, '/dest'))
        ->toThrow(RuntimeException::class);

    // …and the run row is finalized, not stuck 'running'.
    expect($run->fresh()->status)->toBe(BackupRun::STATUS_FAILED)
        ->and($run->fresh()->message)->toContain('Could not acquire the backup lock');
});

test('a lock-release failure does not turn a completed backup into a failure', function (): void {
    // perform() succeeds, then the cache drops as the lock is released.
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->andReturnTrue();
    $lock->shouldReceive('release')->andThrow(new RuntimeException('redis dropped'));
    Cache::partialMock()->shouldReceive('lock')->andReturn($lock);

    $service = Mockery::mock(BackupService::class);
    $service->shouldReceive('create')->once()->andReturn([
        'path' => '/backups/x.tar.gz',
        'size' => 4096,
        'manifest' => [],
        'remote' => null,
    ]);
    $service->shouldReceive('pruneExpired')->once()->andReturn(['days' => 0, 'local' => 0, 'remote' => 0]);

    $run = BackupRun::query()->create(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);
    $result = (new BackupRunner($service))->run($run, '/dest');

    expect($result)->not->toBeNull()
        ->and($run->fresh()->status)->toBe(BackupRun::STATUS_SUCCEEDED);
});

test('a non-operator cannot reach the backup history', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.settings.backups.history'))->assertForbidden();
});

test('the backup history lists runs newest-first with trigger, size, offsite, and detail', function (): void {
    $operator = backupOperator();

    // Older run: scheduled (null trigger), succeeded, mirrored offsite.
    BackupRun::query()->create([
        'status' => BackupRun::STATUS_SUCCEEDED,
        'size_bytes' => 2_097_152,
        'offsite_disk' => 'backups',
        'offsite_key' => 'inst-a/wayfindr-old.tar.gz',
        'triggered_by_id' => null,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour(),
    ]);

    // Newer run: operator-triggered, failed with a detail message, local only.
    BackupRun::query()->create([
        'status' => BackupRun::STATUS_FAILED,
        'message' => 'pg_dump not found on PATH',
        'triggered_by_id' => $operator->id,
        'started_at' => now(),
        'finished_at' => now(),
    ]);

    $response = $this->actingAs($operator)
        ->get(route('operator.settings.backups.history'))
        ->assertOk()
        ->assertSee('Backup history')
        ->assertSee('Scheduled')                    // null-trigger label
        ->assertSee($operator->name)                // operator-triggered run
        ->assertSee('pg_dump not found on PATH')    // failure detail
        ->assertSee('Uploaded to [backups]')        // offsite label
        ->assertSee('2.0 MB');                      // size formatting

    // Newest first: the newer failed run (its detail) renders before the older
    // succeeded run (its offsite label).
    $body = $response->getContent();
    expect(strpos($body, 'pg_dump not found on PATH'))
        ->toBeLessThan(strpos($body, 'Uploaded to [backups]'));
});

test('the backup history shows an empty state when there are no runs', function (): void {
    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.history'))
        ->assertOk()
        ->assertSee('No backup runs recorded yet');
});

test('the backup settings page links to the history', function (): void {
    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee(route('operator.settings.backups.history'));
});

// ---- Restore (slice 3b-2) --------------------------------------------------

function seedLocalArchives(array $filenames): string
{
    $base = sys_get_temp_dir().'/wf-restore-'.uniqid();
    config()->set('wayfindr.backup.path', $base);
    config()->set('wayfindr.backup.prefix', 'inst');
    $scoped = $base.'/inst';
    mkdir($scoped, 0777, true);
    foreach ($filenames as $name) {
        file_put_contents($scoped.'/'.$name, 'archive-bytes');
    }

    return $scoped;
}

// The seeded archives are not real tarballs, so bind a RestoreService whose
// submit-time preflight passes for the happy-path queue tests.
function stubPassingPreflight(): void
{
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('preflight')->andReturn([
        'archive_version' => '0.3.0',
        'running_version' => '0.3.0',
        'version_skew' => false,
    ]);
    app()->instance(RestoreService::class, $restores);
}

test('listLocalArchives returns real archives newest-first and ignores foreign files', function (): void {
    seedLocalArchives([
        'wayfindr-backup-20260727-100000-bbbbbb.tar.gz', // older
        'wayfindr-backup-20260728-100000-aaaaaa.tar.gz', // newer
        'notes.txt',                                     // ignored — not an archive
        'wayfindr-backup-bogus.tar.gz',                  // ignored — bad name
    ]);

    $archives = app(BackupService::class)->listLocalArchives();

    expect($archives)->toHaveCount(2)
        ->and($archives[0]['filename'])->toBe('wayfindr-backup-20260728-100000-aaaaaa.tar.gz')
        ->and($archives[1]['filename'])->toBe('wayfindr-backup-20260727-100000-bbbbbb.tar.gz');
});

test('resolveLocalArchivePath resolves a listed archive and rejects traversal or unknown names', function (): void {
    $scoped = seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);
    $service = app(BackupService::class);

    expect($service->resolveLocalArchivePath('wayfindr-backup-20260728-100000-aaaaaa.tar.gz'))
        ->toBe($scoped.'/wayfindr-backup-20260728-100000-aaaaaa.tar.gz')
        ->and($service->resolveLocalArchivePath('../../etc/passwd'))->toBeNull()
        ->and($service->resolveLocalArchivePath('wayfindr-backup-19990101-000000-ffffff.tar.gz'))->toBeNull();
});

test('a non-operator cannot reach the restore page', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.settings.backups.restore'))->assertForbidden();
});

test('the restore page lists local archives when the queue and cache are durable', function (): void {
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('Restore from backup')
        ->assertSee('Choose an archive')
        ->assertSee('wayfindr-backup-20260728-100000-aaaaaa.tar.gz');
});

test('the restore page points to the CLI when the queue is database-backed', function (): void {
    config()->set('queue.connections.backups.driver', 'database');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('In-GUI restore is unavailable')
        ->assertSee('BACKUP_QUEUE_DRIVER') // names the actual unmet prerequisite
        ->assertSee('php artisan wayfindr:restore');
});

test('the restore page points to the CLI when the cache is database-backed', function (): void {
    config()->set('cache.default', 'database');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('In-GUI restore is unavailable');
});

test('the restore page rejects a process-local array cache by default', function (): void {
    // Secure default (no 'array'): the array store is process-local, so the web
    // request and the worker would not share the status or the lock.
    config()->set('wayfindr.backup.restore_safe_cache_drivers', ['redis', 'memcached', 'dynamodb']);

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('In-GUI restore is unavailable');
});

test('the restore page rejects a failover cache wrapper', function (): void {
    // A failover chain reports its own top-level driver, not its members — the
    // allowlist rejects it rather than trust a possibly database-backed chain.
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['database', 'array']]);
    config()->set('cache.default', 'failover');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('In-GUI restore is unavailable');
});

test('the restore page points to the CLI when maintenance mode uses a database cache store', function (): void {
    // artisan down would write its marker into the database being replaced, so
    // maintenance would lift mid-restore.
    config()->set('app.maintenance.driver', 'cache');
    config()->set('app.maintenance.store', 'database');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('In-GUI restore is unavailable');
});

test('the restore page points to the CLI when file maintenance is not asserted shared', function (): void {
    // Default file driver without the shared-storage assertion: a multi-host
    // deployment's marker might not be visible to the web process. The queue and
    // cache are safe, so the message must name the ACTUAL unmet prerequisite —
    // the file-maintenance assertion — not wrongly blame the queue/cache.
    config()->set('app.maintenance.driver', 'file');
    config()->set('wayfindr.backup.restore_file_maintenance_shared', false);

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk()
        ->assertSee('In-GUI restore is unavailable')
        ->assertSee('WAYFINDR_RESTORE_FILE_MAINTENANCE_SHARED')
        ->assertDontSee('database-backed queue');
});

test('a second restore is rejected while one is already pending', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);
    stubPassingPreflight();

    $payload = [
        'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
        'confirm_name' => 'wayfindr-prod',
        'acknowledge' => '1',
        'workers_stopped' => '1',
    ];

    // First confirmation claims the pending slot and queues.
    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), $payload)
        ->assertRedirect(route('operator.settings.backups.edit'));

    // Second, before the first reaches a terminal state, is rejected.
    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), $payload)
        ->assertSessionHas('error');

    Bus::assertDispatchedTimes(RunRestoreJob::class, 1);
});

test('the pending claim returns a token and rejects a second concurrent claim', function (): void {
    $first = RunRestoreJob::claimPending();
    $second = RunRestoreJob::claimPending();

    expect($first)->toBeString()
        ->and($first)->not->toBeEmpty()
        ->and($second)->toBeNull();
});

test('a stale restore job does not release a newer pending claim', function (): void {
    $newer = RunRestoreJob::claimPending(); // a restore owns the slot

    // A stale job releasing with a DIFFERENT (older) token must not free it.
    RunRestoreJob::releasePending('older-token');
    expect(RunRestoreJob::claimPending())->toBeNull(); // still held

    // The owner's own token frees it atomically.
    RunRestoreJob::releasePending($newer);
    expect(RunRestoreJob::claimPending())->not->toBeNull(); // now claimable
});

test('the failed callback keeps the site down and never throws even when the cache fails', function (): void {
    Artisan::call('down');

    // A cache whose reads/writes throw (Redis unavailable); failed() must not throw
    // and must NOT lift maintenance — a timed-out restore may be partial.
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    $store->shouldReceive('forget')->andThrow(new RuntimeException('redis down'));
    Cache::swap($store);

    try {
        (new RunRestoreJob('x.tar.gz'))->failed(new RuntimeException('timed out'));

        expect(app()->isDownForMaintenance())->toBeTrue(); // kept down despite the cache failure
    } finally {
        // A fresh app per test discards the swapped mock; just ensure the app is up.
        Artisan::call('up');
    }
});

test('a version-skew restore keeps the site in maintenance for migrations', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->andReturn([
        'version_skew' => true,
        'archive_version' => '0.2.0',
        'running_version' => '0.3.0',
        'integrity' => ['dangling' => []],
    ]);

    try {
        (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);

        // Left down so an incompatible schema is never exposed; the message says
        // how to finish (migrate, then up).
        expect(app()->isDownForMaintenance())->toBeTrue()
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('succeeded')
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('migrate');
    } finally {
        Artisan::call('up');
    }
});

test('an unverifiable-version restore keeps the site in maintenance', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->andReturn([
        'version_skew' => false,           // not a skew — simply unprovable
        'version_indeterminate' => true,   // no release identity on either side
        'archive_version' => 'unknown',
        'running_version' => 'unknown',
        'integrity' => ['dangling' => []],
    ]);

    try {
        (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);

        // Fails safe: an unprovable schema match must not silently come back up.
        expect(app()->isDownForMaintenance())->toBeTrue()
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('succeeded')
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('could NOT be verified')
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('WAYFINDR_VERSION');
    } finally {
        Artisan::call('up');
    }
});

test('an unidentified ARCHIVE is not told to run migrations', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->andReturn([
        'version_skew' => false,
        'version_indeterminate' => true,
        'archive_version_known' => false, // the ARCHIVE is the unidentified side
        'running_version_known' => true,
        'archive_version' => 'unknown',
        'running_version' => 'v0.3.0',
        'integrity' => ['dangling' => []],
    ]);

    try {
        (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);

        $message = Cache::get(RunRestoreJob::STATUS_KEY)['message'];

        // Must blame the archive, not the install, and must NOT prescribe
        // migrations — the archive may be from newer code, where migrating here
        // is a no-op that leaves an incompatible schema live.
        expect(app()->isDownForMaintenance())->toBeTrue()
            ->and($message)->toContain('ARCHIVE carries no release identity')
            ->and($message)->not->toContain('migrate --force');
    } finally {
        Artisan::call('up');
    }
});

test('a restore leaves a pre-existing maintenance window in place', function (): void {
    Artisan::call('down'); // set by an operator or a deploy, before the restore

    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->andReturn([
        'version_skew' => false,
        'archive_version' => '0.3.0',
        'running_version' => '0.3.0',
        'integrity' => ['dangling' => []],
    ]);

    try {
        (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);

        // The restore ran, but the maintenance window it did not open stays up.
        expect(app()->isDownForMaintenance())->toBeTrue()
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('succeeded');
    } finally {
        Artisan::call('up');
    }
});

test('a restore failure before it entered maintenance does not lift unrelated maintenance mode', function (): void {
    // Maintenance mode set by someone else; this restore never recorded ownership.
    Artisan::call('down');

    try {
        (new RunRestoreJob('x.tar.gz'))->failed(new RuntimeException('failed before down'));

        expect(app()->isDownForMaintenance())->toBeTrue(); // not lifted — we don't own it
    } finally {
        Artisan::call('up');
    }
});

test('previewing an archive shows the confirmation form with version info', function (): void {
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('preflight')->once()->andReturn([
        'archive_version' => '0.2.0',
        'running_version' => '0.3.0',
        'version_skew' => true,
    ]);
    $this->app->instance(RestoreService::class, $restores);

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore', ['archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz']))
        ->assertOk()
        ->assertSee('Confirm the restore')
        ->assertSee('Type the instance name to confirm')
        ->assertSee('0.2.0')  // backup version
        ->assertSee('0.3.0'); // running version
});

test('the restore rejects a wrong instance name and does not queue', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'not-the-name',
            'acknowledge' => '1',
            'workers_stopped' => '1',
        ])
        ->assertSessionHasErrors('confirm_name');

    Bus::assertNotDispatched(RunRestoreJob::class);
});

test('the restore requires the acknowledgement checkbox', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'workers_stopped' => '1',
        ])
        ->assertSessionHasErrors('acknowledge');

    Bus::assertNotDispatched(RunRestoreJob::class);
});

test('the restore requires attesting the background workers are stopped', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'acknowledge' => '1',
        ])
        ->assertSessionHasErrors('workers_stopped');

    Bus::assertNotDispatched(RunRestoreJob::class);
});

test('the restore rejects an unknown archive', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-19990101-000000-ffffff.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'acknowledge' => '1',
            'workers_stopped' => '1',
        ])
        ->assertSessionHasErrors('archive');

    Bus::assertNotDispatched(RunRestoreJob::class);
});

test('a confirmed restore queues the job, records a durable status, and audits', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);
    stubPassingPreflight();
    $operator = backupOperator();

    $this->actingAs($operator)
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'acknowledge' => '1',
            'workers_stopped' => '1',
        ])
        ->assertRedirect(route('operator.settings.backups.edit'))
        ->assertSessionHas('status');

    Bus::assertDispatched(RunRestoreJob::class);

    $status = Cache::get(RunRestoreJob::STATUS_KEY);
    expect($status['status'])->toBe('running')
        ->and($status['archive'])->toBe('wayfindr-backup-20260728-100000-aaaaaa.tar.gz')
        ->and($status['triggered_by_name'])->toBe($operator->name);

    expect(AuditEvent::query()->where('action', 'operator_settings.backup.restore_triggered')->exists())->toBeTrue();
});

test('a malformed archive is rejected at submit, before anything is queued', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    // Submit-time preflight fails (a corrupt/swapped archive).
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('preflight')->andThrow(new RuntimeException('not a Wayfindr backup'));
    $this->app->instance(RestoreService::class, $restores);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'acknowledge' => '1',
            'workers_stopped' => '1',
        ])
        ->assertSessionHasErrors('archive');

    Bus::assertNotDispatched(RunRestoreJob::class);
    // Rejected before the slot was claimed, so nothing is left holding it.
    expect(RunRestoreJob::claimPending())->not->toBeNull();
});

test('a setup failure after claiming the slot releases the pending claim', function (): void {
    Bus::fake();
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);
    stubPassingPreflight();
    $operator = backupOperator();

    // The audit write fails (a transient DB outage) AFTER the slot is claimed.
    AuditEvent::creating(function (): void {
        throw new RuntimeException('db down');
    });

    $this->actingAs($operator)
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'acknowledge' => '1',
            'workers_stopped' => '1',
        ])
        ->assertSessionHas('error');

    Bus::assertNotDispatched(RunRestoreJob::class);
    // The slot must be freed so the operator can retry once the outage clears.
    expect(RunRestoreJob::claimPending())->not->toBeNull();
});

test('the restore refuses to run when the queue is database-backed', function (): void {
    Bus::fake();
    config()->set('queue.connections.backups.driver', 'database');
    config()->set('app.name', 'wayfindr-prod');
    seedLocalArchives(['wayfindr-backup-20260728-100000-aaaaaa.tar.gz']);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.restore.run'), [
            'archive' => 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz',
            'confirm_name' => 'wayfindr-prod',
            'acknowledge' => '1',
            'workers_stopped' => '1',
        ])
        ->assertSessionHas('error');

    Bus::assertNotDispatched(RunRestoreJob::class);
});

test('the restore job records a failure when the archive is gone', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn(null);
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldNotReceive('restore');

    (new RunRestoreJob('wayfindr-backup-20260728-100000-aaaaaa.tar.gz'))->handle($restores, $backups);

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed');
});

test('a superseded restore aborts without running', function (): void {
    // A newer restore has claimed the pending slot with its own token; this older
    // job holds a stale one whose lease lapsed.
    RunRestoreJob::claimPending();

    $backups = Mockery::mock(BackupService::class);
    $backups->shouldNotReceive('resolveLocalArchivePath'); // aborts before resolving
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldNotReceive('restore');

    (new RunRestoreJob('x.tar.gz', null, null, 'stale-token'))->handle($restores, $backups);

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed')
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('superseded');
});

test('a restore whose pending claim has expired aborts (does not run as if still permitted)', function (): void {
    // Nothing holds the pending slot (the lease lapsed), so PENDING is absent.
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldNotReceive('resolveLocalArchivePath');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldNotReceive('restore');

    (new RunRestoreJob('x.tar.gz', null, null, 'expired-token'))->handle($restores, $backups);

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed')
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('lapsed');
});

test('invalidating the settings cache lets rows replaced by a restore take effect', function (): void {
    $settings = app(OperatorSettings::class);
    $settings->set('backup.prefix', 'old-prefix');
    expect($settings->get('backup.prefix'))->toBe('old-prefix'); // now cached

    // Simulate the dump replacing the row directly (as a restore does — bypassing
    // set(), so no version bump). The cache would keep returning 'old-prefix'…
    OperatorSetting::query()->where('key', 'backup.prefix')->update(['value' => 'restored-prefix']);
    expect($settings->get('backup.prefix'))->toBe('old-prefix');

    // …until the restore invalidates it.
    $settings->invalidateCache();
    expect($settings->get('backup.prefix'))->toBe('restored-prefix');
});

test('a restore whose pending token still matches proceeds', function (): void {
    $token = RunRestoreJob::claimPending();

    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->andReturn([
        'version_skew' => false,
        'archive_version' => '0.3.0',
        'running_version' => '0.3.0',
        'integrity' => ['dangling' => []],
    ]);

    (new RunRestoreJob('x.tar.gz', null, null, $token))->handle($restores, $backups);

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('succeeded');
});

test('the restore page still renders when the status cache read fails', function (): void {
    seedLocalArchives([]);

    // The status-banner cache read throws (cache outage); the page must still load
    // (sessions are DB-backed) so the operator can read config/durability guidance.
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get')->andThrow(new RuntimeException('redis down'));
    Cache::swap($store);

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.restore'))
        ->assertOk();
});

test('the restore job runs the archive and records success with a summary', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->with('/backups/inst/x.tar.gz', true)->andReturn([
        'version_skew' => false,
        'archive_version' => '0.3.0',
        'running_version' => '0.3.0',
        'integrity' => ['dangling' => []],
    ]);

    (new RunRestoreJob('x.tar.gz', 7, 'Operator'))->handle($restores, $backups);

    $status = Cache::get(RunRestoreJob::STATUS_KEY);
    expect($status['status'])->toBe('succeeded')
        ->and($status['message'])->toContain('Restore complete')
        ->and($status['triggered_by_name'])->toBe('Operator');
});

test('a failed restore keeps the site in maintenance for the operator to verify', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    // A failure could be AFTER the DB transaction committed (partial restore).
    $restores->shouldReceive('restore')->once()->andThrow(new RuntimeException('failed copying attachments'));

    try {
        (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);
    } catch (RuntimeException) {
        // expected — the job re-throws the restore failure
    }

    try {
        // Left down (a partial restore must not be exposed), status failed, with
        // verify/restart guidance.
        expect(app()->isDownForMaintenance())->toBeTrue()
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed')
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('maintenance')
            ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('docker compose start');

        // The finally must have released the backup/restore lock despite the
        // failure, so the next backup or restore is not blocked.
        $lock = Cache::lock(BackupRunner::LOCK_KEY, 10);
        expect($lock->get())->toBeTrue();
        $lock->release();
    } finally {
        Artisan::call('up');
    }
});

test('a clean restore brings the site back up and reminds the operator to restart workers', function (): void {
    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldReceive('restore')->once()->andReturn([
        'version_skew' => false,
        'archive_version' => '0.3.0',
        'running_version' => '0.3.0',
        'integrity' => ['dangling' => []],
    ]);

    (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);

    expect(app()->isDownForMaintenance())->toBeFalse() // clean success → back up
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('succeeded')
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('docker compose start');
});

test('the restore job skips when a backup or restore already holds the lock', function (): void {
    $lock = Cache::lock(BackupRunner::LOCK_KEY, 60);
    $lock->get();

    $backups = Mockery::mock(BackupService::class);
    $backups->shouldReceive('resolveLocalArchivePath')->andReturn('/backups/inst/x.tar.gz');
    $restores = Mockery::mock(RestoreService::class);
    $restores->shouldNotReceive('restore');

    (new RunRestoreJob('x.tar.gz'))->handle($restores, $backups);

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed')
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('already running');

    $lock->release();
});

test('a timed-out restore job records a failure over a running status', function (): void {
    RunRestoreJob::putStatus('running', 'Restore in progress…', 'x.tar.gz');

    (new RunRestoreJob('x.tar.gz'))->failed(new RuntimeException('timed out'));

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('failed')
        ->and(Cache::get(RunRestoreJob::STATUS_KEY)['message'])->toContain('timed out');
});

test('the restore failed callback does not clobber a recorded terminal status', function (): void {
    RunRestoreJob::putStatus('succeeded', 'Restore complete.', 'x.tar.gz');

    (new RunRestoreJob('x.tar.gz'))->failed(new RuntimeException('late timeout'));

    expect(Cache::get(RunRestoreJob::STATUS_KEY)['status'])->toBe('succeeded');
});

test('the restore job is one-shot, fails on timeout, and rides the backups connection', function (): void {
    $job = new RunRestoreJob('x.tar.gz');

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->connection)->toBe('backups');
});

test('the backups settings page shows the latest restore status', function (): void {
    RunRestoreJob::putStatus('succeeded', 'Restore complete. All good.', 'wayfindr-backup-20260728-100000-aaaaaa.tar.gz', null, 'Operator Jane');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('Last restore: succeeded')
        ->assertSee('Operator Jane');
});

test('the backups page warns when nothing is consuming the queue', function (): void {
    // The failure this replaces: the only symptom of a missing worker was a run
    // stuck at "Running" forever, with nothing on the page saying why.
    config()->set('cache.default', 'file');
    Cache::store('file')->clear();

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('No worker has been seen on the backups queue.')
        ->assertSee('queue:work backups --queue=backups', escape: false);
});

test('the backups page drops the warning once a worker announces itself', function (): void {
    config()->set('cache.default', 'file');
    Cache::store('file')->clear();

    app(QueueConsumerHeartbeat::class)->record('backups', 'backups');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertDontSee('No worker has been seen on the backups queue.');
});

test('the backups page says "cannot tell" rather than claiming no worker', function (): void {
    // An array store cannot carry a sighting between processes, so a worker
    // could be running perfectly and still be invisible. Reporting "none" would
    // present a guess as a fact and send the operator chasing a live worker.
    config()->set('cache.default', 'array');

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertDontSee('No worker has been seen on the backups queue.');
});

test('the backups page does not claim "no worker" when the heartbeat is unreadable', function (): void {
    // A configured-but-unreachable cache must not produce a confident "add a
    // worker" for an operator whose worker is running fine.
    //
    // The unreadable state is injected rather than produced by pointing the
    // cache at a dead server: OperatorSettings reads the cache too, so a broken
    // store 500s this page long before the worker panel renders. That is a real
    // (and separate) fragility, but it makes the global approach test the error
    // page instead of this branch.
    app()->instance(QueueConsumerHeartbeat::class, new class extends QueueConsumerHeartbeat
    {
        public function observe(string $connection, ?string $queue): array
        {
            return ['state' => self::UNKNOWN, 'at' => null];
        }
    });

    BackupRun::query()->create(['status' => BackupRun::STATUS_SUCCEEDED, 'started_at' => now()]);

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertDontSee('No worker has been seen on the backups queue.')
        ->assertSee('Cannot tell');
});

test('the backups page warns when the worker sighting has gone stale', function (): void {
    // A worker that ran once and stopped leaves a readable record. Treating
    // ever-seen as healthy would stay silent while "Run a backup now" queued
    // jobs nothing would pick up -- the exact failure the notice prevents.
    config()->set('cache.default', 'file');
    config()->set('queue.connections.backups.retry_after', 3900);
    Cache::store('file')->clear();

    app(QueueConsumerHeartbeat::class)->record('backups', 'backups');

    $this->travel(3)->hours();

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('No worker has been seen on the backups queue since');

    $this->travelBack();
});

test('the remediation command names the configured backup queue', function (): void {
    // BACKUP_QUEUE can move the backups queue off its default. A hard-coded
    // --queue=backups would tell that operator to drain a queue nothing
    // dispatches to, leaving backups queued forever after they did as told.
    config()->set('cache.default', 'file');
    config()->set('queue.connections.backups.queue', 'wayfindr-backups');
    config()->set('wayfindr.backup.job_timeout', 7200);
    Cache::store('file')->clear();

    $this->actingAs(backupOperator())
        ->get(route('operator.settings.backups.edit'))
        ->assertOk()
        ->assertSee('--queue=wayfindr-backups', escape: false)
        ->assertSee('--timeout=7200', escape: false);
});
