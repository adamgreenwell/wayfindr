<?php

namespace App\Notifications;

use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ConversationNeedsReply extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private readonly ConversationMessage $message)
    {
        $this->message->loadMissing(['conversation.site', 'conversation.visitor']);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->wantsImmediateAlertEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => (string) config('queue.default', 'sync'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $conversation = $this->message->conversation;

        return (new MailMessage)
            ->subject('Wayfindr reply needed: '.($conversation->subject ?? $conversation->support_code))
            ->line($conversation->site->name.' has a visitor message waiting for you.')
            ->line('Support code: '.$conversation->support_code)
            ->line(Str::limit((string) $this->message->body, 240))
            ->action('Open conversation', route('dashboard.conversations.show', $conversation->support_code));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $conversation = $this->message->conversation;

        return [
            'kind' => 'conversation_needs_reply',
            'conversation_id' => $conversation->id,
            'message_id' => $this->message->id,
            'latest_message_id' => $this->message->id,
            'message_count' => 1,
            'support_code' => $conversation->support_code,
            'subject' => $conversation->subject ?? 'Untitled conversation',
            'site_name' => $conversation->site->name,
            'visitor_anonymous_id' => $conversation->visitor?->anonymous_id,
            'message_preview' => Str::limit((string) $this->message->body, 160),
            'url' => route('dashboard.conversations.show', $conversation->support_code, false),
        ];
    }
}
