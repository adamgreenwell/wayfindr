<?php

namespace App\Notifications;

use App\Models\ApiToken;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Concerns\CoordinatesAgentAlertMail;
use App\Support\Visitors\AlertMailErasureGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Gate;

class TicketAssigned extends Notification implements ShouldQueue
{
    use CoordinatesAgentAlertMail, Queueable;

    public function __construct(
        private readonly Ticket $ticket,
        private readonly User|ApiToken|null $assignedBy,
    ) {
        $this->ticket->loadMissing(['site']);
        $this->afterCommit();
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

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($channel !== 'mail') {
            return true;
        }

        $recipient = $notifiable instanceof User
            ? User::query()->whereKey($notifiable->id)->first()
            : null;
        $ticket = Ticket::query()
            ->with('site')
            ->whereKey($this->ticket->id)
            ->first();

        return $recipient instanceof User
            && $ticket instanceof Ticket
            && $recipient->wantsImmediateAlertEmail()
            && Gate::forUser($recipient)->allows('view', $ticket)
            && $recipient->shouldReceiveTicketAssignmentAlert($ticket);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return AlertMailErasureGuard::stamp($this->coordinateAgentAlertMail((new MailMessage)
            ->subject('Wayfindr ticket assigned: '.$this->ticket->subject)
            ->line($this->assignmentActorName().' assigned you a ticket on '.$this->ticket->site->name.'.')
            ->line('Ticket: #'.$this->ticket->id)
            ->line('Priority: '.ucfirst($this->ticket->priority))
            ->action('Open ticket', route('dashboard.tickets.show', $this->ticket))), $this->ticket);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'ticket_assigned',
            'ticket_id' => $this->ticket->id,
            'subject' => $this->ticket->subject,
            'priority' => $this->ticket->priority,
            'site_name' => $this->ticket->site->name,
            'assigned_by_name' => $this->assignmentActorName(),
            'assigned_by_type' => $this->assignmentActorType(),
            'url' => route('dashboard.tickets.show', $this->ticket, false),
        ];
    }

    /**
     * Who assigned the ticket, as a kind rather than a name, for an alert
     * stored before alerts recorded it, as far as the name can prove it. A
     * name in the shape assignmentActorName() gives Wayfindr or an
     * integration is one an agent can also have, so it says "unknown"; any
     * other name is only ever an agent's.
     */
    public static function actorTypeFromName(string $name): string
    {
        return $name === 'Wayfindr' || (str_starts_with($name, 'Integration “') && str_ends_with($name, '”'))
            ? 'unknown'
            : 'agent';
    }

    private function assignmentActorName(): string
    {
        return match (true) {
            $this->assignedBy instanceof ApiToken => 'Integration “'.$this->assignedBy->name.'”',
            $this->assignedBy instanceof User => $this->assignedBy->name,
            default => 'Wayfindr',
        };
    }

    private function assignmentActorType(): string
    {
        return match (true) {
            $this->assignedBy instanceof ApiToken => 'integration',
            $this->assignedBy instanceof User => 'agent',
            default => 'system',
        };
    }
}
