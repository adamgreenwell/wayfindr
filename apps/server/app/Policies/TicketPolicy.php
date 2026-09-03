<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        $ticket->loadMissing('site');

        return ! $user->isDeactivated()
            && $user->hasAccountPermission(AccountPermission::ManageTickets)
            && $user->account_id
            && (int) $ticket->account_id === (int) $user->account_id
            && $ticket->site?->supportsAgent($user) === true;
    }

    public function addNote(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)
            && $ticket->conversation_id !== null
            && $user->hasAccountPermission(AccountPermission::ViewConversations)
            && $user->hasAccountPermission(AccountPermission::ReplyToConversations);
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function updateStatus(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)
            && $user->hasAccountPermission(AccountPermission::AssignTickets);
    }
}
