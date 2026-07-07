<?php

namespace App\Console\Commands;

use App\Models\CobrowseSession;
use Illuminate\Console\Command;

class PruneCobrowseContentCommand extends Command
{
    protected $signature = 'wayfindr:prune-cobrowse-content {--dry-run : Report what would be pruned without changing anything}';

    protected $description = 'Strip raw cobrowse page content (snapshot HTML, page text, mutation batches) from ended sessions past the retention window, keeping content-free provenance.';

    public function handle(): int
    {
        $hours = max(1, (int) config('wayfindr.cobrowse.content_retention_hours', 72));
        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');
        $pruned = 0;

        CobrowseSession::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use ($dryRun, &$pruned): void {
                foreach ($sessions as $session) {
                    if (! $this->hasPrunableContent($session->metadata ?? [])) {
                        continue;
                    }

                    $pruned++;

                    if ($dryRun) {
                        continue;
                    }

                    $session->updateMetadataAtomically(
                        fn (array $metadata): array => $this->pruneMetadata($metadata)
                    );
                }
            });

        $this->info(sprintf(
            '%s %d cobrowse session%s past the %d-hour content retention window.',
            $dryRun ? 'Would prune' : 'Pruned',
            $pruned,
            $pruned === 1 ? '' : 's',
            $hours,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function hasPrunableContent(array $metadata): bool
    {
        $snapshot = $metadata['snapshot'] ?? null;

        if (is_array($snapshot) && (filled($snapshot['html'] ?? null) || filled($snapshot['text'] ?? null))) {
            return true;
        }

        $batches = $metadata['mutations']['recent_batches'] ?? null;

        return is_array($batches) && $batches !== [];
    }

    /**
     * Remove page content while keeping content-free provenance: counts,
     * lengths, timestamps, page URLs, and the snapshot hash stay so the audit
     * trail and diagnostics remain meaningful after the content is gone.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function pruneMetadata(array $metadata): array
    {
        if (is_array($metadata['snapshot'] ?? null)) {
            unset($metadata['snapshot']['html'], $metadata['snapshot']['text']);
        }

        if (is_array($metadata['mutations'] ?? null)) {
            unset($metadata['mutations']['recent_batches']);
        }

        $metadata['content_pruned_at'] = now()->toJSON();

        return $metadata;
    }
}
