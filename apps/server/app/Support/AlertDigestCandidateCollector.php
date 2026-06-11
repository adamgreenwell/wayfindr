<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AlertDigestCandidateCollector
{
    /**
     * @return Collection<int, array{
     *     kind: string,
     *     last_activity_at: string|null,
     *     notification_id: string,
     *     priority: string|null,
     *     reference: string,
     *     site_name: string,
     *     status: string|null,
     *     subject: string,
     *     url: string
     * }>
     */
    public function forAgent(User $agent): Collection
    {
        if (! $this->agentWantsDigest($agent)) {
            return collect();
        }

        return $agent
            ->unreadNotifications()
            ->latest()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->map(fn (DatabaseNotification $notification): ?array => $this->candidateFor($agent, $notification))
            ->filter()
            ->values();
    }

    private function agentWantsDigest(User $agent): bool
    {
        return ! $agent->isDeactivated()
            && $agent->alertEmailEnabled()
            && $agent->alertMode() !== User::ALERT_MODE_QUIET
            && $agent->alertCadence() === User::ALERT_CADENCE_DIGEST;
    }

    /**
     * @return array{
     *     kind: string,
     *     last_activity_at: string|null,
     *     notification_id: string,
     *     priority: string|null,
     *     reference: string,
     *     site_name: string,
     *     status: string|null,
     *     subject: string,
     *     url: string
     * }|null
     */
    private function candidateFor(User $agent, DatabaseNotification $notification): ?array
    {
        return match ($notification->type) {
            ConversationNeedsReply::class => $this->conversationCandidate($agent, $notification),
            TicketAssigned::class => $this->ticketCandidate($agent, $notification),
            default => null,
        };
    }

    /**
     * @return array{
     *     kind: string,
     *     last_activity_at: string|null,
     *     notification_id: string,
     *     priority: null,
     *     reference: string,
     *     site_name: string,
     *     status: string|null,
     *     subject: string,
     *     url: string
     * }|null
     */
    private function conversationCandidate(User $agent, DatabaseNotification $notification): ?array
    {
        $conversationId = (int) data_get($notification->data, 'conversation_id');
        $conversation = $conversationId > 0
            ? Conversation::query()->with(['latestMessage', 'site'])->find($conversationId)
            : null;

        if (
            ! $conversation
            || $conversation->status !== 'open'
            || $conversation->attentionState() !== 'needs_reply'
            || ! $agent->shouldReceiveConversationAlert($conversation)
        ) {
            return null;
        }

        return [
            'kind' => 'conversation_needs_reply',
            'last_activity_at' => $this->timestamp($conversation->last_message_at ?? $notification->created_at),
            'notification_id' => (string) $notification->id,
            'priority' => null,
            'reference' => $conversation->support_code,
            'site_name' => $conversation->site?->name ?? 'Unknown site',
            'status' => $conversation->status,
            'subject' => $conversation->subject ?? 'Untitled conversation',
            'url' => route('dashboard.conversations.show', $conversation->support_code, false),
        ];
    }

    /**
     * @return array{
     *     kind: string,
     *     last_activity_at: string|null,
     *     notification_id: string,
     *     priority: string|null,
     *     reference: string,
     *     site_name: string,
     *     status: string|null,
     *     subject: string,
     *     url: string
     * }|null
     */
    private function ticketCandidate(User $agent, DatabaseNotification $notification): ?array
    {
        $ticketId = (int) data_get($notification->data, 'ticket_id');
        $ticket = $ticketId > 0
            ? Ticket::query()->with('site')->find($ticketId)
            : null;

        if (! $ticket || ! $agent->shouldReceiveTicketAssignmentAlert($ticket)) {
            return null;
        }

        return [
            'kind' => 'ticket_assigned',
            'last_activity_at' => $this->timestamp($ticket->updated_at ?? $notification->created_at),
            'notification_id' => (string) $notification->id,
            'priority' => $ticket->priority,
            'reference' => 'Ticket #'.$ticket->id,
            'site_name' => $ticket->site?->name ?? 'Unknown site',
            'status' => $ticket->status,
            'subject' => $ticket->subject,
            'url' => route('dashboard.tickets.show', $ticket, false),
        ];
    }

    private function timestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toISOString();
    }
}
