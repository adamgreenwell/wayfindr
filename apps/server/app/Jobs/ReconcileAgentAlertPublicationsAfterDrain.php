<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\AgentAlertPublicationSweep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Finish rolling-release alert reconciliation after old queue work drains. */
final class ReconcileAgentAlertPublicationsAfterDrain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BATCH_SIZE = 500;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(private readonly ?string $afterId = null) {}

    public function afterId(): ?string
    {
        return $this->afterId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(): void
    {
        $batch = AgentAlertPublicationSweep::runBatch(
            $this->afterId,
            self::BATCH_SIZE,
            // Keep Reverb I/O out of this bounded scan. Each durable row gets a
            // retryable publication job; the existing claim lock deduplicates
            // it against any current-release listener racing the sweep.
            function (string $notificationId): void {
                BroadcastReconciledAgentAlert::dispatch($notificationId);
            },
        );

        if ($batch['reconciled'] > 0) {
            Log::info('Reconciled agent alert publications after old workers drained.', [
                'reconciled' => $batch['reconciled'],
            ]);
        }

        if ($batch['has_more'] && $batch['last_id'] !== null) {
            self::dispatch($batch['last_id']);
        }
    }
}
