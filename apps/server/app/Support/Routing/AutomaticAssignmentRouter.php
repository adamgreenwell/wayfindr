<?php

namespace App\Support\Routing;

use App\Enums\AccountPermission;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\SiteRoutingState;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssigned;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AutomaticAssignmentRouter
{
    public function __construct(
        private SiteManagerCoverage $siteManagerCoverage,
        private AssignmentAuditTrail $auditTrail,
    ) {}

    public function assignConversation(Conversation $conversation): ?User
    {
        if ($conversation->assigned_agent_id !== null || $conversation->status === 'closed') {
            return null;
        }

        $assignee = DB::transaction(function () use ($conversation): ?User {
            $site = $this->siteForConversation($conversation);
            $this->siteManagerCoverage->lockAccount((int) $site->account_id);
            $site->refresh();
            $routing = SiteRouting::for($site);

            if (! $routing->enabled) {
                return null;
            }

            $conversation = Conversation::query()
                ->whereKey($conversation->id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($conversation->assigned_agent_id !== null || $conversation->status === 'closed') {
                return null;
            }

            $state = $this->lockedState($site);
            $candidates = $this->candidates($site, AccountPermission::ViewConversations);
            $loads = Conversation::query()
                ->join('sites', 'sites.id', '=', 'conversations.site_id')
                ->where('sites.account_id', $site->account_id)
                ->whereIn('conversations.assigned_agent_id', $candidates->pluck('id'))
                ->where('conversations.status', '!=', 'closed')
                ->selectRaw('conversations.assigned_agent_id, count(*) as aggregate')
                ->groupBy('conversations.assigned_agent_id')
                ->pluck('aggregate', 'conversations.assigned_agent_id');
            $candidates = $candidates
                ->filter(fn (User $agent): bool => (int) ($loads[$agent->id] ?? 0) < $routing->conversationCapacity)
                ->values();
            $assignee = $this->next($candidates, $state->last_conversation_agent_id);

            if (! $assignee) {
                return null;
            }

            $conversation->forceFill(['assigned_agent_id' => $assignee->id])->save();
            $state->forceFill(['last_conversation_agent_id' => $assignee->id])->save();
            $this->auditTrail->conversation($conversation, null, null, $assignee, 'automatic');

            return $assignee;
        });

        if ($assignee) {
            $conversation->refresh();
        }

        return $assignee;
    }

    public function assignTicket(Ticket $ticket): ?User
    {
        if ($ticket->assignee_id !== null || $ticket->status === 'closed') {
            return null;
        }

        $assignee = DB::transaction(function () use ($ticket): ?User {
            $site = Site::query()
                ->whereKey($ticket->site_id)
                ->where('account_id', $ticket->account_id)
                ->first();

            if (! $site instanceof Site) {
                return null;
            }
            $this->siteManagerCoverage->lockAccount((int) $site->account_id);
            $site->refresh();

            if (! SiteRouting::for($site)->enabled) {
                return null;
            }

            $ticket = Ticket::query()
                ->whereKey($ticket->id)
                ->where('account_id', $site->account_id)
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($ticket->assignee_id !== null || $ticket->status === 'closed') {
                return null;
            }

            $state = $this->lockedState($site);
            $assignee = $this->next(
                $this->candidates($site, AccountPermission::ManageTickets),
                $state->last_ticket_agent_id,
            );

            if (! $assignee) {
                return null;
            }

            $ticket->forceFill(['assignee_id' => $assignee->id])->save();
            $state->forceFill(['last_ticket_agent_id' => $assignee->id])->save();
            $this->auditTrail->ticket($ticket, null, null, $assignee, 'automatic');

            return $assignee;
        });

        if ($assignee) {
            $ticket->refresh();
            $this->notifyTicketAssigneeAfterCommit($ticket, $assignee);
        }

        return $assignee;
    }

    private function siteForConversation(Conversation $conversation): Site
    {
        return Site::query()->whereKey($conversation->site_id)->firstOrFail();
    }

    private function lockedState(Site $site): SiteRoutingState
    {
        SiteRoutingState::query()->firstOrCreate(['site_id' => $site->id]);

        return SiteRoutingState::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
    }

    /** @return Collection<int, User> */
    private function candidates(Site $site, AccountPermission $permission): Collection
    {
        $explicitIds = $site->eligibleSupportAgents()->pluck('users.id');
        $query = User::query()
            ->where('account_id', $site->account_id)
            ->whereNull('deactivated_at')
            ->where('routing_status', User::ROUTING_STATUS_ONLINE)
            ->with('customRole')
            ->orderBy('id');

        if ($explicitIds->isNotEmpty()) {
            $query->whereKey($explicitIds->all());
        }

        return $query->get()
            ->filter(fn (User $agent): bool => $agent->hasAccountPermission($permission))
            ->values();
    }

    /** @param Collection<int, User> $candidates */
    private function next(Collection $candidates, int|string|null $lastAgentId): ?User
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $lastAgentId = (int) $lastAgentId;

        return $candidates->first(fn (User $agent): bool => $agent->id > $lastAgentId)
            ?? $candidates->first();
    }

    private function notifyTicketAssigneeAfterCommit(Ticket $ticket, User $assignee): void
    {
        DB::afterCommit(function () use ($ticket, $assignee): void {
            $current = Ticket::query()->with('site')->whereKey($ticket->id)->first();
            $recipient = User::query()->whereKey($assignee->id)->first();

            if (! $current instanceof Ticket
                || ! $recipient instanceof User
                || ! $recipient->shouldReceiveTicketAssignmentAlert($current)) {
                return;
            }

            try {
                $recipient->notify(new TicketAssigned($current, null));
            } catch (Throwable $exception) {
                Log::error('Automatically assigned ticket stored, but its alert failed.', [
                    'ticket_id' => $ticket->id,
                    'assignee_id' => $assignee->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }
}
