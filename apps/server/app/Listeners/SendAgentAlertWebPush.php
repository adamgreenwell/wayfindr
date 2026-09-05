<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AccountPermission;
use App\Events\AgentAlertStored;
use App\Models\User;
use App\Notifications\AgentAlertWebPush;
use App\Support\AgentAlertPayload;
use App\Support\AgentWebPushConfig;
use App\Support\DashboardLanguage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/** Deliver a claimed alert version outside its database/realtime lock scope. */
final class SendAgentAlertWebPush implements ShouldQueueAfterCommit
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [30, 120, 300];

    public function shouldQueue(AgentAlertStored $event): bool
    {
        try {
            return app(AgentWebPushConfig::class)->isReady()
                && $event->recipient->alertPushEnabled()
                && $event->recipient->alertMode() !== User::ALERT_MODE_QUIET
                && $event->recipient->pushSubscriptions()->exists();
        } catch (Throwable) {
            // An unconfigured or not-yet-migrated optional channel must not
            // interfere with the durable database alert or its live broadcast.
            return false;
        }
    }

    public function handle(AgentAlertStored $event, AgentWebPushConfig $webPush): void
    {
        if (! $webPush->isReady()) {
            return;
        }

        $recipient = User::query()->whereKey($event->recipient->id)->first();

        if (! $recipient instanceof User
            || $recipient->isDeactivated()
            || $recipient->alertMode() === User::ALERT_MODE_QUIET
            || ! $recipient->alertPushEnabled()
            || ! $recipient->hasAccountPermission(AccountPermission::ViewAlerts)
            || ! $recipient->pushSubscriptions()->exists()) {
            return;
        }

        $alert = DatabaseNotification::query()
            ->whereKey($event->alert->id)
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->id)
            ->whereNull('read_at')
            ->first();

        if (! $alert instanceof DatabaseNotification
            || ! hash_equals($event->version, AgentAlertPayload::version($alert))
            || ! Gate::forUser($recipient)->allows('view', $alert)) {
            return;
        }

        Notification::sendNow(
            $recipient,
            new AgentAlertWebPush(
                alertId: (string) $alert->id,
                version: $event->version,
                dashboardLocale: DashboardLanguage::for($recipient),
            ),
            [WebPushChannel::class],
        );
    }
}
