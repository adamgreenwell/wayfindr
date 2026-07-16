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
                    if (! $dryRun) {
                        // delete() fires the deleting hook, which removes the binary.
                        $attachment->delete();
                    }

                    $removed++;
                }
            });

        return $removed;
    }

    /**
     * The disks whose stored objects Phase B reconciles: the local default, the
     * active disk, and any disk that still homes attachment rows (a retired
     * remote surface keeps being reconciled until its files are gone). Disks
     * with no filesystems config are skipped with a warning — downloads from
     * them would be broken too, so the operator needs to hear about it.
     *
     * @return list<string>
     */
    private function sweepableDiskNames(): array
    {
        $diskNames = ['attachments'];

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
            if (config("filesystems.disks.{$diskName}") === null) {
                $this->warn(sprintf(
                    'Skipping disk [%s]: rows reference it but it has no filesystems configuration.',
                    $diskName,
                ));

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
            $removed += $this->sweepOrphanedFilesOn($diskName, $dryRun);
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
                $disk->delete($path);
            }

            $removed++;
        }

        return $removed;
    }
}
