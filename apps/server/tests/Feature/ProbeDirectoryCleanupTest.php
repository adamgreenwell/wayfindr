<?php

// Every storage probe in the product writes `.probe` INSIDE a uniquely named
// directory, so the listing check is one scoped call rather than a full-bucket
// list. Deleting the key therefore does not remove the directory, and on a
// local disk each probe left one behind permanently -- 265,493 of them on one
// development machine before anyone noticed (#1010).
//
// There are three such probes and each returns from a different branch, so the
// branch is the thing worth pinning: a cleanup that only runs on the happy path
// is exactly the bug this file exists to stop coming back.

use App\Support\OperatorReadiness;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Probe directories still on the disk, found by their dotfile prefix.
 *
 * Filtered rather than asserting the disk is bare, because the backup probe
 * runs under the install's own prefix, which legitimately exists.
 *
 * @return array<int, string>
 */
function probeCleanupLeftovers(Filesystem $disk, string $prefix): array
{
    return collect($disk->allDirectories())
        ->filter(fn (string $dir): bool => str_contains($dir, $prefix))
        ->values()
        ->all();
}

/**
 * A real faked disk with one method forced to misbehave.
 *
 * Everything else still hits the real filesystem, so the assertion about what
 * survives on disk is about the disk and not about the mock.
 *
 * @return array{0: Filesystem, 1: mixed}
 */
function probeCleanupFlakyDisk(string $name, string $method, mixed $behaviour): array
{
    $real = Storage::fake($name);
    $proxy = Mockery::mock($real);

    $behaviour instanceof Throwable
        ? $proxy->shouldReceive($method)->andThrow($behaviour)
        : $proxy->shouldReceive($method)->andReturn($behaviour);

    Storage::set($name, $proxy);

    return [$real, $proxy];
}

/** @return array<string, mixed> */
function probeCleanupReadinessCheck(): array
{
    return collect(app(OperatorReadiness::class)->summary()['checks'])
        ->firstWhere('key', 'attachment_storage');
}

// ---------------------------------------------------------------- readiness

test('the readiness probe cleans up when the disk cannot list', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'files', []);

    expect(probeCleanupReadinessCheck()['summary'])->toContain('cannot list');
    expect(probeCleanupLeftovers($real, '.wayfindr-readiness-probe-'))
        ->toBe([], 'The readiness probe returned early on the cannot-list branch and left its probe directory on the attachments disk.');
});

test('the readiness probe cleans up when the disk cannot delete', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'delete', false);

    expect(probeCleanupReadinessCheck()['summary'])->toContain('cannot delete');
    expect(probeCleanupLeftovers($real, '.wayfindr-readiness-probe-'))
        ->toBe([], 'The readiness probe returned early on the cannot-delete branch and left its probe directory on the attachments disk.')
        ->and($real->allFiles())
        ->toBe([], 'The readiness probe left .probe behind on the branch where deleting the key failed.');
});

test('the readiness probe cleans up when the write/read round-trip fails', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'get', 'corrupted');

    expect(probeCleanupReadinessCheck()['detail'])->toContain('write/read round-trip failed');
    expect(probeCleanupLeftovers($real, '.wayfindr-readiness-probe-'))
        ->toBe([], 'The readiness probe threw on the failed write/read round-trip and left its probe directory on the attachments disk.');
});

test('the readiness probe cleans up when the disk throws', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'files', new RuntimeException('listing blew up'));

    expect(probeCleanupReadinessCheck()['detail'])->toBe('listing blew up');
    expect(probeCleanupLeftovers($real, '.wayfindr-readiness-probe-'))
        ->toBe([], 'The readiness probe let an exception escape the try and left its probe directory on the attachments disk.')
        ->and($real->allFiles())
        ->toBe([], 'The readiness probe left .probe behind when listing threw before the delete ran.');
});

test('a failed cleanup does not replace the finding the probe exists to report', function (): void {
    $real = Storage::fake('attachments');
    $proxy = Mockery::mock($real);
    $proxy->shouldReceive('files')->andReturn([]);
    $proxy->shouldReceive('deleteDirectory')->andThrow(new RuntimeException('cleanup exploded'));
    Storage::set('attachments', $proxy);

    // The cleanup runs in a finally, so anything it throws REPLACES the verdict
    // the probe already computed. Caught here so that removing the inner
    // catch fails on the assertion below rather than erroring out of the test.
    try {
        $summary = (string) probeCleanupReadinessCheck()['summary'];
    } catch (Throwable $exception) {
        $summary = 'the cleanup threw out of the probe: '.$exception->getMessage();
    }

    expect(str_contains($summary, 'cannot list'))
        ->toBeTrue('A failing cleanup escaped the finally and replaced the cannot-list finding the probe exists to report. Got: '.$summary);
});

// --------------------------------------------------------- storage settings

test('the storage connection test cleans up when the disk cannot list', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'files', []);

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.test'))
        ->assertSessionHas('error');

    expect(probeCleanupLeftovers($real, '.wayfindr-storage-test-'))
        ->toBe([], 'The storage connection test returned early on the list-failed branch and left its probe directory behind.');
});

