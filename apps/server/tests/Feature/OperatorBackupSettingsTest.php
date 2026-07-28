<?php

// Operator backup GUI (ADR 0011 slice 3): configure the offsite mirror disk,
// retention, prefix, and its S3 connection; test the connection; and queue a
// "run a backup now" (RunBackupJob) that records outcomes to backup_runs.

use App\Enums\AccountRole;
use App\Jobs\RunBackupJob;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Models\User;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupService;
use App\Support\Backup\PostgresDatabaseDumper;
use App\Support\Backup\RestoreService;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

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
    config()->set('queue.default', 'no-such-connection'); // dispatch will throw

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
