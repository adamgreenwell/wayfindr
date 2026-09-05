<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AgentAlertDelivery;
use App\Models\SlaAlertDelivery;
use App\Models\User;
use App\Notifications\Concerns\CoordinatesAgentAlertMail;
use App\Notifications\SlaDeadlineAlert;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Serialize realtime, Web Push, and mail around one durable alert version. */
final class AgentAlertDeliveryCoordinator
{
    public const ID_HEADER = 'X-Wayfindr-Agent-Alert';

    public const VERSION_HEADER = 'X-Wayfindr-Agent-Alert-Version';

    public const STATE_HEADER = 'X-Wayfindr-Agent-Alert-State';

    public const CLAIM_HEADER = 'X-Wayfindr-Agent-Alert-Claim';

    public const BATCH_CLAIM_HEADER = 'X-Wayfindr-Agent-Alert-Batch-Claim';

    public const STATE_EVENT = 'event';

    public const CHANNEL_PUSH = 'push';

    public const CHANNEL_IMMEDIATE_MAIL = 'immediate_mail';

    public const CHANNEL_DIGEST_MAIL = 'digest_mail';

    public const CHANNEL_UNATTENDED_MAIL = 'unattended_mail';

    /**
     * @return array{
     *     status: 'claimed'|'covered'|'unavailable',
     *     claim?: array{notification_id: string, alert_version: string, state_key: string, claim_token: string}
     * }
     */
    public function claimNotificationMail(User $recipient, Notification $notification): array
    {
        $alert = $this->alertForNotification($recipient, $notification);

        if (! $alert instanceof DatabaseNotification) {
            return ['status' => 'unavailable'];
        }

        $claim = (string) Str::uuid();

        return DB::transaction(function () use ($alert, $claim, $recipient): array {
            $current = DatabaseNotification::query()
                ->whereKey($alert->id)
                ->where('notifiable_type', $recipient->getMorphClass())
                ->where('notifiable_id', $recipient->id)
                ->lockForUpdate()
                ->first();

            if (! $current instanceof DatabaseNotification || $current->read_at !== null) {
                return ['status' => 'covered'];
            }

            $version = AgentAlertPayload::version($current);

            if (! $this->claimLocked(
                $current,
                $version,
                self::CHANNEL_IMMEDIATE_MAIL,
                $claim,
            )) {
                return ['status' => 'covered'];
            }

            return [
                'status' => 'claimed',
                'claim' => $this->claimPayload($current, $version, $claim),
            ];
        });
    }

    public function versionCovered(DatabaseNotification $alert, string $version): bool
    {
        return $this->realtimeReceiptCovers($alert, $version)
            || AgentAlertDelivery::query()
                ->where('notification_id', $alert->id)
                ->where('alert_version', $version)
                ->exists();
    }

    /**
     * Digest state may advance without refreshing the underlying alert row.
     * A delivery covers only activity that existed when that channel won.
     */
    public function candidateVersionCovered(
        DatabaseNotification $alert,
        string $version,
        ?string $lastActivityAt,
    ): bool {
        if (! is_string($lastActivityAt) || trim($lastActivityAt) === '') {
            return $this->versionCovered($alert, $version);
        }

        try {
            $activity = CarbonImmutable::parse($lastActivityAt);
        } catch (\Throwable) {
            return $this->versionCovered($alert, $version);
        }

        if ($this->realtimeReceiptCoversCandidate($alert, $version, $activity)) {
            return true;
        }

        return AgentAlertDelivery::query()
            ->where('notification_id', $alert->id)
            ->where('alert_version', $version)
            ->get()
            ->contains(function (AgentAlertDelivery $delivery) use ($activity): bool {
                // The message contains the state captured when the channel
                // claimed it. SMTP acceptance can happen after newer work and
                // must not make that unseen work look covered retroactively.
                $coveredAt = $delivery->created_at;

                return $coveredAt !== null && $coveredAt->greaterThanOrEqualTo($activity);
            });
    }