test('the storage connection test cleans up when the disk cannot delete', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'delete', false);

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.test'))
        ->assertSessionHas('error');

    expect(probeCleanupLeftovers($real, '.wayfindr-storage-test-'))
        ->toBe([], 'The storage connection test returned early on the delete-failed branch and left its probe directory behind.')
        ->and($real->allFiles())
        ->toBe([], 'The storage connection test left .probe behind when deleting the key failed.');
});

test('the storage connection test cleans up when the disk throws', function (): void {
    [$real] = probeCleanupFlakyDisk('attachments', 'files', new RuntimeException('listing blew up'));

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.test'))
        ->assertSessionHas('error');

    expect(probeCleanupLeftovers($real, '.wayfindr-storage-test-'))
        ->toBe([], 'The storage connection test let an exception escape and left its probe directory behind.')
        ->and($real->allFiles())
        ->toBe([], 'The storage connection test left .probe behind when listing threw.');
});

// ---------------------------------------------------------- backup settings

test('the offsite connection test cleans up when the disk cannot list', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    [$real] = probeCleanupFlakyDisk('backups', 'files', []);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertSessionHas('error');

    expect(probeCleanupLeftovers($real, '.wayfindr-backup-test-'))
        ->toBe([], 'The offsite connection test returned early on the list-failed branch and left its probe directory under the backup prefix.');
});

test('the offsite connection test cleans up when the disk throws', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    [$real] = probeCleanupFlakyDisk('backups', 'files', new RuntimeException('listing blew up'));

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertSessionHas('error');

    expect(probeCleanupLeftovers($real, '.wayfindr-backup-test-'))
        ->toBe([], 'The offsite connection test let an exception escape and left its probe directory under the backup prefix.')
        ->and($real->allFiles())
        ->toBe([], 'The offsite connection test left .probe behind when listing threw.');
});

// The cleanup deletes a DIRECTORY, and on an object store that is a prefix
// delete -- so the prefix it is handed matters. This is the test that would
// catch a cleanup widened to the operator's own prefix.
test('the offsite cleanup removes only its own directory, never the operator prefix', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    config()->set('wayfindr.backup.prefix', 'acme-install');

    $real = Storage::fake('backups');
    $real->put('acme-install/wayfindr-backup-2026-09-19.tar.gz', 'archive-bytes');

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertSessionHas('status');

    expect($real->exists('acme-install/wayfindr-backup-2026-09-19.tar.gz'))
        ->toBeTrue('The offsite probe cleanup deleted a real backup archive: it removed the operator prefix, not just its own probe directory.')
        ->and(probeCleanupLeftovers($real, '.wayfindr-backup-test-'))
        ->toBe([], 'The offsite connection test left its probe directory under the backup prefix.');
});

// The object store case Codex caught on #1011. `deleteDirectory` is
// list-then-delete on S3, so credentials that can write and delete but NOT list
// -- which is precisely the misconfiguration the probes report as "cannot list"
// -- cannot reclaim the probe object through it. Deleting the known key by name
// needs only DeleteObject, so the cleanup must do that too, not instead.
// The readiness probe deletes its key in the main flow, but only AFTER the
// listing check -- so when listing throws, that line is never reached and the
// finally is the only thing left to reclaim the object.
test('the readiness probe reclaims its object when listing throws and the directory cannot be listed either', function (): void {
    $real = Storage::fake('attachments');
    $proxy = Mockery::mock($real);
    $proxy->shouldReceive('files')->andThrow(new RuntimeException('listing denied'));
    $proxy->shouldReceive('deleteDirectory')->andReturn(false);
    Storage::set('attachments', $proxy);

    expect(probeCleanupReadinessCheck()['detail'])->toBe('listing denied');
    expect($real->allFiles())
        ->toBe([], 'Listing threw before the probe deleted its key, and deleteDirectory cannot reclaim it without listing. The cleanup must delete the known key by name, which needs no listing.');
});

test('the storage connection test reclaims its object when the disk cannot list at all', function (): void {
    $real = Storage::fake('attachments');
    $proxy = Mockery::mock($real);
    $proxy->shouldReceive('files')->andReturn([]);
    $proxy->shouldReceive('deleteDirectory')->andReturn(false);
    Storage::set('attachments', $proxy);

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.test'))
        ->assertSessionHas('error');

    expect($real->allFiles())
        ->toBe([], 'Listing is denied, so deleteDirectory cannot reclaim the probe object. The cleanup must delete the known key by name as well.');
});

test('the offsite connection test reclaims its object when the disk cannot list at all', function (): void {
    config()->set('wayfindr.backup.disk', 'backups');
    $real = Storage::fake('backups');
    $proxy = Mockery::mock($real);
    $proxy->shouldReceive('files')->andReturn([]);
    $proxy->shouldReceive('deleteDirectory')->andReturn(false);
    Storage::set('backups', $proxy);

    $this->actingAs(backupOperator())
        ->post(route('operator.settings.backups.test'))
        ->assertSessionHas('error');

    expect($real->allFiles())
        ->toBe([], 'Listing is denied, so deleteDirectory cannot reclaim the probe object. The cleanup must delete the known key by name as well.');
});
