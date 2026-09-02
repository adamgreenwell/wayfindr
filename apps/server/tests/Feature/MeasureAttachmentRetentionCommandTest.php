<?php

use App\Models\Account;
use App\Support\Attachments\AttachmentRetentionReportReservation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

test('a report reservation excludes a concurrent writer and never replaces its output', function (): void {
    $output = sys_get_temp_dir().'/wayfindr-retention-reservation-'.Str::lower((string) Str::ulid()).'.json';
    $reservation = AttachmentRetentionReportReservation::claim($output);

    try {
        expect(file_exists($output))->toBeFalse()
            ->and(file_exists($output.'.lock'))->toBeTrue()
            ->and(fn () => AttachmentRetentionReportReservation::claim($output))
            ->toThrow(RuntimeException::class, 'already reserved');

        $reservation->publish("first report\n");

        expect(file_get_contents($output))->toBe("first report\n");
    } finally {
        $reservation->release();

        foreach (glob($output.'*') ?: [] as $path) {
            unlink($path);
        }
    }
});

test('report publication refuses a destination created after reservation', function (): void {
    $output = sys_get_temp_dir().'/wayfindr-retention-publication-race-'.Str::lower((string) Str::ulid()).'.json';
    $reservation = AttachmentRetentionReportReservation::claim($output);

    try {
        file_put_contents($output, "other writer\n");

        expect(fn () => $reservation->publish("measurement\n"))
            ->toThrow(RuntimeException::class, 'Refusing to replace')
            ->and(file_get_contents($output))->toBe("other writer\n");
    } finally {
        $reservation->release();

        foreach (glob($output.'*') ?: [] as $path) {
            unlink($path);
        }
    }
});

test('the attachment retention measurement refuses to clean up without both disposable guards', function (): void {
    $account = Account::query()->create([
        'name' => 'Measurement Desk',
        'slug' => 'wayfindr-measurement-desk',
    ]);
    $output = sys_get_temp_dir().'/wayfindr-retention-refusal-'.Str::lower((string) Str::ulid()).'.json';

    putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE');

    $this->artisan('wayfindr:measure-attachment-retention', [
        '--objects' => 20,
        '--bytes' => 64,
        '--output' => $output,
        '--allow-dirty' => true,
        '--skip-scheduler-probe' => true,
    ])->assertFailed();

    expect(Account::query()->whereKey($account->id)->exists())->toBeTrue()
        ->and(file_exists($output))->toBeFalse();
});

test('the preflight validates the disposable targets without changing the database', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The measurement preflight deliberately requires an isolated SQLite database.');
    }

    $account = Account::query()->create([
        'name' => 'Preflight Control',
        'slug' => 'preflight-control',
    ]);
    $output = sys_get_temp_dir().'/wayfindr-retention-preflight-'.Str::lower((string) Str::ulid()).'.json';

    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    config()->set('wayfindr.attachments.orphan_grace_hours', 1);
    config()->set('filesystems.disks.attachments-s3.bucket', null);
    putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES');
    putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY=testing preflight');

    try {
        $this->artisan('wayfindr:measure-attachment-retention', [
            '--objects' => 20,
            '--bytes' => 64,
            '--output' => $output,
            '--confirm-disposable' => true,
            '--allow-dirty' => true,
            '--preflight-only' => true,
        ])->expectsOutputToContain('disposable-target preflight passed')
            ->assertSuccessful();

        expect(Account::query()->whereKey($account->id)->exists())->toBeTrue()
            ->and(file_exists($output))->toBeFalse();
    } finally {
        putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE');
        putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY');
    }
});

