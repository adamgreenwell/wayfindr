<?php

namespace App\Enums;

enum AccountRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Agent = 'agent';

    /** @return list<AccountPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => AccountPermission::cases(),
            self::Admin => AccountPermission::delegable(),
            self::Agent => [
                AccountPermission::ViewConversations,
                AccountPermission::ReplyToConversations,
                AccountPermission::ManageConversations,
                AccountPermission::RequestCobrowse,
                AccountPermission::ManageTickets,
                AccountPermission::AssignTickets,
                AccountPermission::ViewAlerts,
            ],
        };
    }

    public function hasPermission(AccountPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
