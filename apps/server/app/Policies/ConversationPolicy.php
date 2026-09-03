<?php

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return ! $user->isDeactivated()
            && $user->hasAccountPermission(AccountPermission::ViewConversations)
            && $conversation->site?->supportsAgent($user) === true;
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::ReplyToConversations);
    }

    public function updateStatus(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::ManageConversations);
    }

    public function claim(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::ManageConversations)
            && (! $conversation->assigned_agent_id || (int) $conversation->assigned_agent_id === (int) $user->id);
    }

    public function release(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::ManageConversations)
            && (int) $conversation->assigned_agent_id === (int) $user->id;
    }

    public function createTicket(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::ManageTickets);
    }

    public function requestCobrowse(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::RequestCobrowse);
    }

    public function endCobrowse(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation)
            && $user->hasAccountPermission(AccountPermission::RequestCobrowse);
    }
}
