<?php

namespace App\Support\Automation;

use App\Enums\AccountPermission;
use App\Enums\AutomationRuleActionType;
use App\Enums\ConversationStatus;
use App\Enums\TicketStatus;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\TicketAssigned;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Conversations\ConversationPriorityLog;
use App\Support\Routing\AssignmentAuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class AutomationActionExecutor
{
    public function __construct(
        private AssignmentAuditTrail $assignmentAuditTrail,
        private ConversationLifecycleLog $conversationLifecycleLog,
        private ConversationPriorityLog $conversationPriorityLog,
    ) {}

    /**
     * @param  list<array{type: string, value: mixed}>  $actions
     * @return list<array{type: string, status: string, detail: string}>
     */
    public function execute(AutomationActionContext $automation, Ticket|Conversation $subject, array $actions): array
    {
        $results = [];

        foreach ($actions as $action) {
            $type = AutomationRuleActionType::from($action['type']);
            $results[] = match ($type) {
                AutomationRuleActionType::AssignAgent => $this->assignAgent($automation, $subject, (int) $action['value']),
                AutomationRuleActionType::AddLabel => $this->addLabel($automation, $subject, (int) $action['value']),
                AutomationRuleActionType::SetPriority => $this->setPriority($automation, $subject, (string) $action['value']),
                AutomationRuleActionType::SetStatus => $this->setStatus($automation, $subject, (string) $action['value']),
                AutomationRuleActionType::NotifyAgent => $this->notifyAgent($automation, $subject, (int) $action['value']),
                AutomationRuleActionType::PostInternalNote => $this->postInternalNote($automation, $subject, (string) $action['value']),
            };
        }

        return $results;
    }

    /** @return array{type: string, status: string, detail: string} */
    private function assignAgent(AutomationActionContext $automation, Ticket|Conversation $subject, int $agentId): array
    {
        $agent = $this->eligibleAgent($automation, $subject, $agentId);

        if (! $agent instanceof User) {
            return $this->result(AutomationRuleActionType::AssignAgent, 'noop', 'target_unavailable');
        }

        $currentId = $subject instanceof Ticket ? $subject->assignee_id : $subject->assigned_agent_id;

        if ((int) $currentId === (int) $agent->id) {
            return $this->result(AutomationRuleActionType::AssignAgent, 'noop', 'already_assigned');
        }

        $oldAssignee = match (true) {
            $currentId === null => null,
            $subject instanceof Ticket && $subject->relationLoaded('assignee') => $subject->assignee,
            $subject instanceof Conversation && $subject->relationLoaded('assignedAgent') => $subject->assignedAgent,
            default => User::query()->whereKey($currentId)->first(),
        };

        if ($subject instanceof Ticket) {
            $subject->forceFill(['assignee_id' => $agent->id])->save();
            $this->assignmentAuditTrail->ticket($subject, $automation->actor, $oldAssignee, $agent, $automation->source(), [
                $automation->idKey() => $automation->id,
            ]);
        } else {
            $subject->forceFill(['assigned_agent_id' => $agent->id])->save();
            $this->assignmentAuditTrail->conversation($subject, $automation->actor, $oldAssignee, $agent, $automation->source(), [
                $automation->idKey() => $automation->id,
            ]);
        }

        return $this->result(AutomationRuleActionType::AssignAgent, 'applied', 'agent:'.$agent->id);
    }

    /** @return array{type: string, status: string, detail: string} */
    private function addLabel(AutomationActionContext $automation, Ticket|Conversation $subject, int $labelId): array
    {
        if (! $subject instanceof Ticket) {
            throw new InvalidArgumentException('Only tickets can receive labels.');
        }

        $label = $automation->validatedLabel?->id === $labelId
            ? $automation->validatedLabel
            : TicketLabel::query()
                ->whereKey($labelId)
                ->where('account_id', $automation->accountId)
                ->first();

        if (! $label instanceof TicketLabel) {
            throw new InvalidArgumentException("Label {$labelId} is not available to this {$automation->description()}.");
        }

        $changes = $subject->labels()->syncWithoutDetaching([$label->id]);

        if ($changes['attached'] === []) {
            return $this->result(AutomationRuleActionType::AddLabel, 'noop', 'already_labeled');
        }

        $this->recordTicketActivity($automation, $subject, 'ticket.label_added', [
            'label_id' => $label->id,
            'label_name' => $label->name,
            'label_slug' => $label->slug,
        ]);

        return $this->result(AutomationRuleActionType::AddLabel, 'applied', 'label:'.$label->id);
    }

    /** @return array{type: string, status: string, detail: string} */
    private function setPriority(AutomationActionContext $automation, Ticket|Conversation $subject, string $priority): array
    {
        $previous = (string) $subject->priority;

        if ($previous === $priority) {
            return $this->result(AutomationRuleActionType::SetPriority, 'noop', 'already:'.$priority);
        }

        $subject->forceFill(['priority' => $priority])->save();

        if ($subject instanceof Ticket) {
            $this->recordTicketActivity($automation, $subject, 'ticket.updated', [
                'changes' => [
                    'priority' => ['old' => $previous, 'new' => $priority],
                ],
            ]);
        } else {
            $this->conversationPriorityLog->updated(
                $subject,
                $automation->actor,
                $previous,
                $priority,
                $automation->source(),
                [$automation->idKey() => $automation->id],
            );
        }

        return $this->result(AutomationRuleActionType::SetPriority, 'applied', $previous.'->'.$priority);
    }

    /** @return array{type: string, status: string, detail: string} */
    private function setStatus(AutomationActionContext $automation, Ticket|Conversation $subject, string $status): array
    {
        $previous = (string) $subject->status;

        if ($previous === $status) {
            return $this->result(AutomationRuleActionType::SetStatus, 'noop', 'already:'.$status);
        }

        if ($subject instanceof Ticket) {
            $this->setTicketStatus($automation, $subject, TicketStatus::from($status), $previous);
        } else {
            $this->setConversationStatus($automation, $subject, ConversationStatus::from($status), $previous);
        }

        return $this->result(AutomationRuleActionType::SetStatus, 'applied', $previous.'->'.$status);
    }

    /** @return array{type: string, status: string, detail: string} */
    private function notifyAgent(AutomationActionContext $automation, Ticket|Conversation $subject, int $agentId): array
    {
        $agent = $this->eligibleAgent($automation, $subject, $agentId);

        if (! $agent instanceof User || ! $agent->hasAccountPermission(AccountPermission::ViewAlerts)) {
            return $this->result(AutomationRuleActionType::NotifyAgent, 'noop', 'target_unavailable');
        }

        if ($agent->alertMode() === User::ALERT_MODE_QUIET) {
            return $this->result(AutomationRuleActionType::NotifyAgent, 'noop', 'quiet_mode');
        }

        DB::afterCommit(function () use ($agent, $automation, $subject): void {
            $recipient = User::query()->whereKey($agent->id)->first();
            $current = $this->freshSubject($subject);

            if (! $recipient instanceof User
                || ! $current instanceof Ticket && ! $current instanceof Conversation
                || ! Gate::forUser($recipient)->allows('view', $current)) {
                return;
            }

            try {
                $recipient->notify(new AutomationRuleMatched($current, $automation->name, $automation->kind));
            } catch (Throwable $exception) {
                Log::error('Automation completed, but its agent notification failed.', [
                    $automation->idKey() => $automation->id,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->id,
                    'agent_id' => $agent->id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });

        return $this->result(AutomationRuleActionType::NotifyAgent, 'queued', 'agent:'.$agent->id);
    }

    /** @return array{type: string, status: string, detail: string} */
    private function postInternalNote(AutomationActionContext $automation, Ticket|Conversation $subject, string $body): array
    {
        if (! $subject instanceof Ticket) {
            throw new InvalidArgumentException('Only tickets can receive internal notes.');
        }

        $this->recordTicketActivity($automation, $subject, 'ticket.note_added', ['body' => $body]);

        return $this->result(AutomationRuleActionType::PostInternalNote, 'applied', 'private_ticket_note');
    }

    private function eligibleAgent(AutomationActionContext $automation, Ticket|Conversation $subject, int $agentId): ?User
    {
        if ($automation->validatedAgent?->id === $agentId
            && in_array((int) $subject->site_id, $automation->validatedSiteIds, true)) {
            return $automation->validatedAgent;
        }

        $agent = User::query()
            ->with('customRole')
            ->whereKey($agentId)
            ->first();

        if ($agent instanceof User && (int) $agent->account_id !== $automation->accountId) {
            throw new InvalidArgumentException("Agent {$agentId} is not available to this {$automation->description()}.");
        }

        $permission = $subject instanceof Ticket
            ? AccountPermission::ManageTickets
            : AccountPermission::ViewConversations;
        $subject->loadMissing('site');

        if (! $agent instanceof User
            || ! $agent->hasAccountPermission($permission)
            || $subject->site?->supportsAgent($agent) !== true) {
            return null;
        }

        return $agent;
    }

    private function setTicketStatus(
        AutomationActionContext $automation,
        Ticket $ticket,
        TicketStatus $status,
        string $previous,
    ): void {
        $ticket->forceFill([
            'status' => $status,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ])->save();

        $actions = match ($status) {
            TicketStatus::Closed => ['ticket.closed'],
            TicketStatus::Pending => array_values(array_filter([
                $previous === TicketStatus::Closed->value ? 'ticket.reopened' : null,
                'ticket.pending',
            ])),
            TicketStatus::Open => match ($previous) {
                TicketStatus::Closed->value => ['ticket.reopened'],
                TicketStatus::Pending->value => ['ticket.unheld'],
                default => [],
            },
        };

        foreach ($actions as $action) {
            $this->recordTicketActivity($automation, $ticket, $action);
        }
    }

    private function setConversationStatus(
        AutomationActionContext $automation,
        Conversation $conversation,
        ConversationStatus $status,
        string $previous,
    ): void {
        $conversation->forceFill([
            'status' => $status,
            'closed_at' => $status === ConversationStatus::Closed ? now() : null,
        ])->save();

        $metadata = [
            'source' => $automation->source(),
            $automation->idKey() => $automation->id,
        ];

        if ($status === ConversationStatus::Closed) {
            $this->conversationLifecycleLog->closed($conversation, $automation->actor, $previous, $metadata);
        } else {
            $this->conversationLifecycleLog->reopened($conversation, $automation->actor, $previous, $metadata);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function recordTicketActivity(
        AutomationActionContext $automation,
        Ticket $ticket,
        string $action,
        array $metadata = [],
    ): void {
        $ticket->auditEvents()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'actor_type' => $automation->actor?->getMorphClass(),
            'actor_id' => $automation->actor?->id,
            'action' => $action,
            'metadata' => [
                ...$metadata,
                'source' => $automation->source(),
                $automation->idKey() => $automation->id,
            ],
            'occurred_at' => now(),
        ]);
    }

    public function notifyFinalTicketAssignmentAfterCommit(
        Ticket $ticket,
        ?int $previousAssigneeId,
        ?User $assignedBy = null,
    ): void {
        $assigneeId = $ticket->assignee_id === null ? null : (int) $ticket->assignee_id;

        if ($assigneeId === null
            || $assigneeId === $previousAssigneeId
            || $assigneeId === $assignedBy?->id) {
            return;
        }

        DB::afterCommit(function () use ($assignedBy, $assigneeId, $ticket): void {
            $recipient = User::query()->whereKey($assigneeId)->first();
            $current = Ticket::query()->with('site')->whereKey($ticket->id)->first();

            if (! $recipient instanceof User
                || ! $current instanceof Ticket
                || ! $recipient->shouldReceiveTicketAssignmentAlert($current)) {
                return;
            }

            try {
                $recipient->notify(new TicketAssigned($current, $assignedBy));
            } catch (Throwable $exception) {
                Log::error('Automation assigned a ticket, but its alert failed.', [
                    'ticket_id' => $ticket->id,
                    'assignee_id' => $assigneeId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function freshSubject(Ticket|Conversation $subject): Ticket|Conversation|null
    {
        return $subject instanceof Ticket
            ? Ticket::query()->with('site')->whereKey($subject->id)->first()
            : Conversation::query()->with('site')->whereKey($subject->id)->first();
    }

    /** @return array{type: string, status: string, detail: string} */
    private function result(AutomationRuleActionType $type, string $status, string $detail): array
    {
        return [
            'type' => $type->value,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
