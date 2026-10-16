<?php

declare(strict_types=1);

namespace App\Support\Ai\Policy;

use App\Models\AccountAiAssistPolicy;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Every boundary reads current policy. No cached model relations establish authority. */
final class AiAssistPolicyResolver
{
    public const TASKS = ['summary', 'reply_draft', 'relevant_articles', 'ticket_details'];

    public function hasExplicitAccountPolicy(int $accountId): bool
    {
        return AccountAiAssistPolicy::query()->whereKey($accountId)->exists();
    }

    public function forConversation(Conversation $conversation): ResolvedAiAssistPolicy
    {
        return $this->forSite((int) $conversation->site_id);
    }

    public function forSite(int $siteId): ResolvedAiAssistPolicy
    {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return $this->resolve(0, $siteId, null, null, false);
        }

        return $this->resolve((int) $site->account_id, $siteId,
            AccountAiAssistPolicy::query()->find($site->account_id),
            SiteAiAssistPolicy::query()->where('account_id', $site->account_id)->find($siteId));
    }

    public function isCurrent(int $siteId, string $purpose, ?string $revision): bool
    {
        $current = $this->forSite($siteId);
        if (! $current->allows($purpose)) {
            return false;
        }
        // Upgrade compatibility applies only before deliberate account opt-in.
        if ($revision === null) {
            return ! $current->explicit && $current->accountId > 0 && $current->mode === 'manual';
        }

        return hash_equals($current->revisionFor($purpose), $revision);
    }

    /** Account lock held by lifecycle writers stabilizes site/policy membership. */
    public function enabledSitesForAccount(int $accountId): Collection
    {
        return Site::query()->where('account_id', $accountId)->orderBy('id')->get()
            ->filter(fn (Site $site): bool => $this->forSite((int) $site->id)->isEnabled())->values();
    }

    public function installationContextLimit(): int
    {
        $value = config('wayfindr.ai.max_context_characters', 30000);

        return is_int($value) && $value >= 1000 && $value <= 1000000 ? $value : 1000;
    }

    public function resolve(int $accountId, int $siteId, ?AccountAiAssistPolicy $account, ?SiteAiAssistPolicy $site, bool $exists = true): ResolvedAiAssistPolicy
    {
        $limit = $this->installationContextLimit();
        $explicit = $account !== null;
        $legacy = ! $explicit && $exists;
        $valid = $legacy || $this->valid($account) && ($site === null || $this->valid($site));
        $mode = $legacy ? 'manual' : ($valid && $site !== null
            ? (in_array('off', [$account->mode, $site->mode], true) ? 'off'
                : ($account->mode === 'manual' || $site->mode === 'manual' ? 'manual' : 'prepare')) : 'off');
        $tasks = [];
        foreach (self::TASKS as $task) {
            $tasks[$task] = $legacy || $valid && $site !== null && $account->{$task} && $site->{$task};
        }
        if ($valid && ! $legacy) {
            $limit = min($limit, $account->max_context_characters ?? $limit, $site?->max_context_characters ?? $limit);
        }
        $articles = ! $legacy && $mode !== 'off' && $tasks['relevant_articles']
            && $account->article_context_consent && $site->article_context_consent;
        $revision = hash_hmac('sha256', json_encode([
            'assist-policy-v1', $accountId, $siteId, $account?->revision, $site?->revision,
            $explicit, $valid, $mode, $tasks, $limit, $articles,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));

        return new ResolvedAiAssistPolicy($accountId, $siteId, $explicit, $mode,
            $tasks['summary'], $tasks['reply_draft'], $tasks['relevant_articles'], $tasks['ticket_details'],
            $limit, $articles, $revision);
    }

    private function valid(AccountAiAssistPolicy|SiteAiAssistPolicy|null $policy): bool
    {
        if ($policy === null || ! Str::isUuid($policy->revision) || ! in_array($policy->mode, ['off', 'manual', 'prepare'], true)) {
            return false;
        }
        foreach ([...self::TASKS, 'article_context_consent'] as $task) {
            if (! in_array($policy->getRawOriginal($task), [true, false, 0, 1, '0', '1'], true)) {
                return false;
            }
        }
        $value = $policy->getRawOriginal('max_context_characters');

        return $value === null || (is_int($value) || is_string($value) && ctype_digit($value))
            && (int) $value >= 1000 && (int) $value <= 1000000;
    }
}
