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

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60];
    }

    public function handle(): void
    {
        $reconciled = AgentAlertPublicationSweep::run();

        if ($reconciled > 0) {
            Log::info('Reconciled agent alert publications after old workers drained.', [
                'reconciled' => $reconciled,
            ]);
        }
    }
}
