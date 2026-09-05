<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgentAlertPublicationSweep;
use Illuminate\Console\Command;

/** Close the browser-alert write window left by a zero-downtime migration. */
final class ReconcileAgentAlertPublicationsCommand extends Command
{
    protected $signature = 'wayfindr:reconcile-agent-alert-publications';

    protected $description = 'Backfill browser alert publications written by a previous release';

    public function handle(): int
    {
        $reconciled = AgentAlertPublicationSweep::run();

        $this->info($reconciled === 0
            ? 'No agent alert publication needed reconciliation.'
            : sprintf('Reconciled %d agent alert publication(s).', $reconciled));

        return self::SUCCESS;
    }
}
