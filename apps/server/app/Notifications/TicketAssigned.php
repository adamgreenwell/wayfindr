<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Ticket $ticket,
        private readonly User $assignedBy,
    ) {
        $this->ticket->loadMissing(['site']);
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
        return (new MailMessage)
            ->subject('Wayfindr ticket assigned: '.$this->ticket->subject)
            ->line($this->assignedBy->name.' assigned you a ticket on '.$this->ticket->site->name.'.')
            ->line('Ticket: #'.$this->ticket->id)
            ->line('Priority: '.ucfirst($this->ticket->priority))
            ->action('Open ticket', route('dashboard.tickets.show', $this->ticket));
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
            'assigned_by_name' => $this->assignedBy->name,
            'url' => route('dashboard.tickets.show', $this->ticket, false),
        ];
    }
}
