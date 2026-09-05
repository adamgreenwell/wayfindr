<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** A deliberately low-detail notification safe for a locked screen. */
final class AgentAlertWebPush extends Notification
{
    public function __construct(
        public readonly string $alertId,
        public readonly string $version,
        public readonly string $dashboardLocale,
    ) {}

    /** @return array<int, class-string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, ?Notification $notification = null): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(__('profile.alerts.push_notification_title', [], $this->dashboardLocale))
            ->body(__('profile.alerts.push_notification_body', [], $this->dashboardLocale))
            ->lang(str_replace('_', '-', $this->dashboardLocale))
            ->icon('/favicon.ico')
            ->badge('/favicon.ico')
            // One stable tag per database alert means a queue retry or a
            // refreshed conversation replaces the existing OS notification.
            ->tag('wayfindr-agent-alert-'.$this->alertId)
            ->data([
                'url' => '/dashboard/alerts',
                'alert_id' => $this->alertId,
                'version' => $this->version,
            ])
            ->options([
                'TTL' => 300,
                'urgency' => 'normal',
            ]);
    }
}
