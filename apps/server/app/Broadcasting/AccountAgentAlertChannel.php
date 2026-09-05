<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Enums\AccountPermission;
use App\Models\User;

/**
 * Admit one agent to their alert stream inside their current account.
 *
 * Alert payloads can contain visitor-authored previews. Keeping the agent id
 * in the channel name means account membership alone never exposes another
 * agent's routed alerts, while the account segment makes tenant ownership an
 * explicit part of every authorization decision.
 */
final class AccountAgentAlertChannel
{
    public function join(?User $agent, int|string $accountId, int|string $agentId): bool
    {
        if (! $agent instanceof User || $agent->isDeactivated()) {
            return false;
        }

        if ((! is_int($accountId) && ! ctype_digit((string) $accountId))
            || (! is_int($agentId) && ! ctype_digit((string) $agentId))) {
            return false;
        }

        return (int) $accountId === (int) $agent->account_id
            && (int) $agentId === (int) $agent->id
            && $agent->hasAccountPermission(AccountPermission::ViewAlerts);
    }
}
