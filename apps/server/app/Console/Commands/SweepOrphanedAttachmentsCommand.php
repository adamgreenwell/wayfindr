<?php

namespace App\Console\Commands;

use App\Models\ConversationMessageAttachment;
use App\Support\Attachments\AttachmentStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class SweepOrphanedAttachmentsCommand extends Command
{
    protected $signature = 'wayfindr:sweep-orphaned-attachments {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Remove abandoned/failed unbound attachment uploads and orphaned storage objects, per ADR 0007.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Gather the disks to reconcile BEFORE Phase A deletes rows: a retired
        // surface (e.g. attachments-s3 after switching back to local) must keep
        // being reconciled for as long as rows still call it home.
        $diskNames = $this->sweepableDiskNames();

        $removedRows = $this->sweepAbandonedUploads($dryRun);
        $removedFiles = $this->sweepOrphanedFiles($diskNames, $dryRun);

        $this->info(sprintf(
            '%s %d abandoned/failed upload%s and %d orphaned storage object%s.',
            $dryRun ? 'Would remove' : 'Removed',
            $removedRows,
            $removedRows === 1 ? '' : 's',
            $removedFiles,
            $removedFiles === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }

    /**
     * Phase A: attachment rows that never became part of a message — abandoned
     * pending uploads past the expiry window, or failed uploads — are deleted
     * (the model's deleting hook removes each binary with its row).
     */
    private function sweepAbandonedUploads(bool $dryRun): int
    {
        $expiryHours = max(1, (int) config('wayfindr.attachments.pending_expiry_hours', 24));
        $cutoff = now()->subHours($expiryHours);
        $removed = 0;

        ConversationMessageAttachment::query()
            ->whereNull('conversation_message_id')
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('status', ConversationMessageAttachment::STATUS_FAILED)
                    ->orWhere('created_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(100, function ($attachments) use ($dryRun, &$removed): void {
                foreach ($attachments as $attachment) {
                    if ($dryRun) {
                        $removed++;

                        continue;
                    }

                    try {
                        // delete() fires the deleting hook, which removes the binary.
                        $attachment->delete();
                        $removed++;
                    } catch (\Throwable $exception) {
                        // A row homed on a retired or misconfigured disk must not
                        // block the rest of the cleanup. The row is kept (its
                        // binary may still exist somewhere unreachable) and
                        // surfaced for the operator.
                        $this->warn(sprintf(
                            'Could not remove abandoned upload #%d (disk [%s]): %s',
                            $attachment->id,
                            $attachment->storage_disk,
                            $exception->getMessage(),
                        ));
                    }
                }
            });

        return $removed;
    }

    /**
     * The disks whose stored objects Phase B reconciles: every configured
     * dedicated attachments disk (so a retired surface keeps being reconciled
     * even after its last row is gone), the active disk, and any disk that
     * still homes attachment rows. Disks with no filesystems config are skipped
     * with a warning — downloads from them would be broken too, so the operator
     * needs to hear about it.
     *
     * @return list<string>
     */
    private function sweepableDiskNames(): array
    {
        // Every dedicated attachments disk that is materially configured — an
        // s3-driver disk with no bucket has never stored anything and is
        // unreachable anyway, so it is skipped without noise on default installs.
        $diskNames = collect(config('filesystems.disks', []))
            ->filter(fn ($disk, string $name): bool => str_starts_with($name, 'attachments'))
            ->filter(function ($disk): bool {
                $driver = is_array($disk) ? ($disk['driver'] ?? 'local') : 'local';

                return $driver !== 's3' || filled(is_array($disk) ? ($disk['bucket'] ?? null) : null);
            })
            ->keys()
            ->all();

        try {
            $diskNames[] = AttachmentStorage::diskName();
        } catch (InvalidArgumentException $exception) {
            // A misconfigured active disk must not stop the sweep from
            // reconciling the rest; surface it and carry on.
            $this->error('Attachment storage is misconfigured: '.$exception->getMessage());
        }

        $rowHomedDisks = ConversationMessageAttachment::query()
            ->distinct()
            ->pluck('storage_disk')
            ->all();

        $sweepable = [];

        foreach (array_unique(array_merge($diskNames, $rowHomedDisks)) as $diskName) {
            // The same safety judgment as upload routing (dedicated name,
            // configured, no public-exposure markers), so a disk the resolver
            // would reject is never orphan-swept either — Phase B deletes every
            // object without an attachment row, which on a shared or exposed
            // disk could eat unrelated files.
            try {
                AttachmentStorage::assertSafeDisk($diskName);
            } catch (InvalidArgumentException $exception) {
                $this->warn(sprintf('Skipping disk [%s]: %s', $diskName, $exception->getMessage()));

                continue;
            }

            $sweepable[] = $diskName;
        }

        return $sweepable;
    }

    /**
     * Phase B: stored objects with no owning row — the residue of a database FK
     * cascade (which deletes rows without loading models, so the model hook
     * never runs). Only objects older than the grace window are removed, so an
     * in-flight upload (binary written, row not yet committed) is spared.
     *
     * @param  list<string>  $diskNames
     */
    private function sweepOrphanedFiles(array $diskNames, bool $dryRun): int
    {
        $removed = 0;

        foreach ($diskNames as $diskName) {
            try {
                $removed += $this->sweepOrphanedFilesOn($diskName, $dryRun);
            } catch (\Throwable $exception) {
                // An unreachable disk (e.g. a configured bucket with broken
                // credentials) must not stop the other disks' reconciliation.
                $this->warn(sprintf('Disk [%s] could not be swept: %s', $diskName, $exception->getMessage()));
            }
        }

        return $removed;
    }

    private function sweepOrphanedFilesOn(string $diskName, bool $dryRun): int
    {
        $graceHours = max(0, (int) config('wayfindr.attachments.orphan_grace_hours', 1));
        $graceCutoff = now()->subHours($graceHours)->getTimestamp();
        $disk = Storage::disk($diskName);

        // Known keys are looked up after Phase A so rows/files it removed are
        // already gone. Flip to a hash set for O(1) membership tests.
        $knownKeys = ConversationMessageAttachment::query()
            ->where('storage_disk', $diskName)
            ->pluck('storage_key')
            ->flip();

        $removed = 0;
        $undeletable = 0;

        foreach ($disk->allFiles() as $path) {
            if (str_starts_with(basename($path), '.')) {
                // Never touch dotfiles (a .gitkeep, or the readiness probe).
                continue;
            }

            if ($knownKeys->has($path)) {
                continue;
            }

            if ($disk->lastModified($path) > $graceCutoff) {
                continue;
            }

            if (! $dryRun) {
                // The disks run with throw => false, so a delete refused by the
                // credentials returns false silently — count it honestly instead
                // of reporting an object as removed while it still exists.
                if ($disk->delete($path) === false) {
                    $undeletable++;

                    continue;
                }
            }

            $removed++;
        }

        if ($undeletable > 0) {
            $this->warn(sprintf(
                '%d orphaned object%s on disk [%s] could not be deleted — check the credentials\' delete permission.',
                $undeletable,
                $undeletable === 1 ? '' : 's',
                $diskName,
            ));
        }

        return $removed;
    }
}
