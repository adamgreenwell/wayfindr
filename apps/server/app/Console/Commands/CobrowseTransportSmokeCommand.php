<?php

namespace App\Console\Commands;

use App\Support\CobrowsePayloadBudget;
use App\Support\CobrowseTransportReadiness;
use Illuminate\Console\Command;

class CobrowseTransportSmokeCommand extends Command
{
    protected $signature = 'wayfindr:cobrowse-transport-smoke';

    protected $description = 'Print aggregate cobrowse transport readiness without exposing visitor support data.';

    public function handle(CobrowseTransportReadiness $readiness): int
    {
        $check = $readiness->check();

        $this->info('Wayfindr cobrowse transport smoke');
        $this->line('Status: '.$check['status_label']);
        $this->line('Summary: '.$check['summary']);
        $this->line('Detail: '.$check['detail']);
        $this->line('Next step: '.$check['action']);
        $this->newLine();
        $this->printBudgetDefaults();

        return $check['status'] === 'attention'
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function printBudgetDefaults(): void
    {
        $this->line('Cobrowse budget defaults');

        foreach (CobrowsePayloadBudget::readinessDefaults() as $group) {
            $this->line($group['label']);

            foreach ($group['items'] as $item) {
                $this->line(sprintf('%s: %s', $item['label'], $item['value']));
            }
        }
    }
}
