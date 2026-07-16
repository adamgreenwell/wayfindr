<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreakGlassGrant>
 */
class BreakGlassGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'scope_type' => BreakGlassGrant::SCOPE_ACCOUNT,
            'conversation_id' => null,
            'site_id' => null,
            'requester_id' => User::factory()->state(['platform_role' => 'operator']),
            'reason' => 'Investigating a reported data issue.',
            'status' => BreakGlassGrant::STATUS_REQUESTED,
            'self_approved' => false,
            'requested_minutes' => BreakGlassGrant::DEFAULT_MINUTES,
        ];
    }

    public function activeFor(Account $account, User $requester, int $minutes = BreakGlassGrant::DEFAULT_MINUTES): static
    {
        return $this->state([
            'account_id' => $account->id,
            'requester_id' => $requester->id,
            'status' => BreakGlassGrant::STATUS_ACTIVE,
            'approver_id' => $requester->id,
            'self_approved' => true,
            'requested_minutes' => $minutes,
            'approved_at' => now(),
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    public function scopedToConversation(Conversation $conversation): static
    {
        $conversation->loadMissing('site');

        return $this->state([
            'account_id' => $conversation->site?->account_id,
            'scope_type' => BreakGlassGrant::SCOPE_CONVERSATION,
            'conversation_id' => $conversation->id,
            'site_id' => $conversation->site_id,
        ]);
    }

    public function scopedToSite(Site $site): static
    {
        return $this->state([
            'account_id' => $site->account_id,
            'scope_type' => BreakGlassGrant::SCOPE_SITE,
            'conversation_id' => null,
            'site_id' => $site->id,
        ]);
    }
}
