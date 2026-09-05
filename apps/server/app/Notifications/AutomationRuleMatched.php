<?php

namespace App\Notifications;

use App\Enums\AccountPermission;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Concerns\CoordinatesAgentAlertMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class AutomationRuleMatched extends Notification implements ShouldQueue
{
    use CoordinatesAgentAlertMail, Queueable;

    public function __construct(
        private readonly Ticket|Conversation $subject,
        private readonly string $ruleName,
        private readonly string $automationKind = 'rule',
    ) {
        $this->subject->loadMissing('site');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->wantsImmediateAlertEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => (string) config('queue.default', 'sync'),
        ];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        $recipient = User::query()->whereKey($notifiable->id)->first();
        $subject = $this->freshSubject();

        if (! $recipient instanceof User
            || ! $subject instanceof Ticket && ! $subject instanceof Conversation
            || ! $recipient->hasAccountPermission(AccountPermission::ViewAlerts)
            || $recipient->alertMode() === User::ALERT_MODE_QUIET
            || ! Gate::forUser($recipient)->allows('view', $subject)) {
            return false;
        }

        return $channel !== 'mail' || $recipient->wantsImmediateAlertEmail();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->automationKind === 'macro'
            ? (new MailMessage)
                ->subject('Wayfindr macro applied: '.$this->ruleName)
                ->line('Macro “'.$this->ruleName.'” was applied to '.$this->subjectLabel().'.')
                ->line('Site: '.$this->subject->site->name)
                ->action('Open support work', url($this->subjectUrl()))
            : (new MailMessage)
                ->subject('Wayfindr automation matched: '.$this->ruleName)
                ->line('Automation “'.$this->ruleName.'” matched '.$this->subjectLabel().'.')
                ->line('Site: '.$this->subject->site->name)
                ->action('Open support work', url($this->subjectUrl()));

        return $this->coordinateAgentAlertMail($message);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $isTicket = $this->subject instanceof Ticket;

        return [
            'kind' => 'automation_rule_matched',
            'automation_kind' => $this->automationKind,
            'rule_name' => $this->ruleName,
            'subject_kind' => $isTicket ? 'ticket' : 'conversation',
            'subject_id' => $this->subject->id,
            'ticket_id' => $isTicket ? $this->subject->id : null,
            'conversation_id' => $isTicket ? null : $this->subject->id,
            'support_code' => $isTicket ? null : $this->subject->support_code,
            'subject' => $this->subject->subject,
            'priority' => $this->subject->priority,
            'status' => $this->subject->status,
            'site_name' => $this->subject->site->name,
            'url' => $this->subjectUrl(),
        ];
    }

    private function freshSubject(): Ticket|Conversation|null
    {
        return $this->subject instanceof Ticket
            ? Ticket::query()->with('site')->whereKey($this->subject->id)->first()
            : Conversation::query()->with('site')->whereKey($this->subject->id)->first();
    }

    private function subjectLabel(): string
    {
        return $this->subject instanceof Ticket
            ? 'ticket #'.$this->subject->id.' “'.$this->subject->subject.'”'
            : 'conversation '.$this->subject->support_code;
    }

    private function subjectUrl(): string
    {
        return $this->subject instanceof Ticket
            ? route('dashboard.tickets.show', $this->subject, false)
            : route('dashboard.conversations.show', $this->subject->support_code, false);
    }
}
