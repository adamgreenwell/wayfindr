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

        self::assertSafeDisk($disk);

        return $disk;
    }

    /**
     * The single place a disk is judged safe to hold attachments — used by
     * upload routing AND by the retention sweep before it will orphan-sweep a
     * disk, so the two can never drift apart.
     */
    public static function assertSafeDisk(string $disk): void
    {
        // Attachments must live on a DEDICATED disk — never a shared one
        // (local, public, s3, ...). The public disk is web-served, and the
        // retention sweep treats every object on a swept disk without an
        // attachment row as an orphan and deletes it: on a shared disk that
        // would eat unrelated application files. Dedicated disk names start
        // with "attachments" (operators may define their own attachments-*).
        if (! str_starts_with($disk, 'attachments')) {
            throw new InvalidArgumentException(sprintf(
                'Attachment storage requires a dedicated disk whose name starts with "attachments" — got [%s]. '
                .'A shared disk would let the retention sweep delete unrelated files. '
                .'Use attachments (local), attachments-s3, or define your own attachments-* disk.',
                $disk,
            ));
        }

        $diskConfig = config("filesystems.disks.{$disk}");

        if ($diskConfig === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown attachment storage disk [%s]. Configure it in filesystems.disks or use attachments / attachments-s3.',
                $disk,
            ));
        }

        // The name convention is not enough: a custom attachments-* disk could
        // still be configured for web exposure (or point somewhere shared).
        // ADR 0007's guarantee is "no public path, ever", so any exposure
        // marker on the disk config is rejected — attachments are only reached
        // by streaming through the app's authorized endpoints.
        $diskConfig = is_array($diskConfig) ? $diskConfig : [];

        // Object ACLs are judged by ALLOWLIST: only ACLs that keep objects
        // readable solely by the bucket owner are safe. Everything else —
        // public-read, and also authenticated-read (readable by ANY
        // authenticated AWS account, not just yours) — is exposure.
        $acl = strtolower(trim((string) ($diskConfig['options']['ACL'] ?? '')));
        $privateAcls = ['', 'private', 'bucket-owner-full-control', 'bucket-owner-read'];

        $exposure = match (true) {
            filled($diskConfig['url'] ?? null) => 'defines a public URL',
            ($diskConfig['serve'] ?? false) === true => 'has HTTP serving enabled',
            ($diskConfig['visibility'] ?? null) === 'public' => 'has public visibility',
            ! in_array($acl, $privateAcls, true) => sprintf('sets object ACL [%s], which can expose objects beyond the bucket owner', $acl),
            default => null,
        };

        if ($exposure !== null) {
            throw new InvalidArgumentException(sprintf(
                'Attachment storage disk [%s] %s — attachments must stay private (no url, no serve, no public visibility) and are only served through authorized Wayfindr endpoints.',
                $disk,
                $exposure,
            ));
        }
    }
}