    /** The caller must hold the notification row lock. */
    public function claimLocked(
        DatabaseNotification $alert,
        string $version,
        string $channel,
        string $claim,
        ?string $lastActivityAt = null,
    ): bool {
        $stateKey = $this->stateKey($lastActivityAt);

        if ($alert->read_at !== null
            || ! hash_equals($version, AgentAlertPayload::version($alert))
            || $this->realtimeReceiptCovers($alert, $version)) {
            return false;
        }

        $existing = AgentAlertDelivery::query()
            ->where('notification_id', $alert->id)
            ->where('alert_version', $version)
            ->where('state_key', $stateKey)
            ->first();

        if ($existing instanceof AgentAlertDelivery) {
            // A worker may die after claiming but before MessageSending. A
            // retry of the same mail channel safely takes over that still-
            // unsent row; the old worker's token can no longer cross SMTP.
            if ($existing->channel !== $channel
                || $existing->started_at !== null
                || $existing->accepted_at !== null) {
                return false;
            }

            $existing->forceFill(['claim_token' => $claim])->save();

            return true;
        }

        if ($this->candidateVersionCovered($alert, $version, $lastActivityAt)) {
            return false;
        }

        AgentAlertDelivery::query()->create([
            'notification_id' => (string) $alert->id,
            'alert_version' => $version,
            'state_key' => $stateKey,
            'channel' => $channel,
            'claim_token' => $claim,
        ]);

        return true;
    }

    /** The caller must hold the notification row lock through Web Push. */
    public function acceptPushLocked(DatabaseNotification $alert, string $version): void
    {
        if (! hash_equals($version, AgentAlertPayload::version($alert))) {
            return;
        }

        AgentAlertDelivery::query()->firstOrCreate(
            [
                'notification_id' => (string) $alert->id,
                'alert_version' => $version,
                'state_key' => self::STATE_EVENT,
            ],
            [
                'channel' => self::CHANNEL_PUSH,
                'claim_token' => null,
                'started_at' => now(),
                'accepted_at' => now(),
            ],
        );
    }

    /**
     * @param  array{notification_id: string, alert_version: string, state_key: string, claim_token: string}  $claim
     */
    public function markMailTransportStarted(array $claim, ?string $slaDeliveryId = null): void
    {
        $started = DB::transaction(function () use ($claim, $slaDeliveryId): bool {
            $slaDelivery = $slaDeliveryId === null
                ? null
                : SlaAlertDelivery::query()
                    ->where('public_id', $slaDeliveryId)
                    ->lockForUpdate()
                    ->first();

            if ($slaDeliveryId !== null
                && (! $slaDelivery instanceof SlaAlertDelivery
                    || $slaDelivery->channel !== 'mail'
                    || $slaDelivery->claimed_at === null
                    || $slaDelivery->started_at !== null
                    || $slaDelivery->accepted_at !== null
                    || $slaDelivery->cancelled_at !== null
                    || $slaDelivery->deduplicated_at !== null)) {
                throw new LogicException('The SLA mail delivery is no longer eligible for transport.');
            }

            $alert = DatabaseNotification::query()
                ->whereKey($claim['notification_id'])
                ->lockForUpdate()
                ->first();

            if (! $alert instanceof DatabaseNotification
                || $alert->read_at !== null
                || ! hash_equals($claim['alert_version'], AgentAlertPayload::version($alert))
                || $this->realtimeReceiptCovers($alert, $claim['alert_version'])) {
                $this->releaseUnstartedMailClaim($claim);

                return false;
            }

            $agentAlertStarted = AgentAlertDelivery::query()
                ->where('notification_id', $claim['notification_id'])
                ->where('alert_version', $claim['alert_version'])
                ->where('state_key', $claim['state_key'])
                ->where('claim_token', $claim['claim_token'])
                ->whereNull('started_at')
                ->whereNull('accepted_at')
                ->update(['started_at' => now()]) === 1;

            if (! $agentAlertStarted) {
                return false;
            }

            if (! $slaDelivery instanceof SlaAlertDelivery) {
                return true;
            }

            $slaDelivery->forceFill(['started_at' => now()])->save();

            return true;
        });

        if (! $started) {
            throw new LogicException('The agent alert mail delivery is no longer eligible for transport.');
        }
    }

