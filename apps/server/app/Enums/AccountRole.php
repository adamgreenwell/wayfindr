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

    /**
     * The built-in roles' display names, keyed by stored value.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Owner->value => __('profile.roles.owner'),
            self::Admin->value => __('profile.roles.admin'),
            self::Agent->value => __('profile.roles.agent'),
        ];
    }
}
