<?php

namespace App\Console\Commands;

use App\Support\CobrowsePayloadBudget;
use App\Support\CobrowseTransportReadiness;
use Illuminate\Console\Command;

class CobrowseTransportSmokeCommand extends Command
{
    protected $signature = 'wayfindr:cobrowse-transport-smoke
        {--json : Print machine-readable aggregate readiness output.}';

    protected $description = 'Print aggregate cobrowse transport readiness without exposing visitor support data.';

    public function handle(CobrowseTransportReadiness $readiness): int
    {
        $check = $readiness->check();

        if ($this->option('json')) {
            $this->line(json_encode($this->jsonPayload($check), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $this->exitCodeForCheck($check);
        }

        $this->info('Wayfindr cobrowse transport smoke');
        $this->line('Status: '.$check['status_label']);
        $this->line('Summary: '.$check['summary']);
        $this->line('Detail: '.$check['detail']);
        $this->line('Next step: '.$check['action']);
        $this->newLine();
        $this->printBudgetDefaults();

        return $this->exitCodeForCheck($check);
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

    /**
     * @param  array{action: string, detail: string, key: string, label: string, status: string, status_label: string, summary: string}  $check
     * @return array{action: string, budget_defaults: array<int, array{description: string, items: array<int, array{label: string, value: string}>, label: string}>, detail: string, key: string, label: string, status: string, status_label: string, summary: string}
     */
    private function jsonPayload(array $check): array
    {
        return [
            ...$check,
            'budget_defaults' => CobrowsePayloadBudget::readinessDefaults(),
        ];
    }

    /**
     * @param  array{status: string}  $check
     */
    private function exitCodeForCheck(array $check): int
    {
        return $check['status'] === 'attention'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
