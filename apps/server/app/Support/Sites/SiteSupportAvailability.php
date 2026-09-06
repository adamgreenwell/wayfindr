<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Enums\AccountPermission;
use App\Models\Site;
use App\Models\User;

/** Whether somebody eligible to receive a new conversation is online. */
final class SiteSupportAvailability
{
    public function hasOnlineConversationAgent(Site $site): bool
    {
        $hasExplicitRoster = $site->supportAgents()->exists();
        $query = User::query()
            ->where('account_id', $site->account_id)
            ->whereNull('deactivated_at')
            ->where('routing_status', User::ROUTING_STATUS_ONLINE)
            ->with('customRole')
            ->orderBy('id');

        if ($hasExplicitRoster) {
            $query->whereKey($site->eligibleSupportAgents()->pluck('users.id')->all());
        }

        return $query->get()->contains(
            fn (User $agent): bool => $agent->hasAccountPermission(AccountPermission::ViewConversations)
        );
    }
}
