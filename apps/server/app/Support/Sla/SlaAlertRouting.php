<?php

namespace App\Support\Sla;

use App\Enums\AccountPermission;
use App\Models\Conversation;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/** The current recipients for an SLA event, regardless of delivery cadence. */
final class SlaAlertRouting
{
    /** @return Collection<int, User> */
    public function recipients(SlaClock $clock): Collection
    {
        // Routing is also a delivery-time check. Reload instead of trusting a
        // queued notification's cached site or assignment relationships.
        $clock->load(['site.account', 'subject']);
        $subject = $clock->subject;
        $site = $clock->site;

        if (! $site || $site->isArchived() || ! $site->account || ! ($subject instanceof Conversation || $subject instanceof Ticket)) {
            return collect();
        }

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

    public function routesTo(SlaClock $clock, User $agent): bool
    {
        return $this->recipients($clock)
            ->contains(fn (User $recipient): bool => $recipient->is($agent));
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
