<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AccountPermission;
use App\Events\AgentAlertStored;
use App\Exceptions\RetryableAgentWebPushException;
use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\User;
use App\Notifications\AgentAlertWebPush;
use App\Support\AgentAlertPayload;
use App\Support\AgentVisibleRealtimePresence;
use App\Support\AgentWebPushConfig;
use App\Support\DashboardLanguage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/** Deliver a claimed alert version while serializing its final eligibility. */
final class SendAgentAlertWebPush implements ShouldQueueAfterCommit
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public function shouldQueue(AgentAlertStored $event): bool
    {
        try {
            $assessment = app(AgentWebPushConfig::class)->assessment();
        } catch (Throwable) {
            return false;
        }

        if (! in_array($assessment['status'], ['ready', 'unavailable'], true)
            || ! $event->recipient->alertPushEnabled()
            || $event->recipient->alertMode() === User::ALERT_MODE_QUIET) {
            return false;
        }

        try {
            if ($assessment['status'] === 'ready') {
                AgentPushSubscription::purgeStaleFor($event->recipient);
            }

            $hasSubscription = $assessment['status'] === 'unavailable'
                ? AgentPushSubscription::withoutGlobalScope(AgentPushSubscription::CURRENT_VAPID_SCOPE)
                    ->where('subscribable_type', $event->recipient->getMorphClass())
                    ->where('subscribable_id', $event->recipient->getKey())
                    ->exists()
                : $event->recipient->pushSubscriptions()->exists();

            return $hasSubscription;
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
        ?AgentVisibleRealtimePresence $visiblePresence = null,
    ): void {
        $assessment = $webPush->assessment();

        if ($assessment['status'] === 'unavailable') {
            throw new RetryableAgentWebPushException('Web Push settings are temporarily unavailable.');
        }

        if ($assessment['status'] !== 'ready') {
            return;
        }

        $accountId = $event->recipient->account_id;

        if ($accountId === null) {
            return;
        }

        $retryableFailure = null;
        $visiblePresence ??= app(AgentVisibleRealtimePresence::class);

        // Leaving a Reverb presence channel is asynchronous. A browser that is
        // already closing can therefore appear visible for one last lookup even
        // though it will never render the realtime alert. Confirm a positive
        // result after a short grace period before suppressing Web Push. The
        // wait happens before database locks are acquired.
        if ($visiblePresence->hasVisibleClient($event->recipient)) {
            Sleep::for(1)->seconds();

            if ($visiblePresence->hasVisibleClient($event->recipient)) {
                return;
            }
        }

        DB::transaction(function () use ($accountId, $event, &$retryableFailure): void {
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
                || $recipient->alertMode() === User::ALERT_MODE_QUIET
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
                || ! Gate::forUser($recipient)->allows('view', $alert)) {
                return;
            }

            try {
                Notification::sendNow(
                    $recipient,
                    new AgentAlertWebPush(
                        alertId: (string) $alert->id,
                        version: $event->version,
                        dashboardLocale: DashboardLanguage::for($recipient),
                    ),
                    [WebPushChannel::class],
                );
            } catch (RetryableAgentWebPushException $exception) {
                // The channel has already processed every report. Commit any
                // expired-subscription deletions before asking the queue to
                // retry transient siblings; throwing here would roll them back.
                $retryableFailure = $exception;
            }
        });

        if ($retryableFailure instanceof RetryableAgentWebPushException) {
            throw $retryableFailure;
        }
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
