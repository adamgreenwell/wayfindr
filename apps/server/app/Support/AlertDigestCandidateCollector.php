<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AlertDigestCandidateCollector
{
    public const DIGEST_QUEUED_AT_KEY = 'digest_queued_at';

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
        $candidate = match ($notification->type) {
            ConversationNeedsReply::class => $this->conversationCandidate($agent, $notification),
            TicketAssigned::class => $this->ticketCandidate($agent, $notification),
            default => null,
        };

        if (! $candidate || $this->candidateWasQueuedAfterLastActivity($notification, $candidate['last_activity_at'])) {
            return null;
        }

        return $candidate;
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
            'last_activity_label' => $this->label($conversation->last_message_at ?? $notification->created_at, $agent),
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
            'last_activity_label' => $this->label($ticket->updated_at ?? $notification->created_at, $agent),
            'notification_id' => (string) $notification->id,
            'priority' => $ticket->priority,
            'reference' => 'Ticket #'.$ticket->id,
            'site_name' => $ticket->site?->name ?? 'Unknown site',
            'status' => $ticket->status,
            'subject' => $ticket->subject,
            'url' => route('dashboard.tickets.show', $ticket, false),
        ];
    }

    /**
     * The machine value, kept for comparison and never shown to anyone.
     *
     * {@see self::candidateWasQueuedAfterLastActivity()} parses this back, so
     * it is UTC and it stays UTC. Its display twin is {@see self::label()}.
     */
    private function timestamp(?CarbonInterface $timestamp): ?string
    {
        return $timestamp?->toISOString();
    }

    /**
     * The same moment for the agent this digest is addressed to.
     *
     * The reader is passed rather than looked up, because there is nobody
     * signed in: a digest is assembled by a scheduled command and rendered in
     * a queue worker. This is the case `ReaderClock`'s explicit-reader
     * argument was written for, and until now nothing used it.
     *
     * What the email said before was `2026-08-24T15:05:00.000000Z` -- storage's
     * clock, in a format nobody reads, in the middle of a sentence.
     */
    private function label(?CarbonInterface $timestamp, User $agent): ?string
    {
        return $timestamp === null ? null : ReaderClock::dateTime($timestamp, $agent);
    }

    private function candidateWasQueuedAfterLastActivity(DatabaseNotification $notification, ?string $lastActivityAt): bool
    {
        $queuedAt = data_get($notification->data, self::DIGEST_QUEUED_AT_KEY);

        if (! is_string($queuedAt) || trim($queuedAt) === '') {
            return false;
        }

        if (! is_string($lastActivityAt) || trim($lastActivityAt) === '') {
            return true;
        }

        return CarbonImmutable::parse($queuedAt)->greaterThanOrEqualTo(CarbonImmutable::parse($lastActivityAt));
    }
}
