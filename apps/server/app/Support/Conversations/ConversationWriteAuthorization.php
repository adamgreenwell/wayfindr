<?php

namespace App\Support\Conversations;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Support\Facades\Gate;

final class ConversationWriteAuthorization
{
    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
    ) {}

    /**
     * Refresh and authorize a conversation actor under the account-first lock.
     *
     * Call inside the transaction that performs the write. Role edits, site
     * access edits, and conversation mutations then serialize in one stable
     * order, so a request cannot keep using permissions cached before it began.
     *
     * @return array{0: User, 1: Conversation}
     */
    public function lock(User $agent, Conversation $conversation, string $ability): array
    {
        $site = $conversation->site()->firstOrFail();
        $accountId = (int) $site->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $agent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->firstOrFail();
        $conversation = Conversation::query()
            ->with('site')
            ->whereKey($conversation->id)
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows($ability, $conversation), 404);

        return [$agent, $conversation];
    }
}
