<?php

// The remote (S3-compatible) attachment storage surface (ADR 0007). The
// configured disk steers NEW uploads only; every row records its own disk, so
// local and remote files coexist and serve through the same authorized,
// stream-through endpoints. Misconfiguration fails loud (rejected uploads +
// readiness attention), never lands files somewhere unintended.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentStorage;
use App\Support\Attachments\AttachmentUploadService;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('attachments');
    Storage::fake('attachments-s3');
});

function storageSurfaceFixture(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id])->load('site');

    return compact('account', 'site', 'visitor', 'conversation');
}

// --- Disk selection --------------------------------------------------------

test('uploads land on the configured remote disk and the row records it', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s3']);
    $f = storageSurfaceFixture();

    $attachment = app(AttachmentUploadService::class)
        ->store($f['conversation'], UploadedFile::fake()->image('remote.png'), $f['visitor']);

    expect($attachment->storage_disk)->toBe('attachments-s3');
    Storage::disk('attachments-s3')->assertExists($attachment->storage_key);
    Storage::disk('attachments')->assertMissing($attachment->storage_key);
});

test('the default remains the local attachments disk', function (): void {
    $f = storageSurfaceFixture();

    $attachment = app(AttachmentUploadService::class)
        ->store($f['conversation'], UploadedFile::fake()->image('local.png'), $f['visitor']);

    expect($attachment->storage_disk)->toBe('attachments');
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

test('an unknown storage disk rejects uploads loudly', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s4']);
    $f = storageSurfaceFixture();

    expect(fn () => app(AttachmentUploadService::class)
        ->store($f['conversation'], UploadedFile::fake()->image('x.png'), $f['visitor']))
        ->toThrow(InvalidArgumentException::class);

    expect(ConversationMessageAttachment::count())->toBe(0);
});

test('the public disk is refused even though it exists', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'public']);

    expect(fn () => AttachmentStorage::diskName())->toThrow(InvalidArgumentException::class);
});

test('shared disks are refused even though they exist — only dedicated attachments disks are allowed', function (): void {
    // The sweep orphan-deletes anything on a swept disk without a row; pointing
    // storage at a shared disk (e.g. Laravel's built-in local) would let it eat
    // unrelated application files, so it must be rejected outright.
    foreach (['local', 's3'] as $sharedDisk) {
        config(['wayfindr.attachments.storage_disk' => $sharedDisk]);

        expect(fn () => AttachmentStorage::diskName())
            ->toThrow(InvalidArgumentException::class, 'dedicated disk');
    }
});

test('the sweep refuses to orphan-sweep a shared disk even when a row claims it', function (): void {
    $f = storageSurfaceFixture();
    Storage::fake('local');

    // A (historical/manual) row claiming a shared disk must not drag that disk
    // into the orphan sweep.
    ConversationMessageAttachment::factory()
        ->pendingFor($f['conversation'], $f['visitor'])
        ->create(['storage_disk' => 'local']);

    $bystanderKey = 'unrelated/app-file.txt';
    Storage::disk('local')->put($bystanderKey, 'not an attachment');
    touch(Storage::disk('local')->path($bystanderKey), now()->subHours(3)->getTimestamp());

    $this->artisan('wayfindr:sweep-orphaned-attachments')
        ->expectsOutputToContain('refusing to orphan-sweep')
        ->assertSuccessful();

    Storage::disk('local')->assertExists($bystanderKey);
});

// --- Mixed homes serve through the same boundary ---------------------------

test('local and remote attachments serve side by side from their recorded homes', function (): void {
    $f = storageSurfaceFixture();
    $message = ConversationMessage::factory()->for($f['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $f['visitor']->id,
    ]);

    $local = ConversationMessageAttachment::factory()->forMessage($message)->create([
        'storage_disk' => 'attachments',
        'original_filename' => 'local.png',
    ]);
    Storage::disk('attachments')->put($local->storage_key, 'local-bytes');

    $remote = ConversationMessageAttachment::factory()->forMessage($message)->create([
        'storage_disk' => 'attachments-s3',
        'original_filename' => 'remote.png',
    ]);
    Storage::disk('attachments-s3')->put($remote->storage_key, 'remote-bytes');

    $agent = User::factory()->for($f['account'])->create();

    $localResponse = $this->actingAs($agent)->get(route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $local->id,
    ]));
    $localResponse->assertOk();
    expect($localResponse->streamedContent())->toBe('local-bytes');

    $remoteResponse = $this->actingAs($agent)->get(route('dashboard.conversations.attachments.show', [
        'supportCode' => $f['conversation']->support_code,
        'attachment' => $remote->id,
    ]));
    $remoteResponse->assertOk();
    expect($remoteResponse->streamedContent())->toBe('remote-bytes')
        ->and($remoteResponse->headers->get('X-Content-Type-Options'))->toContain('nosniff');
});

test('deleting a remote-homed attachment removes its remote binary', function (): void {
    $f = storageSurfaceFixture();
    $attachment = ConversationMessageAttachment::factory()
        ->pendingFor($f['conversation'], $f['visitor'])
        ->create(['storage_disk' => 'attachments-s3']);
    Storage::disk('attachments-s3')->put($attachment->storage_key, 'bytes');

    $attachment->delete();

    Storage::disk('attachments-s3')->assertMissing($attachment->storage_key);
});

