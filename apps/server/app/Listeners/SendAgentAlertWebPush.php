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

        $retryableFailure = null;
        $settings ??= app(OperatorSettings::class);
        $deliveries ??= app(AgentAlertDeliveryCoordinator::class);

        DB::transaction(function () use ($accountId, $channel, $deliveries, $event, &$retryableFailure, $settings, $webPush): void {
            // Coordinate with operator rotation and subscription enrollment,
            // then bypass the process-local settings cache. A worker that
            // started this job on the old key must never purge a browser that
            // enrolled on the newly committed generation.
            $this->refreshWebPushSettingsUnderLock($settings);
            $assessment = $webPush->assessment();

            if ($assessment['status'] === 'unavailable') {
                $retryableFailure = new RetryableAgentWebPushException(
                    'Web Push settings are temporarily unavailable.',
                );

                return;
            }

            if ($assessment['status'] !== 'ready') {
                return;
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
                return;
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
                return;
            }

            AgentPushSubscription::purgeStaleFor($recipient);

            if (! $recipient->pushSubscriptions()->exists()) {
                return;
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
                || $deliveries->versionCovered($alert, $event->version)
                || ! Gate::forUser($recipient)->allows('view', $alert)) {
                return;
            }

            $deliveryChannel = $channel ?? app(WebPushChannel::class);

            if (! $deliveryChannel instanceof AgentWebPushChannel) {
                throw new LogicException('Wayfindr Web Push delivery channel is not configured.');
            }

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
                // The channel has already processed every report. Commit any
                // expired-subscription deletions before asking the queue to
                // retry transient siblings. If even one browser accepted this
                // version, that is the winning channel and no retry is needed.
                if (! $deliveryChannel->deliveryAccepted()) {
                    $retryableFailure = $exception;

                    return;
                }
            }

            if ($deliveryChannel->deliveryAccepted()) {
                $deliveries->acceptPushLocked($alert, $event->version);
            }
        });

        if ($retryableFailure instanceof RetryableAgentWebPushException) {
            throw $retryableFailure;
        }
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
