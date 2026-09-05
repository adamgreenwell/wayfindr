<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReconcileAgentAlertPublicationsAfterDrain;
use App\Support\AgentAlertPublicationSweep;
use Illuminate\Console\Command;

/** Close the browser-alert write window left by a zero-downtime migration. */
final class ReconcileAgentAlertPublicationsCommand extends Command
{
    public const DRAIN_DELAY_SECONDS = 120;

    protected $signature = 'wayfindr:reconcile-agent-alert-publications
        {--after-worker-drain : Queue one final pass after pre-activation default-queue work has drained}';

    protected $description = 'Backfill browser alert publications written by a previous release';

    public function handle(): int
    {
        if ($this->option('after-worker-drain')) {
            ReconcileAgentAlertPublicationsAfterDrain::dispatch()
                ->delay(now()->addSeconds(self::DRAIN_DELAY_SECONDS));

            $this->info(sprintf(
                'Queued a final agent alert publication pass for %d seconds after activation.',
                self::DRAIN_DELAY_SECONDS,
            ));

            return self::SUCCESS;
        }

        $reconciled = AgentAlertPublicationSweep::run();

        $this->info($reconciled === 0
            ? 'No agent alert publication needed reconciliation.'
            : sprintf('Reconciled %d agent alert publication(s).', $reconciled));

        return self::SUCCESS;
    }
}
