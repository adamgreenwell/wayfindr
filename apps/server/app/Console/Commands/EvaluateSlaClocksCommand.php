<?php

namespace App\Console\Commands;

use App\Models\SlaClock;
use App\Notifications\SlaDeadlineAlert;
use App\Support\Sla\SlaAlertRouting;
use App\Support\Sla\SlaClockManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateSlaClocksCommand extends Command
{
    protected $signature = 'wayfindr:evaluate-sla-clocks';

    protected $description = 'Advance active SLA clocks and notify eligible agents when a deadline approaches or breaches.';

    public function handle(SlaClockManager $manager, SlaAlertRouting $routing): int
    {
        $evaluated = 0;
        $alerts = 0;
        $failed = 0;
        $at = now();

        SlaClock::query()
            ->select('id')
            ->whereNull('satisfied_at')
            ->whereNull('cancelled_at')
            ->lazyById(250)
            ->each(function (SlaClock $candidate) use ($at, &$alerts, &$evaluated, &$failed, $manager, $routing): void {
                $clockId = (int) $candidate->id;
                $evaluated++;

                try {
                    ['clock' => $clock, 'stage' => $stage] = $manager->evaluate($clockId, $at);
                } catch (ModelNotFoundException) {
                    // A site purge can delete a clock between the bounded id
                    // scan and its locked evaluation. Nothing remains to do.
                    return;
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('SLA clock evaluation failed.', [
                        'sla_clock_id' => $clockId,
                        'exception' => $exception,
                    ]);

                    return;
                }

                if ($stage === null || ! $clock->subject) {
                    return;
                }

                $stageFailed = false;
                $deliveredUserIds = $clock->alertedUserIds($stage);

                foreach ($routing->recipients($clock) as $agent) {
                    if (in_array((int) $agent->id, $deliveredUserIds, true)) {
                        continue;
                    }

                    try {
                        $agent->notify(new SlaDeadlineAlert($clock, $stage));
                        $manager->recordAlertHandoff((int) $clock->id, $stage, (int) $agent->id);
                        $alerts++;
                    } catch (Throwable $exception) {
                        $stageFailed = true;
                        $failed++;
                        Log::warning('SLA deadline alert delivery failed.', [
                            'sla_clock_id' => $clock->id,
                            'agent_id' => $agent->id,
                            'stage' => $stage,
                            'exception' => $exception,
                        ]);
                    }
                }

                if ($stageFailed) {
                    return;
                }

                try {
                    // A stage is complete only after every currently eligible
                    // recipient has accepted the handoff. With no recipients,
                    // completing here also prevents a stale alert appearing
                    // later merely because routing preferences changed.
                    $manager->completeAlertHandoff((int) $clock->id, $stage, $at);
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('SLA alert handoff completion failed.', [
                        'sla_clock_id' => $clock->id,
                        'stage' => $stage,
                        'exception' => $exception,
                    ]);
                }
            });

        $this->line("SLA evaluation complete. Clocks evaluated: {$evaluated}. Alerts queued: {$alerts}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