// --- Sweep covers the active remote disk ------------------------------------

test('the sweep reaps orphaned objects on the active remote disk too', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s3']);

    $orphanKey = 'aaa/orphan-remote';
    Storage::disk('attachments-s3')->put($orphanKey, 'orphan');
    touch(Storage::disk('attachments-s3')->path($orphanKey), now()->subHours(3)->getTimestamp());

    $freshKey = 'bbb/fresh-remote';
    Storage::disk('attachments-s3')->put($freshKey, 'fresh');

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    Storage::disk('attachments-s3')->assertMissing($orphanKey);
    Storage::disk('attachments-s3')->assertExists($freshKey);
});

test('a retired remote surface keeps being reconciled while rows still call it home', function (): void {
    // The install used attachments-s3, then switched back to local. Rows still
    // homed on the retired disk must keep its orphaned objects sweepable.
    config(['wayfindr.attachments.storage_disk' => 'attachments']);
    $f = storageSurfaceFixture();

    // A row still living on the retired disk keeps it in the sweep set.
    $resident = ConversationMessageAttachment::factory()
        ->pendingFor($f['conversation'], $f['visitor'])
        ->create(['storage_disk' => 'attachments-s3']);
    Storage::disk('attachments-s3')->put($resident->storage_key, 'still-mine');

    // A cascade-orphaned object on the retired disk, past the grace window.
    $orphanKey = 'ddd/retired-orphan';
    Storage::disk('attachments-s3')->put($orphanKey, 'orphan');
    touch(Storage::disk('attachments-s3')->path($orphanKey), now()->subHours(3)->getTimestamp());

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    Storage::disk('attachments-s3')->assertMissing($orphanKey);
    // The row-owned object survives.
    Storage::disk('attachments-s3')->assertExists($resident->storage_key);
});

test('readiness flags a disk whose credentials cannot delete', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s3']);

    $deleteless = Mockery::mock();
    $deleteless->shouldReceive('put')->andReturn(true);
    $deleteless->shouldReceive('get')->andReturn('ok');
    $deleteless->shouldReceive('files')->andReturnUsing(fn (string $dir): array => [$dir.'/.probe']);
    $deleteless->shouldReceive('delete')->andReturn(false);
    $deleteless->shouldReceive('exists')->andReturn(true);
    Storage::shouldReceive('disk')->with('attachments-s3')->andReturn($deleteless);

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_storage');

    expect($check['status'])->toBe('attention')
        ->and($check['summary'])->toContain('cannot delete');
});

test('readiness flags a disk whose credentials cannot list', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s3']);

    $listless = Mockery::mock();
    $listless->shouldReceive('put')->andReturn(true);
    $listless->shouldReceive('get')->andReturn('ok');
    $listless->shouldReceive('files')->andReturn([]);
    $listless->shouldReceive('delete')->andReturn(true);
    Storage::shouldReceive('disk')->with('attachments-s3')->andReturn($listless);

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_storage');

    expect($check['status'])->toBe('attention')
        ->and($check['summary'])->toContain('cannot list');
});

test('a configured retired disk keeps being swept even after its last row is gone', function (): void {
    // Active surface is local, no rows reference attachments-s3 anymore — but
    // the disk is still materially configured (bucket set), so purely-orphaned
    // objects on it must still be reconciled.
    config([
        'wayfindr.attachments.storage_disk' => 'attachments',
        'filesystems.disks.attachments-s3.bucket' => 'retired-bucket',
    ]);

    $orphanKey = 'eee/rowless-orphan';
    Storage::disk('attachments-s3')->put($orphanKey, 'orphan');
    touch(Storage::disk('attachments-s3')->path($orphanKey), now()->subHours(3)->getTimestamp());

    $this->artisan('wayfindr:sweep-orphaned-attachments')->assertSuccessful();

    Storage::disk('attachments-s3')->assertMissing($orphanKey);
});

test('a misconfigured active disk does not stop the sweep of the local default', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s4']);

    $orphanKey = 'ccc/orphan-local';
    Storage::disk('attachments')->put($orphanKey, 'orphan');
    touch(Storage::disk('attachments')->path($orphanKey), now()->subHours(3)->getTimestamp());

    $this->artisan('wayfindr:sweep-orphaned-attachments')
        ->expectsOutputToContain('misconfigured')
        ->assertSuccessful();

    Storage::disk('attachments')->assertMissing($orphanKey);
});

// --- Readiness --------------------------------------------------------------

test('readiness reports the active disk when its probe passes', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s3']);

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_storage');

    expect($check['status'])->toBe('ready')
        ->and($check['summary'])->toContain('attachments-s3');
});

test('readiness flags a misconfigured storage disk as attention', function (): void {
    config(['wayfindr.attachments.storage_disk' => 'attachments-s4']);

    $check = collect(app(OperatorReadiness::class)->summary()['checks'])->firstWhere('key', 'attachment_storage');

    expect($check['status'])->toBe('attention')
        ->and($check['summary'])->toContain('misconfigured');
});
