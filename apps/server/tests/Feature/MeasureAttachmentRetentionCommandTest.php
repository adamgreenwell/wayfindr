<?php

use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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
            ->and(Storage::disk('attachments')->allFiles())->toBe([]);
    } finally {
        putenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE');
        putenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY');

        if (file_exists($output)) {
            unlink($output);
        }
    }
});
