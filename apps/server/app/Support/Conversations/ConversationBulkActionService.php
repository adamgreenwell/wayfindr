<?php

namespace App\Support\Conversations;

use App\Enums\AccountPermission;
use App\Enums\ConversationBulkAction;
use App\Enums\ConversationStatus;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationBulkActionRun;
use App\Models\User;
use App\Support\Automation\AutomationActionContext;
use App\Support\Automation\AutomationActionExecutor;
use App\Support\Routing\AssignmentAuditTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class ConversationBulkActionService
{
    public function __construct(
        private AutomationActionExecutor $executor,
        private AssignmentAuditTrail $assignmentAuditTrail,
        private ConversationLifecycleLog $lifecycleLog,
        private ConversationPriorityLog $priorityLog,
    ) {}

    /** @return array<string, mixed> */
    public function state(Conversation $conversation, ConversationBulkAction $action): array
    {
        return match ($action) {
            ConversationBulkAction::AssignAgent => [
                'assigned_agent_id' => $conversation->assigned_agent_id === null
                    ? null
                    : (int) $conversation->assigned_agent_id,
            ],
            ConversationBulkAction::SetPriority => ['priority' => (string) $conversation->priority],
            ConversationBulkAction::SetStatus, ConversationBulkAction::Close => [
                'status' => (string) $conversation->status,
                'closed_at' => $conversation->closed_at?->getTimestamp(),
            ],
        };
    }

    public function wouldChange(Conversation $conversation, ConversationBulkAction $action, mixed $value): bool
    {
        return match ($action) {
            ConversationBulkAction::AssignAgent => (int) $conversation->assigned_agent_id !== (int) $value,
            ConversationBulkAction::SetPriority => (string) $conversation->priority !== (string) $value,
            ConversationBulkAction::SetStatus => (string) $conversation->status !== (string) $value,
            ConversationBulkAction::Close => (string) $conversation->status !== ConversationStatus::Closed->value,
        };
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return list<array{conversation_id: int, before: array<string, mixed>, after: array<string, mixed>}>
     */
    public function apply(
        User $agent,
        ConversationBulkActionRun $run,
        Collection $conversations,
        mixed $value,
        ?User $validatedTarget,
    ): array {
        $action = $run->actionEnum();
        $siteIds = $conversations->pluck('site_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $context = AutomationActionContext::forConversationBulkAction($run, $agent, $validatedTarget, $siteIds);
        $changes = [];

        foreach ($conversations as $conversation) {
            if (! $this->wouldChange($conversation, $action, $value)) {
                continue;
            }

            $before = $this->state($conversation, $action);
            $this->executor->execute($context, $conversation, [[
                'type' => $action->automationAction()->value,
                'value' => $action === ConversationBulkAction::Close
                    ? ConversationStatus::Closed->value
                    : $value,
            ]]);
            $changes[] = [
                'conversation_id' => (int) $conversation->id,
                'before' => $before,
                'after' => $this->state($conversation, $action),
            ];
        }

        return $changes;
    }

    /**
     * Restore only direct bulk changes whose field has not been touched since
     * this run. Side effects outside that field are deliberately left alone.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return array{reverted: int, skipped: int, skipped_conversation_ids: list<int>}
     */
    public function undo(User $agent, ConversationBulkActionRun $run, Collection $conversations): array
    {
        $action = $run->actionEnum();
        $conversations = $conversations->keyBy('id');
        $latestAudits = $this->latestRelevantAudits($conversations->values(), $action);
        $previousAssignees = $action === ConversationBulkAction::AssignAgent
            ? $this->previousAssignees($run)
            : collect();
        $reverted = 0;
        $skippedIds = [];

        foreach ($run->changes as $change) {
            $conversationId = (int) ($change['conversation_id'] ?? 0);
            $conversation = $conversations->get($conversationId);

            if (! $conversation instanceof Conversation
                || $this->state($conversation, $action) !== ($change['after'] ?? null)
                || ! $this->auditBelongsToRun($latestAudits->get($conversationId), $run)) {
                $skippedIds[] = $conversationId;

                continue;
            }

            if (! $this->restore(
                $conversation,
                $agent,
                $run,
                $change['before'] ?? [],
                $previousAssignees,
            )) {
                $skippedIds[] = $conversationId;

                continue;
            }

            $reverted++;
        }

        return [
            'reverted' => $reverted,
            'skipped' => count($skippedIds),
            'skipped_conversation_ids' => $skippedIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  Collection<int, User>  $previousAssignees
     */
    private function restore(
        Conversation $conversation,
        User $agent,
        ConversationBulkActionRun $run,
        array $before,
        Collection $previousAssignees,
    ): bool {
        $action = $run->actionEnum();

        if ($action === ConversationBulkAction::AssignAgent) {
            $previousAssigneeId = $before['assigned_agent_id'] ?? null;
            $previousAssignee = $previousAssigneeId === null
                ? null
                : $previousAssignees->get((int) $previousAssigneeId);

            if ($previousAssigneeId !== null
                && (! $previousAssignee instanceof User
                    || (int) $previousAssignee->account_id !== (int) $run->account_id
                    || ! $previousAssignee->hasAccountPermission(AccountPermission::ViewConversations)
                    || ! $conversation->site->supportsAgent($previousAssignee))) {
                return false;
            }

            $currentAssignee = $conversation->assignedAgent;
            $conversation->forceFill(['assigned_agent_id' => $previousAssignee?->id])->save();
            $this->assignmentAuditTrail->conversation(
                $conversation,
                $agent,
                $currentAssignee,
                $previousAssignee,
                'bulk_action_undo',
                ['undo_of_conversation_bulk_action_run_id' => (int) $run->id],
            );

            return true;
        }

        if ($action === ConversationBulkAction::SetPriority) {
            $current = (string) $conversation->priority;
            $previous = (string) ($before['priority'] ?? '');
            $conversation->forceFill(['priority' => $previous])->save();
            $this->priorityLog->updated(
                $conversation,
                $agent,
                $current,
                $previous,
                'bulk_action_undo',
                ['undo_of_conversation_bulk_action_run_id' => (int) $run->id],
            );

            return true;
        }

        $current = (string) $conversation->status;
        $previous = (string) ($before['status'] ?? '');
        $previousClosedAt = $before['closed_at'] ?? null;
        $conversation->forceFill([
            'status' => $previous,
            'closed_at' => $previousClosedAt === null
                ? null
                : Carbon::createFromTimestampUTC((int) $previousClosedAt),
        ])->save();
        $metadata = [
            'source' => 'bulk_action_undo',
            'undo_of_conversation_bulk_action_run_id' => (int) $run->id,
        ];

        if ($previous === ConversationStatus::Closed->value) {
            $this->lifecycleLog->closed($conversation, $agent, $current, $metadata);
        } else {
            $this->lifecycleLog->reopened($conversation, $agent, $current, $metadata);
        }

        return true;
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, AuditEvent>
     */
    private function latestRelevantAudits(
        Collection $conversations,
        ConversationBulkAction $action,
    ): Collection {
        $conversationIds = $conversations->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $relevant = AuditEvent::query()
            ->where('subject_type', (new Conversation)->getMorphClass())
            ->whereIn('subject_id', $conversationIds)
            ->whereIn('action', $this->relevantAuditActions($action));
        $latestIds = (clone $relevant)
            ->selectRaw('MAX(id)')
            ->groupBy('subject_id');

        return AuditEvent::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy(fn (AuditEvent $event): int => (int) $event->subject_id);
    }

    private function auditBelongsToRun(mixed $event, ConversationBulkActionRun $run): bool
    {
        return $event instanceof AuditEvent
            && (int) data_get($event->metadata, 'conversation_bulk_action_run_id') === (int) $run->id;
    }

    /** @return list<string> */
    private function relevantAuditActions(ConversationBulkAction $action): array
    {
        return match ($action) {
            ConversationBulkAction::AssignAgent => ['conversation.assignee_updated'],
            ConversationBulkAction::SetPriority => [ConversationPriorityLog::UPDATED],
            ConversationBulkAction::SetStatus, ConversationBulkAction::Close => [
                ConversationLifecycleLog::CLOSED,
                ConversationLifecycleLog::REOPENED,
            ],
        };
    }

    /** @return Collection<int, User> */
    private function previousAssignees(ConversationBulkActionRun $run): Collection
    {
        $ids = collect($run->changes)
            ->pluck('before.assigned_agent_id')
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->with('customRole')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (User $user): int => (int) $user->id);
    }
}