    /** Recheck every candidate in a batch immediately before SMTP. */
    public function markBatchMailTransportStarted(string $claim): void
    {
        $started = DB::transaction(function () use ($claim): bool {
            $notificationIds = AgentAlertDelivery::query()
                ->where('claim_token', $claim)
                ->whereIn('channel', [self::CHANNEL_DIGEST_MAIL, self::CHANNEL_UNATTENDED_MAIL])
                ->whereNull('started_at')
                ->whereNull('accepted_at')
                ->orderBy('notification_id')
                ->pluck('notification_id')
                ->all();

            if ($notificationIds === []) {
                return false;
            }

            $alerts = DatabaseNotification::query()
                ->whereIn('id', $notificationIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (DatabaseNotification $alert): string => (string) $alert->id);
            $deliveries = AgentAlertDelivery::query()
                ->where('claim_token', $claim)
                ->whereIn('channel', [self::CHANNEL_DIGEST_MAIL, self::CHANNEL_UNATTENDED_MAIL])
                ->whereNull('started_at')
                ->whereNull('accepted_at')
                ->orderBy('notification_id')
                ->lockForUpdate()
                ->get();

            if ($deliveries->count() !== count($notificationIds)) {
                return false;
            }

            foreach ($deliveries as $delivery) {
                $alert = $alerts->get((string) $delivery->notification_id);

                if (! $alert instanceof DatabaseNotification
                    || $alert->read_at !== null
                    || ! hash_equals($delivery->alert_version, AgentAlertPayload::version($alert))
                    || ! $this->batchClaimIsCurrent($alert, $delivery, $claim)) {
                    return false;
                }
            }

            return AgentAlertDelivery::query()
                ->whereIn('id', $deliveries->modelKeys())
                ->whereNull('started_at')
                ->whereNull('accepted_at')
                ->update(['started_at' => now()]) === $deliveries->count();
        });

        if (! $started) {
            throw new LogicException('The agent alert batch is no longer eligible for mail transport.');
        }
    }

    /**
     * @param  array{notification_id: string, alert_version: string, state_key: string, claim_token: string}  $claim
     */
    public function acceptMailClaim(array $claim, ?CarbonInterface $acceptedAt = null): void
    {
        $acceptedAt ??= now();
        $accepted = DB::transaction(function () use ($acceptedAt, $claim): bool {
            $delivery = AgentAlertDelivery::query()
                ->where('notification_id', $claim['notification_id'])
                ->where('alert_version', $claim['alert_version'])
                ->where('state_key', $claim['state_key'])
                ->where('claim_token', $claim['claim_token'])
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->first();

            if (! $delivery instanceof AgentAlertDelivery) {
                return false;
            }

            // Mail::fake does not emit MessageSending, but NotificationSent is
            // still authoritative success. Production keeps the earlier
            // transport-boundary timestamp when MessageSending did fire.
            $delivery->forceFill([
                'started_at' => $delivery->started_at ?? $acceptedAt,
                'accepted_at' => $acceptedAt,
            ])->save();

            return true;
        });

        if (! $accepted) {
            throw new LogicException('The accepted agent alert mail claim could not be finalized.');
        }
    }

    /**
     * Digest and unattended mail already keep their own pre-SMTP batch claim.
     * Finalize the shared version record when that established send returns.
     *
     * @param  array{notification_id: string, alert_version: string, state_key: string, claim_token: string}  $claim
     */
    public function acceptBatchMailClaim(array $claim, CarbonInterface $acceptedAt): void
    {
        $accepted = DB::transaction(function () use ($acceptedAt, $claim): bool {
            $delivery = AgentAlertDelivery::query()
                ->where('notification_id', $claim['notification_id'])
                ->where('alert_version', $claim['alert_version'])
                ->where('state_key', $claim['state_key'])
                ->where('claim_token', $claim['claim_token'])
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->first();

            if (! $delivery instanceof AgentAlertDelivery) {
                return false;
            }

            $delivery->forceFill([
                'started_at' => $delivery->started_at ?? $acceptedAt,
                'accepted_at' => $acceptedAt,
            ])->save();

            return true;
        });

        if (! $accepted) {
            throw new LogicException('The accepted agent alert batch claim could not be finalized.');
        }
    }

