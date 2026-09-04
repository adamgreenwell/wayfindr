<?php

namespace App\Notifications;

use App\Models\Conversation;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Sla\SlaAlertRouting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Mime\Email;

class SlaDeadlineAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly SlaClock $clock,
        private readonly string $stage,
        private readonly ?string $onlyChannel = null,
    ) {
        if ($onlyChannel !== null && ! in_array($onlyChannel, ['database', 'mail'], true)) {
            throw new \InvalidArgumentException('Unknown SLA alert channel.');
        }

        $this->clock->loadMissing(['site', 'subject']);
    }

    public function via(object $notifiable): array
    {
        if ($this->onlyChannel !== null) {
            return [$this->onlyChannel];
        }

        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->wantsImmediateAlertEmail()) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync', 'mail' => (string) config('queue.default', 'sync')];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! in_array($channel, ['database', 'mail'], true)) {
            return true;
        }

        $recipient = $notifiable instanceof User ? User::query()->whereKey($notifiable->id)->first() : null;
        $clock = SlaClock::query()->with('subject.site')->find($this->clock->id);

        $currentAndRouted = $recipient instanceof User
            && $clock?->subject !== null
            && $clock->alertStageIsCurrent($this->stage)
            && Gate::forUser($recipient)->allows('view', $clock->subject)
            && app(SlaAlertRouting::class)->routesTo($clock, $recipient);

        return $currentAndRouted
            && ($channel === 'database' || $recipient->wantsImmediateAlertEmail());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $stage = $this->stage === 'breach' ? 'breached' : 'approaching';

        $message = (new MailMessage)
            ->subject('Wayfindr SLA '.$stage.': '.$data['subject'])
            ->line($data['site_name'].' has support work '.$stage.' its '.$this->metricLabel().' target.')
            ->line('Priority: '.ucfirst((string) $data['priority']))
            ->action($data['subject_kind'] === 'ticket' ? 'Open ticket' : 'Open conversation', url($data['url']));

        if (is_string($this->id) && $this->id !== '') {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'wayfindr.invalid';
            $message->withSymfonyMessage(function (Email $email) use ($host): void {
                $email->getHeaders()->remove('Message-ID');
                $email->getHeaders()->addIdHeader('Message-ID', $this->id.'@'.$host);
            });
        }

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $subject = $this->clock->subject;
        $ticket = $subject instanceof Ticket ? $subject : null;
        $conversation = $subject instanceof Conversation ? $subject : null;

        return [
            'kind' => 'sla_deadline',
            'sla_clock_id' => $this->clock->id,
            'stage' => $this->stage,
            'metric' => $this->clock->metric,
            'priority' => $this->clock->priority,
            'subject_kind' => $ticket ? 'ticket' : 'conversation',
            'ticket_id' => $ticket?->id,
            'conversation_id' => $conversation?->id,
            'support_code' => $conversation?->support_code,
            'subject' => (string) ($subject?->subject ?: ($ticket ? 'Untitled ticket' : 'Untitled conversation')),
            'site_name' => $this->clock->site?->name ?? 'Unknown site',
            'target_seconds' => $this->clock->target_seconds,
            'elapsed_seconds' => $this->clock->elapsed_seconds,
            'url' => $ticket
                ? route('dashboard.tickets.show', $ticket, false)
                : route('dashboard.conversations.show', $conversation?->support_code, false),
        ];
    }

    private function metricLabel(): string
    {
        return $this->clock->metric === SlaClock::METRIC_FIRST_RESPONSE ? 'first-response' : 'resolution';
    }
}
