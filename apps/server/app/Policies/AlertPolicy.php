<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Conversation;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\SlaDeadlineAlert;
use App\Notifications\TicketAssigned;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

class AlertPolicy
{
    public function view(User $user, DatabaseNotification $notification): bool
    {
        return ! $user->isDeactivated()
            && $user->hasAccountPermission(AccountPermission::ViewAlerts)
            && $this->belongsTo($user, $notification)
            && $this->supportAlertVisibleTo($user, $notification);
    }

    public function markRead(User $user, DatabaseNotification $notification): bool
    {
        return $this->view($user, $notification);
    }

    private function belongsTo(User $user, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_type === $user->getMorphClass()
            && (string) $notification->notifiable_id === (string) $user->getKey();
    }

    private function supportAlertVisibleTo(User $user, DatabaseNotification $notification): bool
    {
        if ($notification->type === ConversationNeedsReply::class) {
            $conversationId = (int) data_get($notification->data, 'conversation_id');
            $conversation = $conversationId > 0
                ? Conversation::query()->with('site')->find($conversationId)
                : null;

            return $conversation
                && Gate::forUser($user)->allows('view', $conversation);
        }

        if ($notification->type === TicketAssigned::class) {
            $ticketId = (int) data_get($notification->data, 'ticket_id');
            $ticket = $ticketId > 0
                ? Ticket::query()->with('site')->find($ticketId)
                : null;

            return $ticket
                && Gate::forUser($user)->allows('view', $ticket);
        }

        if ($notification->type === SlaDeadlineAlert::class) {
            $clockId = (int) data_get($notification->data, 'sla_clock_id');
            $clock = $clockId > 0
                ? SlaClock::query()->with('subject.site')->find($clockId)
                : null;

            return $clock?->subject
                && Gate::forUser($user)->allows('view', $clock->subject);
        }

        if ($notification->type === AutomationRuleMatched::class) {
            $subjectId = (int) data_get($notification->data, 'subject_id');
            $subject = match (data_get($notification->data, 'subject_kind')) {
                'ticket' => Ticket::query()->with('site')->find($subjectId),
                'conversation' => Conversation::query()->with('site')->find($subjectId),
                default => null,
            };

            return $subject
                && Gate::forUser($user)->allows('view', $subject);
        }

        return false;
    }
}