    /**
     * Release only a claim that is known not to have reached mail transport.
     *
     * @param  array{notification_id: string, alert_version: string, state_key: string, claim_token: string}  $claim
     */
    public function releaseUnstartedMailClaim(array $claim): void
    {
        AgentAlertDelivery::query()
            ->where('notification_id', $claim['notification_id'])
            ->where('alert_version', $claim['alert_version'])
            ->where('state_key', $claim['state_key'])
            ->where('claim_token', $claim['claim_token'])
            ->whereNull('started_at')
            ->whereNull('accepted_at')
            ->delete();
    }

    /** @return array{notification_id: string, alert_version: string, state_key: string, claim_token: string} */
    public function claimPayload(
        DatabaseNotification $alert,
        string $version,
        string $claim,
        string $stateKey = self::STATE_EVENT,
    ): array {
        return [
            'notification_id' => (string) $alert->id,
            'alert_version' => $version,
            'state_key' => $stateKey,
            'claim_token' => $claim,
        ];
    }

    private function realtimeReceiptCovers(DatabaseNotification $alert, string $version): bool
    {
        $receipt = $alert->getAttribute('agent_alert_realtime_received_version');

        return is_string($receipt) && hash_equals($version, $receipt);
    }

    private function realtimeReceiptCoversCandidate(
        DatabaseNotification $alert,
        string $version,
        CarbonInterface $activity,
    ): bool {
        if (! $this->realtimeReceiptCovers($alert, $version)) {
            return false;
        }

        $alertedAt = AgentAlertPayload::alertedAt($alert);

        return $alertedAt === null || $alertedAt->greaterThanOrEqualTo($activity);
    }

    private function batchClaimIsCurrent(
        DatabaseNotification $alert,
        AgentAlertDelivery $delivery,
        string $claim,
    ): bool {
        if ($delivery->channel === self::CHANNEL_UNATTENDED_MAIL) {
            return hash_equals(
                $claim,
                (string) data_get($alert->data, UnattendedConversationAlertCollector::UNATTENDED_DELIVERY_CLAIM_KEY),
            ) && ! $this->realtimeReceiptCovers($alert, $delivery->alert_version);
        }

        $digestClaim = data_get($alert->data, AlertDigestCandidateCollector::DIGEST_DELIVERY_CLAIM_KEY);
        $lastActivityAt = is_array($digestClaim)
            ? data_get($digestClaim, 'last_activity_at')
            : null;

        if (! is_array($digestClaim)
            || ! hash_equals($claim, (string) data_get($digestClaim, 'token'))
            || (! is_string($lastActivityAt) && $lastActivityAt !== null)
            || ! hash_equals($delivery->state_key, $this->stateKey($lastActivityAt))) {
            return false;
        }

        if ($lastActivityAt === null) {
            return ! $this->realtimeReceiptCovers($alert, $delivery->alert_version);
        }

        try {
            $activity = CarbonImmutable::parse($lastActivityAt);
        } catch (\Throwable) {
            return false;
        }

        return ! $this->realtimeReceiptCoversCandidate($alert, $delivery->alert_version, $activity);
    }

    private function alertForNotification(User $recipient, Notification $notification): ?DatabaseNotification
    {
        if (! in_array(CoordinatesAgentAlertMail::class, class_uses_recursive($notification), true)) {
            return null;
        }

        if (is_string($notification->id) && $notification->id !== '') {
            $byId = DatabaseNotification::query()
                ->whereKey($notification->id)
                ->where('notifiable_type', $recipient->getMorphClass())
                ->where('notifiable_id', $recipient->id)
                ->first();

            if ($byId instanceof DatabaseNotification) {
                return $byId;
            }
        }

        if (! $notification instanceof SlaDeadlineAlert) {
            return null;
        }

        return $recipient->unreadNotifications()
            ->where('type', SlaDeadlineAlert::class)
            ->latest()
            ->get()
            ->first(fn (DatabaseNotification $alert): bool => (int) data_get($alert->data, 'sla_clock_id') === $notification->clockId()
                && data_get($alert->data, 'stage') === $notification->stage());
    }

    public function stateKey(?string $lastActivityAt): string
    {
        return is_string($lastActivityAt) && trim($lastActivityAt) !== ''
            ? hash('sha256', $lastActivityAt)
            : self::STATE_EVENT;
    }
}
