<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountPermission: string
{
    case ManageAgents = 'manage_agents';
    case ManageRoles = 'manage_roles';
    case ManageSites = 'manage_sites';
    case ManageSiteAccess = 'manage_site_access';
    case ManagePrivacySettings = 'manage_privacy_settings';
    case ManageIntegrations = 'manage_integrations';
    case ManageKnowledge = 'manage_knowledge';
    case ManageSecurity = 'manage_security';
    case ManageOperatorAccess = 'manage_operator_access';
    case ViewReports = 'view_reports';
    case ViewAudit = 'view_audit';
    case ViewConversations = 'view_conversations';
    case ReplyToConversations = 'reply_to_conversations';
    case ManageConversations = 'manage_conversations';
    case RequestCobrowse = 'request_cobrowse';
    case ManageTickets = 'manage_tickets';
    case AssignTickets = 'assign_tickets';
    case ManageAutomations = 'manage_automations';
    case ViewAlerts = 'view_alerts';

    /** @return list<self> */
    public static function delegable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $permission): bool => $permission !== self::ManageRoles,
        ));
    }

    /** @return list<string> */
    public static function delegableValues(): array
    {
        return array_map(
            fn (self $permission): string => $permission->value,
            self::delegable(),
        );
    }
}
