<?php

namespace App\Support\Routing;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;

final class AssignmentAuditTrail
{
    public function conversation(
        Conversation $conversation,
        ?User $actor,
        ?User $oldAssignee,
        ?User $newAssignee,
        string $source,
        array $metadata = [],
    ): void {
        $this->record($conversation, (int) $conversation->site_id, $actor, $oldAssignee, $newAssignee, $source, $metadata);
    }

    public function ticket(
        Ticket $ticket,
        ?User $actor,
        ?User $oldAssignee,
        ?User $newAssignee,
        string $source,
        array $metadata = [],
    ): void {
        $this->record($ticket, (int) $ticket->site_id, $actor, $oldAssignee, $newAssignee, $source, $metadata);
    }

    private function record(
        Conversation|Ticket $subject,
        int $siteId,
        ?User $actor,
        ?User $oldAssignee,
        ?User $newAssignee,
        string $source,
        array $metadata,
    ): void {
        $subject->auditEvents()->create([
            'account_id' => $subject instanceof Ticket
                ? $subject->account_id
                : $subject->site()->value('account_id'),
            'site_id' => $siteId,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->id,
            'action' => $subject instanceof Ticket
                ? 'ticket.assignee_updated'
                : 'conversation.assignee_updated',
            'metadata' => [
                'old_assignee_id' => $oldAssignee?->id,
                'old_assignee_name' => $oldAssignee?->name,
                'new_assignee_id' => $newAssignee?->id,
                'new_assignee_name' => $newAssignee?->name,
                'source' => $source,
                'strategy' => $source === 'automatic' ? 'round_robin' : null,
                ...$metadata,
            ],
            'occurred_at' => now(),
        ]);
    }
}
