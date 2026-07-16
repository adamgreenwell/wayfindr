<?php

namespace App\Support\Attachments;

use InvalidArgumentException;

/**
 * Resolves which filesystem disk NEW attachment uploads land on (ADR 0007).
 *
 * Every attachment row records its own `storage_disk`, so this only steers new
 * uploads — existing files keep serving from their recorded home, which is what
 * makes a local -> remote switch (or back) safe without a migration.
 *
 * Validation is fail-loud, mirroring the scanner driver: a typo'd disk or an
 * unsafe choice must reject uploads and surface on readiness, never silently
 * land visitor files somewhere unintended.
 */
class AttachmentStorage
{
    public static function diskName(): string
    {
        $disk = trim((string) config('wayfindr.attachments.storage_disk', 'attachments'));

        if ($disk === '') {
            return 'attachments';
        }

        // The public disk is web-served by design — visitor attachments must
        // never land there, whatever the configuration says.
        if ($disk === 'public') {
            throw new InvalidArgumentException(
                'Attachments may not use the public disk. Use attachments (local) or attachments-s3.'
            );
        }

        if (config("filesystems.disks.{$disk}") === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown attachment storage disk [%s]. Configure it in filesystems.disks or use attachments / attachments-s3.',
                $disk,
            ));
        }

        return $disk;
    }

    /**
     * The disks the retention sweep reconciles storage against: the local
     * default plus the active disk when it differs. (Rows are swept by their
     * own recorded disk regardless; this governs the orphaned-object pass.)
     *
     * @return list<string>
     */
    public static function sweepableDiskNames(): array
    {
        return array_values(array_unique(['attachments', self::diskName()]));
    }
}
