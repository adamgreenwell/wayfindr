<?php

namespace App\Support\Tickets;

use App\Enums\AccountPermission;
use App\Enums\TicketBulkAction;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\AuditEvent;
use App\Models\Ticket;
use App\Models\TicketBulkActionRun;
use App\Models\TicketLabel;
use App\Models\User;
use App\Support\Automation\AutomationActionContext;
use App\Support\Automation\AutomationActionExecutor;
use App\Support\Routing\AssignmentAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class TicketBulkActionService
{
    public function __construct(
        private AutomationActionExecutor $executor,
        private AssignmentAuditTrail $assignmentAuditTrail,
    ) {}

    /**
     * The smallest state snapshot that proves the reviewed action is still
     * acting on the value the agent saw. Unrelated ticket edits do not expire a
     * preview, but a change to this field does.
     *
     * @return array<string, mixed>
     */
    public function state(Ticket $ticket, TicketBulkAction $action, mixed $value): array
    {
        return match ($action) {
            TicketBulkAction::AssignAgent => ['assignee_id' => $ticket->assignee_id === null ? null : (int) $ticket->assignee_id],
            TicketBulkAction::AddLabel => [
                'label_id' => (int) $value,
                'attached' => $ticket->relationLoaded('labels')
                    ? $ticket->labels->contains('id', (int) $value)
                    : $ticket->labels()->whereKey((int) $value)->exists(),
            ],
            TicketBulkAction::SetPriority => ['priority' => (string) $ticket->priority],
            TicketBulkAction::SetStatus, TicketBulkAction::Close => [
                'status' => (string) $ticket->status,
                'closed_at' => $ticket->closed_at?->getTimestamp(),
            ],
        };
    }

    /** @param Collection<int, Ticket> $tickets */
    public function prepareState(Collection $tickets, TicketBulkAction $action, mixed $value): void
    {
        if ($action !== TicketBulkAction::AddLabel) {
            return;
        }

        $tickets->load(['labels' => fn ($query) => $query->whereKey((int) $value)]);
    }

    public function wouldChange(Ticket $ticket, TicketBulkAction $action, mixed $value): bool
    {
        return match ($action) {
            TicketBulkAction::AssignAgent => (int) $ticket->assignee_id !== (int) $value,
            TicketBulkAction::AddLabel => ! $this->state($ticket, $action, $value)['attached'],
            TicketBulkAction::SetPriority => (string) $ticket->priority !== (string) $value,
            TicketBulkAction::SetStatus => (string) $ticket->status !== (string) $value,
            TicketBulkAction::Close => (string) $ticket->status !== TicketStatus::Closed->value,
        };
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return list<array{ticket_id: int, subject: string, site: string, before: array<string, mixed>, after: array<string, mixed>}>
     */
    public function apply(
        User $agent,
        TicketBulkActionRun $run,
        Collection $tickets,
        mixed $value,
        User|TicketLabel|null $validatedTarget,
    ): array {
        $action = $run->actionEnum();
        $siteIds = $tickets->pluck('site_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $context = AutomationActionContext::forTicketBulkAction($run, $agent, $validatedTarget, $siteIds);
        $changes = [];

        foreach ($tickets as $ticket) {
            if (! $this->wouldChange($ticket, $action, $value)) {
                continue;
            }

            $before = $this->state($ticket, $action, $value);
            $previousRuleState = $this->ruleState($ticket);

            $this->executor->execute($context, $ticket, [[
                'type' => $action->automationAction()->value,
                'value' => $action === TicketBulkAction::Close ? TicketStatus::Closed->value : $value,
            ]]);

            if ($action === TicketBulkAction::AddLabel
                && $validatedTarget instanceof TicketLabel
                && $ticket->relationLoaded('labels')) {
                $ticket->setRelation('labels', $ticket->labels->push($validatedTarget));
            }

            $after = $this->state($ticket, $action, $value);
            $changes[] = [
                'ticket_id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'site' => (string) $ticket->site->name,
                'before' => $before,
                'after' => $after,
            ];

            $this->executor->notifyFinalTicketAssignmentAfterCommit(
                $ticket,
                $previousRuleState['assignee_id'],
                $agent,
            );

            if ($this->ruleState($ticket) !== $previousRuleState) {
                event(new TicketUpdated($ticket));
            }
        }

        return $changes;
    }

    /**
     * Restore direct bulk changes only when no later edit has touched the same
     * field. Automation side effects are deliberately not guessed at or
     * reversed.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return array{reverted: int, skipped: int, skipped_ticket_ids: list<int>}
     */
    public function undo(User $agent, TicketBulkActionRun $run, Collection $tickets): array
    {
        $action = $run->actionEnum();
        $tickets = $tickets->keyBy('id');
        $latestAudits = $this->latestRelevantAudits($tickets->values(), $action, $run);
        $reverted = 0;
        $skippedIds = [];

        foreach ($run->changes as $change) {
            $ticketId = (int) ($change['ticket_id'] ?? 0);
            $ticket = $tickets->get($ticketId);

            if (! $ticket instanceof Ticket
                || $this->state($ticket, $action, $this->storedValue($run)) !== ($change['after'] ?? null)
                || ! $this->auditBelongsToRun($latestAudits->get($ticketId), $run)) {
                $skippedIds[] = $ticketId;

                continue;
            }

            $previousRuleState = $this->ruleState($ticket);

            if (! $this->restore($ticket, $agent, $run, $change['before'] ?? [])) {
                $skippedIds[] = $ticketId;

                continue;
            }

            $reverted++;
            $this->executor->notifyFinalTicketAssignmentAfterCommit(
                $ticket,
                $previousRuleState['assignee_id'],
                $agent,
            );

            if ($this->ruleState($ticket) !== $previousRuleState) {
                event(new TicketUpdated($ticket));
            }
        }

        return [
            'reverted' => $reverted,
            'skipped' => count($skippedIds),
            'skipped_ticket_ids' => $skippedIds,
        ];
    }

    private function storedValue(TicketBulkActionRun $run): mixed
    {
        return data_get($run->value, 'value');
    }

    /** @param array<string, mixed> $before */
    private function restore(Ticket $ticket, User $agent, TicketBulkActionRun $run, array $before): bool
    {
        $action = $run->actionEnum();

        if ($action === TicketBulkAction::AssignAgent) {
            $previousAssigneeId = $before['assignee_id'] ?? null;
            $previousAssignee = $previousAssigneeId === null
                ? null
                : User::query()->with('customRole')->whereKey((int) $previousAssigneeId)->first();

            if ($previousAssigneeId !== null
                && (! $previousAssignee instanceof User
                    || (int) $previousAssignee->account_id !== (int) $run->account_id
                    || ! $previousAssignee->hasAccountPermission(AccountPermission::ManageTickets)
                    || ! $ticket->site->supportsAgent($previousAssignee))) {
                return false;
            }

            $currentAssignee = $ticket->assignee_id === null
                ? null
                : User::query()->whereKey($ticket->assignee_id)->first();
            $ticket->forceFill(['assignee_id' => $previousAssignee?->id])->save();
            $this->assignmentAuditTrail->ticket(
                $ticket,
                $agent,
                $currentAssignee,
                $previousAssignee,
                'bulk_action_undo',
                ['undo_of_ticket_bulk_action_run_id' => (int) $run->id],
            );

            return true;
        }

        if ($action === TicketBulkAction::AddLabel) {
            $labelId = (int) data_get($run->value, 'value');
            $ticket->labels()->detach($labelId);
            $this->recordActivity($ticket, $agent, 'ticket.label_removed', $run, [
                'label_id' => $labelId,
                'label_name' => (string) data_get($run->value, 'label'),
            ]);

            return true;
        }

        if ($action === TicketBulkAction::SetPriority) {
            $current = (string) $ticket->priority;
            $previous = (string) ($before['priority'] ?? '');
            $ticket->forceFill(['priority' => $previous])->save();
            $this->recordActivity($ticket, $agent, 'ticket.updated', $run, [
                'changes' => ['priority' => ['old' => $current, 'new' => $previous]],
            ]);

            return true;
        }

        $current = (string) $ticket->status;
        $previous = (string) ($before['status'] ?? '');
        $previousClosedAt = $before['closed_at'] ?? null;
        $ticket->forceFill([
            'status' => $previous,
            'closed_at' => $previousClosedAt === null
                ? null
                : Carbon::createFromTimestampUTC((int) $previousClosedAt),
        ])->save();
        foreach ($this->statusAuditActions($current, $previous) as $auditAction) {
            $this->recordActivity($ticket, $agent, $auditAction, $run);
        }

        return true;
    }

    /**
     * Fetch only the latest relevant audit per ticket. Doing the relevance
     * filtering and grouping in SQL keeps a 200-ticket undo bounded even when
     * those tickets have years of update history.
     *
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, AuditEvent>
     */
    private function latestRelevantAudits(
        Collection $tickets,
        TicketBulkAction $action,
        TicketBulkActionRun $run,
    ): Collection {
        $ticketIds = $tickets->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $relevant = AuditEvent::query()
            ->where('subject_type', (new Ticket)->getMorphClass())
            ->whereIn('subject_id', $ticketIds)
            ->whereIn('action', $this->relevantAuditActions($action))
            ->when(
                $action === TicketBulkAction::AddLabel,
                fn (Builder $query) => $query->where(
                    'metadata->label_id',
                    (int) data_get($run->value, 'value'),
                ),
            )
            ->when(
                $action === TicketBulkAction::SetPriority,
                fn (Builder $query) => $query->whereNotNull('metadata->changes->priority'),
            );
        $latestIds = (clone $relevant)
            ->selectRaw('MAX(id)')
            ->groupBy('subject_id');

        return AuditEvent::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy(fn (AuditEvent $event): int => (int) $event->subject_id);
    }

    private function auditBelongsToRun(mixed $event, TicketBulkActionRun $run): bool
    {
        return $event instanceof AuditEvent
            && (int) data_get($event->metadata, 'ticket_bulk_action_run_id') === (int) $run->id;
    }

    /** @return list<string> */
    private function relevantAuditActions(TicketBulkAction $action): array
    {
        return match ($action) {
            TicketBulkAction::AssignAgent => ['ticket.assignee_updated'],
            TicketBulkAction::AddLabel => ['ticket.label_added', 'ticket.label_removed'],
            TicketBulkAction::SetPriority => ['ticket.updated'],
            TicketBulkAction::SetStatus, TicketBulkAction::Close => [
                'ticket.closed',
                'ticket.pending',
                'ticket.reopened',
                'ticket.unheld',
            ],
        };
    }

    /** @return list<string> */
    private function statusAuditActions(string $from, string $to): array
    {
        if ($to === TicketStatus::Closed->value) {
            return ['ticket.closed'];
        }

        if ($to === TicketStatus::Pending->value) {
            return array_values(array_filter([
                $from === TicketStatus::Closed->value ? 'ticket.reopened' : null,
                'ticket.pending',
            ]));
        }

        return [$from === TicketStatus::Closed->value ? 'ticket.reopened' : 'ticket.unheld'];
    }

    /** @param array<string, mixed> $metadata */
    private function recordActivity(
        Ticket $ticket,
        User $agent,
        string $action,
        TicketBulkActionRun $run,
        array $metadata = [],
    ): void {
        $ticket->auditEvents()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'action' => $action,
            'metadata' => [
                ...$metadata,
                'source' => 'bulk_action_undo',
                'undo_of_ticket_bulk_action_run_id' => (int) $run->id,
            ],
            'occurred_at' => now(),
        ]);
    }

    /** @return array{assignee_id: int|null, priority: string, status: string} */
    private function ruleState(Ticket $ticket): array
    {
        return [
            'assignee_id' => $ticket->assignee_id === null ? null : (int) $ticket->assignee_id,
            'priority' => (string) $ticket->priority,
            'status' => (string) $ticket->status,
        ];
    }
}
