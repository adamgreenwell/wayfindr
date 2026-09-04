<?php

namespace App\Console\Commands;

use App\Enums\AccountPermission;
use App\Models\Conversation;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaDeadlineAlert;
use App\Support\Sla\SlaClockManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateSlaClocksCommand extends Command
{
    protected $signature = 'wayfindr:evaluate-sla-clocks';

    protected $description = 'Advance active SLA clocks and notify eligible agents when a deadline approaches or breaches.';

    public function handle(SlaClockManager $manager): int
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
            ->each(function (SlaClock $candidate) use ($at, &$alerts, &$evaluated, &$failed, $manager): void {
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

                foreach ($this->recipients($clock) as $agent) {
                    try {
                        $agent->notify(new SlaDeadlineAlert($clock, $stage));
                        $alerts++;
                    } catch (Throwable $exception) {
                        $failed++;
                        Log::warning('SLA deadline alert delivery failed.', [
                            'sla_clock_id' => $clock->id,
                            'agent_id' => $agent->id,
                            'stage' => $stage,
                            'exception' => $exception,
                        ]);
                    }
                }
            });

        $this->line("SLA evaluation complete. Clocks evaluated: {$evaluated}. Alerts queued: {$alerts}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, User> */
    private function recipients(SlaClock $clock): Collection
    {
        $subject = $clock->subject;
        $site = $clock->site;
        $assignedId = $subject instanceof Ticket ? $subject->assignee_id : $subject->assigned_agent_id;

        if ($assignedId) {
            $assigned = $site->account->agents()->whereKey($assignedId)->first();

            if ($assigned && $site->supportsAgent($assigned)) {
                return $this->eligibleRecipient($assigned, $subject)
                    ? collect([$assigned])
                    : collect();
            }
        }

        $query = $site->hasExplicitSupportAgents()
            ? $site->eligibleSupportAgents()
            : $site->account->agents();

        return $query->get()
            ->filter(fn (User $agent): bool => $this->eligibleRecipient($agent, $subject))
            ->values();
    }

    private function eligibleRecipient(User $agent, Conversation|Ticket $subject): bool
    {
        if ($agent->isDeactivated()) {
            return false;
        }

        if ($subject instanceof Conversation) {
            return $agent->shouldReceiveConversationAlert($subject);
        }

        return $agent->hasAccountPermission(AccountPermission::ViewAlerts)
            && $agent->hasAccountPermission(AccountPermission::ManageTickets)
            && $agent->alertMode() !== User::ALERT_MODE_QUIET
            && ($agent->alertMode() !== User::ALERT_MODE_ASSIGNED
                || (int) $subject->assignee_id === $agent->id);
    }
}