test('the local preflight refuses another configured attachment disk', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The measurement preflight deliberately requires an isolated SQLite database.');
    }

    $account = Account::query()->create([
        'name' => 'Remote Disk Control',
        'slug' => 'remote-disk-control',
    ]);
    $output = sys_get_temp_dir().'/wayfindr-retention-remote-refusal-'.Str::lower((string) Str::ulid()).'.json';

    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    config()->set('wayfindr.attachments.orphan_grace_hours', 1);
    config()->set('filesystems.disks.attachments-s3', [
        'driver' => 's3',
        'bucket' => 'operator-bucket',
        'endpoint' => 'https://object-store.example.test',
        'key' => 'operator-key',
        'secret' => 'operator-secret',
    ]);
    putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES');
    putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY=testing refusal');

    try {
        $this->artisan('wayfindr:measure-attachment-retention', [
            '--objects' => 20,
            '--bytes' => 64,
            '--output' => $output,
            '--confirm-disposable' => true,
            '--allow-dirty' => true,
            '--preflight-only' => true,
        ])->expectsOutputToContain('refuses additional configured attachment disks: attachments-s3')
            ->assertFailed();

        expect(Account::query()->whereKey($account->id)->exists())->toBeTrue()
            ->and(file_exists($output))->toBeFalse();
    } finally {
        putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE');
        putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY');
    }
});

test('the preflight resolves a symlinked report parent before enforcing the repository boundary', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The measurement preflight deliberately requires an isolated SQLite database.');
    }

    $repositoryRoot = (string) realpath(base_path('../..'));
    $linkedParent = sys_get_temp_dir().'/wayfindr-retention-report-link-'.Str::lower((string) Str::ulid());
    $output = $linkedParent.'/report.json';

    expect(symlink($repositoryRoot, $linkedParent))->toBeTrue();
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    config()->set('wayfindr.attachments.orphan_grace_hours', 1);
    config()->set('filesystems.disks.attachments-s3.bucket', null);
    putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES');
    putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY=testing report boundary');

    try {
        $this->artisan('wayfindr:measure-attachment-retention', [
            '--objects' => 20,
            '--bytes' => 64,
            '--output' => $output,
            '--confirm-disposable' => true,
            '--allow-dirty' => true,
            '--preflight-only' => true,
        ])->expectsOutputToContain('outside the repository')
            ->assertFailed();

        expect(file_exists($output))->toBeFalse();
    } finally {
        putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE');
        putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY');

        if (is_link($linkedParent)) {
            unlink($linkedParent);
        }
    }
});

test('the real preflight refuses a disposable SQLite path that links outside its temporary root', function (): void {
    if (! function_exists('symlink')) {
        $this->markTestSkipped('This regression requires symbolic-link support.');
    }

    $suffix = Str::lower((string) Str::ulid());
    $temporaryRoot = sys_get_temp_dir().'/wayfindr-attachment-retention-'.$suffix;
    $outsideDatabase = sys_get_temp_dir().'/wayfindr-retention-outside-'.$suffix.'.sqlite';
    $linkedDatabase = $temporaryRoot.'/database.sqlite';
    $storage = $temporaryRoot.'/storage';
    $output = sys_get_temp_dir().'/wayfindr-retention-linked-db-'.$suffix.'.json';

    mkdir($storage.'/app/private/attachments', 0777, true);
    mkdir($storage.'/framework/cache', 0777, true);
    mkdir($storage.'/framework/sessions', 0777, true);
    mkdir($storage.'/framework/views', 0777, true);
    mkdir($storage.'/logs', 0777, true);
    $sqlite = new SQLite3($outsideDatabase);
    $sqlite->exec('CREATE TABLE untouched (id INTEGER PRIMARY KEY, value TEXT)');
    $sqlite->exec("INSERT INTO untouched (value) VALUES ('control')");
    $sqlite->close();
    $before = hash_file('sha256', $outsideDatabase);

    expect(symlink($outsideDatabase, $linkedDatabase))->toBeTrue();

    $process = new Process([
        PHP_BINARY,
        base_path('artisan'),
        'wayfindr:measure-attachment-retention',
        '--objects=20',
        '--bytes=64',
        '--output='.$output,
        '--confirm-disposable',
        '--allow-dirty',
        '--preflight-only',
    ], base_path(), [
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'false',
        'APP_URL' => 'http://127.0.0.1',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'APP_CONFIG_CACHE' => $temporaryRoot.'/config.php',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $linkedDatabase,
        'LARAVEL_STORAGE_PATH' => $storage,
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'BROADCAST_CONNECTION' => 'log',
        'WAYFINDR_ATTACHMENT_STORAGE_DISK' => 'attachments',
        'WAYFINDR_ATTACHMENT_S3_BUCKET' => '',
        'WAYFINDR_ATTACHMENT_ORPHAN_GRACE_HOURS' => '1',
        'WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE' => 'YES',
        'WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY' => 'testing hostile SQLite symlink',
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput().$process->getErrorOutput())->toContain('must not be a symbolic link')
            ->and(hash_file('sha256', $outsideDatabase))->toBe($before)
            ->and(file_exists($output))->toBeFalse();
    } finally {
        foreach (glob($output.'*') ?: [] as $path) {
            unlink($path);
        }

        if (is_link($linkedDatabase)) {
            unlink($linkedDatabase);
        }

        if (file_exists($outsideDatabase)) {
            unlink($outsideDatabase);
        }

        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($temporaryRoot);
    }
});

