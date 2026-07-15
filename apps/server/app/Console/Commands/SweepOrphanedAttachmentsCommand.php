<?php

namespace App\Console\Commands;

use App\Models\ConversationMessageAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SweepOrphanedAttachmentsCommand extends Command
{
    protected $signature = 'wayfindr:sweep-orphaned-attachments {--dry-run : Report what would be removed without changing anything}';

    protected $description = 'Remove abandoned/failed unbound attachment uploads and orphaned storage objects, per ADR 0007.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $removedRows = $this->sweepAbandonedUploads($dryRun);
        $removedFiles = $this->sweepOrphanedFiles($dryRun);

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
     * Phase B: files on the attachments disk with no owning row — the residue of
     * a database FK cascade (which deletes rows without loading models, so the
     * model hook never runs). Only files older than the grace window are removed,
     * so an in-flight upload (binary written, row not yet committed) is spared.
     */
    private function sweepOrphanedFiles(bool $dryRun): int
    {
        $graceHours = max(0, (int) config('wayfindr.attachments.orphan_grace_hours', 1));
        $graceCutoff = now()->subHours($graceHours)->getTimestamp();
        $disk = Storage::disk('attachments');

        // Known keys are looked up after Phase A so rows/files it removed are
        // already gone. Flip to a hash set for O(1) membership tests.
        $knownKeys = ConversationMessageAttachment::query()
            ->where('storage_disk', 'attachments')
            ->pluck('storage_key')
            ->flip();

        $removed = 0;

        foreach ($disk->allFiles() as $path) {
            if (str_starts_with(basename($path), '.')) {
                // Never touch dotfiles (e.g. a .gitkeep an operator added).
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
