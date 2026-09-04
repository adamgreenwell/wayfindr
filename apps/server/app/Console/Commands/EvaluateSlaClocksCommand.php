<?php

namespace App\Console\Commands;

use App\Jobs\SendSlaDeadlineAlertDelivery;
use App\Models\SlaClock;
use App\Models\User;
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

                foreach ($routing->recipients($clock) as $agent) {
                    foreach ($this->deliveryChannels($agent) as $channel) {
                        if ($clock->alertWasHandedOff($stage, $channel, (int) $agent->id)) {
                            continue;
                        }

                        $notification = new SlaDeadlineAlert($clock, $stage, $channel);

                        try {
                            // Notification::shouldSend() runs inside a queued
                            // channel, where its veto has no return value for
                            // this scheduler. Check immediately on both sides
                            // of the handoff so only a still-current channel is
                            // checkpointed as accepted.
                            if (! $notification->shouldSend($agent, $channel)) {
                                continue;
                            }

                            $delivery = $manager->alertDelivery(
                                (int) $clock->id,
                                $stage,
                                $channel,
                                (int) $agent->id,
                            );
                            SendSlaDeadlineAlertDelivery::dispatchPending((int) $delivery->id, $channel);

                            if (! $notification->shouldSend($agent, $channel)) {
                                continue;
                            }

                            $manager->recordAlertHandoff((int) $clock->id, $stage, $channel, (int) $agent->id);
                            $alerts++;
                        } catch (Throwable $exception) {
                            $stageFailed = true;
                            $failed++;
                            Log::warning('SLA deadline alert delivery failed.', [
                                'sla_clock_id' => $clock->id,
                                'agent_id' => $agent->id,
                                'channel' => $channel,
                                'stage' => $stage,
                                'exception' => $exception,
                            ]);
                        }
                    }
                }

                if ($stageFailed) {
                    return;
                }

                try {
                    if (! $manager->completeAlertHandoffIfCurrent((int) $clock->id, $stage, $at, $routing)) {
                        return;
                    }
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

    /** @return list<'database'|'mail'> */
    private function deliveryChannels(User $agent): array
    {
        return $agent->wantsImmediateAlertEmail()
            ? ['database', 'mail']
            : ['database'];
    }
}