test('the local measurement proves deletion survival and bounded cleanup', function (): void {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('The measurement command deliberately requires an isolated SQLite database.');
    }

    Storage::fake('attachments');
    config()->set('wayfindr.attachments.storage_disk', 'attachments');
    config()->set('wayfindr.attachments.pending_expiry_hours', 24);
    config()->set('wayfindr.attachments.orphan_grace_hours', 1);

    $this->artisan('wayfindr:seed-desk', [
        '--conversations' => 1,
        '--months' => 1,
        '--agents' => 1,
        '--sites' => 1,
        '--messages' => 1,
        '--fresh' => true,
    ])->assertSuccessful();

    $output = sys_get_temp_dir().'/wayfindr-retention-local-'.Str::lower((string) Str::ulid()).'.json';
    putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES');
    putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY=testing fake local disk');

    try {
        $this->artisan('wayfindr:measure-attachment-retention', [
            '--objects' => 20,
            '--bytes' => 64,
            '--output' => $output,
            '--confirm-disposable' => true,
            '--allow-dirty' => true,
            '--skip-scheduler-probe' => true,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        expect($report['workload']['categories'])->toBe([
            'bound' => 8,
            'fresh-pending' => 2,
            'expired-pending' => 4,
            'failed' => 2,
            'old-orphan' => 3,
            'recent-orphan' => 1,
        ])->and($report['seed']['objects']['count'])->toBe(20)
            ->and($report['seed']['objects']['bytes'])->toBe(1280)
            ->and($report['dry_run']['exit_code'])->toBe(0)
            ->and($report['dry_run']['expected_eligible_rows'])->toBe(6)
            ->and($report['dry_run']['expected_eligible_orphan_objects'])->toBe(3)
            ->and($report['sweep']['exit_code'])->toBe(0)
            ->and($report['after']['objects']['count'])->toBe(11)
            ->and($report['after']['objects']['bytes'])->toBe(704)
            ->and($report['verification'])->each->toBeTrue()
            ->and($report['scheduler_probe']['testing_only_skip'])->toBeTrue()
            ->and($report['cleanup'])->each->toBeTrue()
            ->and($report['s3_request_attempts'])->toBeNull()
            ->and(Account::query()->where('slug', 'wayfindr-measurement-desk')->exists())->toBeFalse()
            ->and(Storage::disk('attachments')->allFiles())->toBe([])
            ->and(file_exists($output.'.lock'))->toBeFalse();
    } finally {
        putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE');
        putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY');

        if (file_exists($output)) {
            unlink($output);
        }
    }
});
