<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\SlaDeadlineAlert;
use App\Notifications\TicketAssigned;
use App\Support\Sla\SlaAlertRouting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AlertDigestCandidateCollector
{
    public const DIGEST_QUEUED_AT_KEY = 'digest_queued_at';

    public const DIGEST_DELIVERY_CLAIM_KEY = 'digest_delivery_claim';

    public function __construct(
        private readonly SlaAlertRouting $slaAlertRouting,
        ?AgentAlertDeliveryCoordinator $deliveries = null,
    ) {
        $this->deliveries = $deliveries ?? app(AgentAlertDeliveryCoordinator::class);
    }

    private readonly AgentAlertDeliveryCoordinator $deliveries;

    /**
     * @return Collection<int, array{
     *     alert_version: string,
     *     delivery_state_key: string,
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
            ->map(function (DatabaseNotification $notification) use ($agent): ?array {
                $candidate = $this->candidateFor($agent, $notification);

                if ($candidate === null
                    || $this->deliveryClaimCovers($notification, $candidate['last_activity_at'])) {
                    return null;
                }

                return $candidate;
            })
            ->filter()
            ->values();
    }

    private function agentWantsDigest(User $agent): bool
    {
        return ! $agent->isDeactivated()
            && $agent->alertEmailEnabled()
            && ! $agent->alertInterruptionsPaused()
            && $agent->alertCadence() === User::ALERT_CADENCE_DIGEST;
    }

    /**
     * Persist an idempotency claim before handing a digest to SMTP.
     *
     * @param  Collection<int, array{alert_version: string, delivery_state_key: string, last_activity_at: string|null, notification_id: string}>  $candidates
     * @return Collection<int, array{alert_version: string, delivery_state_key: string, last_activity_at: string|null, notification_id: string}>
     */
    public function claimForDelivery(User $agent, Collection $candidates, string $claim): Collection
    {
        return DB::transaction(function () use ($agent, $candidates, $claim): Collection {
            $candidateByNotification = $candidates->keyBy('notification_id');
            $claimed = collect();

            DatabaseNotification::query()
                ->whereIn('id', $candidates->pluck('notification_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (DatabaseNotification $notification) use ($agent, $candidateByNotification, $claim, $claimed): void {
                    $candidate = $candidateByNotification->get((string) $notification->id);
                    $current = $this->candidateFor($agent, $notification);

                    if (! is_array($candidate)
                        || $current === null
                        || $current['alert_version'] !== $candidate['alert_version']
                        || $current['last_activity_at'] !== $candidate['last_activity_at']
                        || $this->deliveryClaimCovers($notification, $current['last_activity_at'])
                        || ! $this->deliveries->claimLocked(
                            $notification,
                            $current['alert_version'],
                            AgentAlertDeliveryCoordinator::CHANNEL_DIGEST_MAIL,
                            $claim,
                            $current['last_activity_at'],
                        )) {
                        return;
                    }

                    $notification->forceFill([
                        'data' => [
                            ...$notification->data,
                            self::DIGEST_DELIVERY_CLAIM_KEY => [
                                'token' => $claim,
                                'last_activity_at' => $candidate['last_activity_at'],
                            ],
                        ],
                    ])->save();
                    $claimed->push($candidate);
                });

            return $claimed->values();
        });
    }

    /**
     * Finalize only the exact states covered by an accepted SMTP handoff.
     *
     * @param  Collection<int, array{alert_version: string, delivery_state_key: string, last_activity_at: string|null, notification_id: string}>  $candidates
     */
    public function acceptDeliveryClaim(User $agent, Collection $candidates, string $claim, CarbonInterface $acceptedAt): void
    {
        DB::transaction(function () use ($agent, $candidates, $claim, $acceptedAt): void {
            $sentByNotification = $candidates->keyBy('notification_id');

            DatabaseNotification::query()
                ->whereIn('id', $candidates->pluck('notification_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (DatabaseNotification $notification) use ($agent, $sentByNotification, $claim, $acceptedAt): void {
                    if (! $this->deliveryClaimIsOwnedBy($notification, $claim)) {
                        return;
                    }

                    $sent = $sentByNotification->get((string) $notification->id);
                    $current = $this->candidateFor($agent, $notification, ignoreDelivery: true);
                    $data = $notification->data;
                    unset($data[self::DIGEST_DELIVERY_CLAIM_KEY]);

                    if (is_array($sent)) {
                        $this->deliveries->acceptBatchMailClaim(
                            $this->deliveries->claimPayload(
                                $notification,
                                $sent['alert_version'],
                                $claim,
                                $sent['delivery_state_key'],
                            ),
                            $acceptedAt,
                        );
                    }

                    if (is_array($sent)
                        && $current !== null
                        && $current['alert_version'] === $sent['alert_version']
                        && $current['last_activity_at'] === $sent['last_activity_at']) {
                        $data[self::DIGEST_QUEUED_AT_KEY] = $acceptedAt->toISOString();
                    }

                    $notification->forceFill(['data' => $data])->save();
                });
        });
    }

    /** Release a pre-SMTP claim only when this exact job still owns it. */
    public function releaseDeliveryClaim(Collection $candidates, string $claim): void
    {
        DB::transaction(function () use ($candidates, $claim): void {
            DatabaseNotification::query()
                ->whereIn('id', $candidates->pluck('notification_id')->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (DatabaseNotification $notification) use ($candidates, $claim): void {
                    if (! $this->deliveryClaimIsOwnedBy($notification, $claim)) {
                        return;
                    }

                    $candidate = $candidates->firstWhere('notification_id', (string) $notification->id);

                    if (is_array($candidate)) {
                        $this->deliveries->releaseUnstartedMailClaim(
                            $this->deliveries->claimPayload(
                                $notification,
                                $candidate['alert_version'],
                                $claim,
                                $candidate['delivery_state_key'],
                            ),
                        );
                    }

                    $data = $notification->data;
                    unset($data[self::DIGEST_DELIVERY_CLAIM_KEY]);
                    $notification->forceFill(['data' => $data])->save();
                });
        });
    }

    private function deliveryClaimCovers(DatabaseNotification $notification, ?string $lastActivityAt): bool
    {
        $claim = data_get($notification->data, self::DIGEST_DELIVERY_CLAIM_KEY);

        return is_array($claim)
            && array_key_exists('last_activity_at', $claim)
            && $claim['last_activity_at'] === $lastActivityAt
            && is_string(data_get($claim, 'token'))
            && data_get($claim, 'token') !== '';
    }

    private function deliveryClaimIsOwnedBy(DatabaseNotification $notification, string $claim): bool
    {
        $token = data_get($notification->data, self::DIGEST_DELIVERY_CLAIM_KEY.'.token');

        return is_string($token) && hash_equals($claim, $token);
    }

    /**
     * @return array{
     *     alert_version: string,
     *     delivery_state_key: string,
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
    private function candidateFor(
        User $agent,
        DatabaseNotification $notification,
        bool $ignoreDelivery = false,
    ): ?array {
        $candidate = match ($notification->type) {
            ConversationNeedsReply::class => $this->conversationCandidate($agent, $notification),
            TicketAssigned::class => $this->ticketCandidate($agent, $notification),
            SlaDeadlineAlert::class => $this->slaCandidate($agent, $notification),
            AutomationRuleMatched::class => $this->automationCandidate($agent, $notification),
            default => null,
        };

        if (! $candidate || $this->candidateWasQueuedAfterLastActivity($notification, $candidate['last_activity_at'])) {
            return null;
        }

        $version = AgentAlertPayload::version($notification);

        if (! $ignoreDelivery && $this->deliveries->candidateVersionCovered(
            $notification,
            $version,
            $candidate['last_activity_at'],
        )) {
            return null;
        }

        return [
            'alert_version' => $version,
            'delivery_state_key' => $this->deliveries->stateKey($candidate['last_activity_at']),
            ...$candidate,
        ];
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

    /** @return array<string, mixed>|null */
    private function slaCandidate(User $agent, DatabaseNotification $notification): ?array
    {
        $clockId = (int) data_get($notification->data, 'sla_clock_id');
        $clock = $clockId > 0
            ? SlaClock::query()->with(['site', 'subject'])->find($clockId)
            : null;

        if (! $clock || ! $clock->subject) {
            return null;
        }

        $stage = data_get($notification->data, 'stage');

        // A digest is a current-work summary. A warning whose work has since
        // completed, or whose clock has since breached, is durable history in
        // the alert centre but no longer something to interrupt email with.
        if (! is_string($stage)
            || ! $clock->alertStageIsCurrent($stage)
            || ! $this->slaAlertRouting->routesTo($clock, $agent)) {
            return null;
        }

        $isTicket = $clock->subject instanceof Ticket;
        $activityAt = $stage === 'breach'
            ? $clock->breached_at
            : $clock->warned_at;

        return [
            'kind' => 'sla_deadline',
            'last_activity_at' => $this->timestamp($activityAt ?? $notification->created_at),
            'last_activity_label' => $this->label($activityAt ?? $notification->created_at, $agent),
            'notification_id' => (string) $notification->id,
            'priority' => $clock->priority,
            'reference' => $isTicket ? 'Ticket #'.$clock->subject->id : $clock->subject->support_code,
            'site_name' => $clock->site?->name ?? 'Unknown site',
            'status' => $stage === 'breach' ? 'SLA breached' : 'SLA approaching breach',
            'subject' => $clock->subject->subject ?? ($isTicket ? 'Untitled ticket' : 'Untitled conversation'),
            'url' => (string) data_get($notification->data, 'url'),
        ];
    }

    /** @return array<string, mixed>|null */
    private function automationCandidate(User $agent, DatabaseNotification $notification): ?array
    {
        $subjectId = (int) data_get($notification->data, 'subject_id');
        $subject = match (data_get($notification->data, 'subject_kind')) {
            'ticket' => Ticket::query()->with('site')->find($subjectId),
            'conversation' => Conversation::query()->with('site')->find($subjectId),
            default => null,
        };

        if (! $subject || ! Gate::forUser($agent)->allows('view', $subject)) {
            return null;
        }

        $isTicket = $subject instanceof Ticket;

        return [
            'kind' => 'automation_rule_matched',
            'last_activity_at' => $this->timestamp($notification->created_at),
            'last_activity_label' => $this->label($notification->created_at, $agent),
            'notification_id' => (string) $notification->id,
            'priority' => $subject->priority,
            'reference' => $isTicket ? 'Ticket #'.$subject->id : $subject->support_code,
            'site_name' => $subject->site?->name ?? 'Unknown site',
            'status' => $subject->status,
            'subject' => $subject->subject ?? ($isTicket ? 'Untitled ticket' : 'Untitled conversation'),
            'url' => (string) data_get($notification->data, 'url'),
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
