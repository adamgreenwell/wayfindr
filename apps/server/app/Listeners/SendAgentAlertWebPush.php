<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AccountPermission;
use App\Events\AgentAlertStored;
use App\Exceptions\RetryableAgentWebPushException;
use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Notifications\AgentAlertWebPush;
use App\Support\AgentAlertDeliveryCoordinator;
use App\Support\AgentAlertPayload;
use App\Support\AgentWebPushChannel;
use App\Support\AgentWebPushConfig;
use App\Support\DashboardLanguage;
use App\Support\Settings\OperatorSettings;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/** Deliver a claimed alert version while serializing its final eligibility. */
final class SendAgentAlertWebPush implements ShouldQueueAfterCommit
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    /** Allow the live browser receipt to arrive before evaluating fallback. */
    public function withDelay(AgentAlertStored $event): int
    {
        return 1;
    }

    public function shouldQueue(AgentAlertStored $event): bool
    {
        try {
            return DB::transaction(function () use ($event): bool {
                $settings = app(OperatorSettings::class);

                $this->refreshWebPushSettingsUnderLock($settings);
                $assessment = app(AgentWebPushConfig::class)->assessment();

                if (! in_array($assessment['status'], ['ready', 'unavailable'], true)
                    || ! $event->recipient->alertPushEnabled()
                    || $event->recipient->alertInterruptionsPaused()) {
                    return false;
                }

                if ($assessment['status'] === 'ready') {
                    AgentPushSubscription::purgeStaleFor($event->recipient);
                }

                return $assessment['status'] === 'unavailable'
                    ? AgentPushSubscription::withoutGlobalScope(AgentPushSubscription::CURRENT_VAPID_SCOPE)
                        ->where('subscribable_type', $event->recipient->getMorphClass())
                        ->where('subscribable_id', $event->recipient->getKey())
                        ->exists()
                    : $event->recipient->pushSubscriptions()->exists();
            });
        } catch (Throwable) {
            // A separate subscription database may recover by the time the
            // queued listener runs. Preserve that retry unless schema inspection
            // can positively show this optional channel is not migrated yet.
            return ! $this->pushSubscriptionStorageIsKnownAbsent();
        }
    }

    public function handle(
        AgentAlertStored $event,
        AgentWebPushConfig $webPush,
        ?OperatorSettings $settings = null,
        ?AgentAlertDeliveryCoordinator $deliveries = null,
        ?AgentWebPushChannel $channel = null,
    ): void {
        $accountId = $event->recipient->account_id;

        if ($accountId === null) {
            return;
        }

        $settings ??= app(OperatorSettings::class);
        $deliveries ??= app(AgentAlertDeliveryCoordinator::class);
        $claim = $this->withLockedEligibleAlert(
            $event,
            $accountId,
            $webPush,
            $settings,
            $deliveries,
            null,
            fn (User $recipient, DatabaseNotification $alert): ?array => $deliveries->beginPushLocked(
                $alert,
                $event->version,
            ),
        );

        if (! is_array($claim)) {
            return;
        }

        $deliveryChannel = $channel ?? app(WebPushChannel::class);

        if (! $deliveryChannel instanceof AgentWebPushChannel) {
            $deliveries->releaseKnownFailedPushClaim($claim);

            throw new LogicException('Wayfindr Web Push delivery channel is not configured.');
        }

        $retryableFailure = null;

        try {
            $attempted = $this->withLockedEligibleAlert(
                $event,
                $accountId,
                $webPush,
                $settings,
                $deliveries,
                $claim,
                function (User $recipient, DatabaseNotification $alert) use (
                    $claim,
                    $deliveries,
                    $deliveryChannel,
                    $event,
                    &$retryableFailure,
                ): bool {
                    try {
                        $deliveryChannel->send(
                            $recipient,
                            new AgentAlertWebPush(
                                alertId: (string) $alert->id,
                                version: $event->version,
                                dashboardLocale: DashboardLanguage::for($recipient),
                            ),
                        );
                    } catch (RetryableAgentWebPushException $exception) {
                        // Every report has settled. A wholly failed attempt is
                        // known safe for retry and email fallback; one accepted
                        // sibling makes Push the winner despite the transient.
                        if (! $deliveryChannel->deliveryAccepted()) {
                            $deliveries->releaseKnownFailedPushClaim($claim);
                            $retryableFailure = $exception;

                            return true;
                        }
                    }

                    if ($deliveryChannel->deliveryAccepted()) {
                        $deliveries->acceptPushClaimLocked($claim);
                    } else {
                        $deliveries->releaseKnownFailedPushClaim($claim);
                    }

                    return true;
                },
            );
        } catch (RetryableAgentWebPushException $exception) {
            // Settings became unavailable before the external call. This is a
            // known-unsent outcome, so a retry and mail fallback stay open.
            $deliveries->releaseKnownFailedPushClaim($claim);

            throw $exception;
        }

        if ($attempted !== true) {
            // Eligibility changed between the durable pre-transport claim and
            // the final locked handoff. No external call occurred.
            $deliveries->releaseKnownFailedPushClaim($claim);

            return;
        }

        if ($retryableFailure instanceof RetryableAgentWebPushException) {
            throw $retryableFailure;
        }
    }

    /**
     * @param  array{notification_id: string, alert_version: string, state_key: string, claim_token: string}|null  $claim
     */
    private function withLockedEligibleAlert(
        AgentAlertStored $event,
        int $accountId,
        AgentWebPushConfig $webPush,
        OperatorSettings $settings,
        AgentAlertDeliveryCoordinator $deliveries,
        ?array $claim,
        Closure $action,
    ): mixed {
        return DB::transaction(function () use (
            $accountId,
            $action,
            $claim,
            $deliveries,
            $event,
            $settings,
            $webPush,
        ): mixed {
            // Coordinate with operator rotation and subscription enrollment,
            // then bypass the process-local settings cache. A worker that
            // started on an old key cannot purge a browser enrolled on the
            // newly committed generation.
            $this->refreshWebPushSettingsUnderLock($settings);
            $assessment = $webPush->assessment();

            if ($assessment['status'] === 'unavailable') {
                throw new RetryableAgentWebPushException(
                    'Web Push settings are temporarily unavailable.',
                );
            }

            if ($assessment['status'] !== 'ready') {
                return null;
            }

            // Match the account-then-user order used by deactivation, role,
            // site-access, and preference writers. Keep those locks plus the
            // alert row lock through the network send: whichever operation
            // wins the locks defines whether this exact unread alert is still
            // eligible, without a revocation or read racing the final call.
            $account = Account::query()
                ->whereKey($accountId)
                ->lockForUpdate()
                ->first();

            if (! $account instanceof Account) {
                return null;
            }

            $recipient = User::query()
                ->whereKey($event->recipient->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            if (! $recipient instanceof User
                || $recipient->isDeactivated()
                || $recipient->alertInterruptionsPaused()
                || ! $recipient->alertPushEnabled()
                || ! $recipient->hasAccountPermission(AccountPermission::ViewAlerts)) {
                return null;
            }

            AgentPushSubscription::purgeStaleFor($recipient);

            if (! $recipient->pushSubscriptions()->exists()) {
                return null;
            }

            $alert = DatabaseNotification::query()
                ->whereKey($event->alert->id)
                ->where('notifiable_type', $recipient->getMorphClass())
                ->where('notifiable_id', $recipient->id)
                ->whereNull('read_at')
                ->lockForUpdate()
                ->first();

            if (! $alert instanceof DatabaseNotification
                || ! hash_equals($event->version, AgentAlertPayload::version($alert))
                || ! Gate::forUser($recipient)->allows('view', $alert)) {
                return null;
            }

            if ($claim === null) {
                if ($deliveries->versionCovered($alert, $event->version)) {
                    return null;
                }
            } elseif (! $deliveries->pushClaimIsCurrentLocked($alert, $claim)) {
                return null;
            }

            return $action($recipient, $alert);
        });
    }

    /** Refresh the destructive generation boundary while rotation is excluded. */
    private function refreshWebPushSettingsUnderLock(OperatorSettings $settings): void
    {
        OperatorSetting::query()->insertOrIgnore([
            'key' => 'webpush.public_key',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        OperatorSetting::query()
            ->where('key', 'webpush.public_key')
            ->sharedLock()
            ->firstOrFail();
        $settings->refreshFromDatabase();
    }

    private function pushSubscriptionStorageIsKnownAbsent(): bool
    {
        try {
            $subscription = new AgentPushSubscription;
            $schema = Schema::connection($subscription->getConnectionName());
            $table = $subscription->getTable();

            return ! $schema->hasTable($table)
                || ! $schema->hasColumns($table, [
                    'subscribable_type',
                    'subscribable_id',
                    'vapid_public_key_hash',
                ]);
        } catch (Throwable) {
            // The same connection outage can prevent schema inspection. Unknown
            // storage is retryable; only a confirmed missing schema opts out.
            return false;
        }
    }
}
