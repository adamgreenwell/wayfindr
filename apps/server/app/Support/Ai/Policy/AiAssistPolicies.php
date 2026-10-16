<?php

declare(strict_types=1);

namespace App\Support\Ai\Policy;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\AccountAiConnection;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\SiteAiAssistPolicy;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Execution\AiSupportUsage;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Account → fresh actor → sites. Usage cancellation runs only after commit. */
final readonly class AiAssistPolicies
{
    public function __construct(private AiAssistPolicyResolver $resolver, private AgentCopilotConnectionResolver $connections) {}

    public function accountToken(int $accountId): string
    {
        return $this->token(['account', $accountId, AccountAiAssistPolicy::query()->find($accountId)?->revision]);
    }

    public function siteToken(int $siteId): string
    {
        $site = Site::query()->findOrFail($siteId);

        return $this->token(['site', (int) $site->account_id, $siteId,
            AccountAiAssistPolicy::query()->find($site->account_id)?->revision,
            SiteAiAssistPolicy::query()->where('account_id', $site->account_id)->find($siteId)?->revision]);
    }

    public function saveAccount(User $actor, int $accountId, array $input, string $policyToken): AccountAiAssistPolicy
    {
        return $this->locked($actor, $accountId, function (User $fresh) use ($accountId, $input, $policyToken): AccountAiAssistPolicy {
            $this->requireToken($this->accountToken($accountId), $policyToken, 'policy_token');
            $current = AccountAiAssistPolicy::query()->find($accountId);
            $values = $this->values($input, $this->resolver->installationContextLimit());
            $candidate = new AccountAiAssistPolicy;
            $candidate->forceFill(['account_id' => $accountId, 'revision' => (string) Str::uuid(), ...$values]);
            $candidate->syncOriginal();
            $sites = Site::query()->where('account_id', $accountId)->orderBy('id')->lockForUpdate()->get();
            $enabled = false;
            foreach ($sites as $site) {
                $sitePolicy = SiteAiAssistPolicy::query()->where('account_id', $accountId)->find($site->id);
                if ($current === null) {
                    $sitePolicy = $this->initialSite((int) $site->id, $accountId, $values);
                }
                $next = $this->resolver->resolve($accountId, (int) $site->id, $candidate, $sitePolicy);
                $wasEnabled = $this->resolver->forSite((int) $site->id)->isEnabled();
                if (($current === null || $wasEnabled || $next->isEnabled()) && ! $site->supportsAgent($fresh)) {
                    throw new AuthorizationException;
                }
                $enabled = $enabled || $next->isEnabled();
            }
            if ($enabled) {
                $this->requireConnection($accountId);
            }
            if ($current !== null && $this->same($current, $values)) {
                return $current;
            }
            if ($current === null) {
                foreach ($sites as $site) {
                    $this->initialSite((int) $site->id, $accountId, $values)->save();
                }
            }
            $record = $current ?? new AccountAiAssistPolicy;
            $record->forceFill(['account_id' => $accountId, 'revision' => (string) Str::uuid(), ...$values])->save();
            $this->changed($fresh, $accountId, 'account', [$record->revision]);

            return $record;
        });
    }

    public function saveSites(User $actor, int $accountId, array $siteIds, array $input, string $accountToken, array $siteTokens): void
    {
        $this->locked($actor, $accountId, function (User $fresh) use ($accountId, $siteIds, $input, $accountToken, $siteTokens): void {
            $this->requireToken($this->accountToken($accountId), $accountToken, 'account_policy_token');
            $account = AccountAiAssistPolicy::query()->find($accountId);
            if ($account === null) {
                throw ValidationException::withMessages(['policy_token' => 'Save the account policy first.']);
            }
            $ids = [];
            foreach ($siteIds as $id) {
                if (! (is_int($id) || is_string($id) && preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1) || (int) $id < 1) {
                    throw ValidationException::withMessages(['site_ids' => 'Choose accessible sites.']);
                }
                $ids[] = (int) $id;
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            if ($ids === [] || count($ids) > 1000) {
                throw ValidationException::withMessages(['site_ids' => 'Choose accessible sites.']);
            }
            $sites = Site::query()->where('account_id', $accountId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($sites->count() !== count($ids)) {
                throw new AuthorizationException;
            }
            $values = $this->values($input, min($this->resolver->installationContextLimit(), $account->max_context_characters ?? PHP_INT_MAX));
            $changes = [];
            foreach ($sites as $site) {
                if (! $site->supportsAgent($fresh)) {
                    throw new AuthorizationException;
                }
                $this->requireToken($this->siteToken((int) $site->id), $siteTokens[$site->id] ?? null, 'site_tokens');
                $current = SiteAiAssistPolicy::query()->where('account_id', $accountId)->find($site->id);
                $candidate = new SiteAiAssistPolicy;
                $candidate->forceFill(['site_id' => $site->id, 'account_id' => $accountId, 'revision' => (string) Str::uuid(), ...$values]);
                $candidate->syncOriginal();
                if ($this->resolver->resolve($accountId, (int) $site->id, $account, $candidate)->isEnabled()) {
                    $this->requireConnection($accountId);
                }
                if ($current === null || ! $this->same($current, $values)) {
                    $changes[] = [$current ?? new SiteAiAssistPolicy, (int) $site->id];
                }
            }
            // Validation and authority for every selected site precede every write.
            $revisions = [];
            foreach ($changes as [$record, $siteId]) {
                $record->forceFill(['site_id' => $siteId, 'account_id' => $accountId, 'revision' => (string) Str::uuid(), ...$values])->save();
                $revisions[] = $record->revision;
            }
            if ($revisions !== []) {
                $this->changed($fresh, $accountId, 'sites', $revisions);
            }
        });
    }

    public function connectionReady(int $accountId): bool
    {
        $connection = AccountAiConnection::query()->where('account_id', $accountId)->first();

        return $connection !== null && $connection->active_revision !== null && $connection->enabled
            && $this->connections->forAccount($accountId) !== null;
    }

    public function present(User $actor): array
    {
        $actor = User::query()->find($actor->id);
        $this->requireActor($actor, (int) $actor?->account_id);
        $accountId = (int) $actor->account_id;
        $account = AccountAiAssistPolicy::query()->find($accountId);
        $canUpdateAccount = true;
        $rows = [];
        foreach (Site::query()->where('account_id', $accountId)->orderBy('id')->get() as $site) {
            $effective = $this->resolver->forSite((int) $site->id);
            if (! $site->supportsAgent($actor)) {
                // A first opt-in affects every existing legacy site. Later
                // account edits must cover currently enabled sites too.
                if ($account === null || $effective->isEnabled()) {
                    $canUpdateAccount = false;
                }

                continue;
            }
            $policy = SiteAiAssistPolicy::query()->where('account_id', $accountId)->find($site->id);
            $rows[] = ['site_id' => (int) $site->id, 'name' => $site->name, 'token' => $this->siteToken((int) $site->id),
                ...$this->form($policy, $account === null ? 'manual' : 'off'),
                'effective_mode' => $effective->mode, 'article_context_allowed' => $effective->articleContextAllowed];
        }

        return [
            'explicit' => $account !== null, 'account_token' => $this->accountToken($accountId),
            'can_update_account' => $canUpdateAccount, 'connection_ready' => $this->connectionReady($accountId),
            'installation_context_limit' => $this->resolver->installationContextLimit(),
            'account' => $this->form($account, 'manual'), 'sites' => $rows,
        ];
    }

    private function form(AccountAiAssistPolicy|SiteAiAssistPolicy|null $record, string $defaultMode): array
    {
        $values = ['mode' => $record?->mode ?? $defaultMode, 'max_context_characters' => $record?->max_context_characters,
            'article_context_consent' => $record?->article_context_consent ?? false];
        foreach (AiAssistPolicyResolver::TASKS as $task) {
            $values[$task] = $record?->{$task} ?? ($task !== 'relevant_articles');
        }

        return $values;
    }

    private function initialSite(int $siteId, int $accountId, array $values): SiteAiAssistPolicy
    {
        $record = new SiteAiAssistPolicy;
        $record->forceFill(['site_id' => $siteId, 'account_id' => $accountId, 'revision' => (string) Str::uuid(),
            'mode' => 'manual', 'summary' => true, 'reply_draft' => true, 'ticket_details' => true,
            'relevant_articles' => $values['relevant_articles'], 'article_context_consent' => $values['article_context_consent'],
            'max_context_characters' => null]);
        $record->syncOriginal();

        return $record;
    }

    private function values(array $input, int $ceiling): array
    {
        if (! in_array($input['mode'] ?? null, ['off', 'manual', 'prepare'], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose an Assist mode.']);
        }
        $values = ['mode' => $input['mode']];
        foreach ([...AiAssistPolicyResolver::TASKS, 'article_context_consent'] as $task) {
            if (! in_array($input[$task] ?? false, [true, false, 0, 1, '0', '1'], true)) {
                throw ValidationException::withMessages([$task => 'Choose a valid policy.']);
            }
            $values[$task] = in_array($input[$task] ?? false, [true, 1, '1'], true);
        }
        if ($values['relevant_articles'] && ! $values['article_context_consent']) {
            throw ValidationException::withMessages(['article_context_consent' => 'Acknowledge shared article context.']);
        }
        if (! $values['relevant_articles']) {
            $values['article_context_consent'] = false;
        }
        $limit = $input['max_context_characters'] ?? null;
        if ($limit === '') {
            $limit = null;
        }
        if ($limit !== null && (! (is_int($limit) || is_string($limit) && preg_match('/\A[0-9]{1,7}\z/', $limit) === 1)
            || (int) $limit < 1000 || (int) $limit > $ceiling)) {
            throw ValidationException::withMessages(['max_context_characters' => 'The context limit may only narrow its parent.']);
        }
        $values['max_context_characters'] = $limit === null ? null : (int) $limit;

        return $values;
    }

    private function same(AccountAiAssistPolicy|SiteAiAssistPolicy $record, array $values): bool
    {
        foreach ($values as $key => $value) {
            if ($record->{$key} !== $value) {
                return false;
            }
        }

        return true;
    }

    private function requireConnection(int $accountId): void
    {
        if (! $this->connectionReady($accountId)) {
            throw ValidationException::withMessages(['connection_required' => 'Save a tested connection before enabling Assist.']);
        }
    }

    private function requireToken(string $current, mixed $posted, string $field): void
    {
        if (! is_string($posted) || ! hash_equals($current, $posted)) {
            throw ValidationException::withMessages([$field => 'The policy changed. Reload before editing.']);
        }
    }

    private function token(array $values): string
    {
        return hash_hmac('sha256', json_encode(['assist-policy-editor-v1', ...$values], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function requireActor(?User $actor, int $accountId): void
    {
        if ($actor === null || $accountId < 1 || (int) $actor->account_id !== $accountId || $actor->isDeactivated()
            || ! $actor->hasAccountPermission(AccountPermission::ManageAiConnections)) {
            throw new AuthorizationException;
        }
    }

    private function locked(User $actor, int $accountId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $accountId, $operation): mixed {
            $account = Account::query()->whereKey($accountId)->lockForUpdate()->first();
            $fresh = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            $this->requireActor($fresh, $accountId);
            if ($account === null) {
                throw new AuthorizationException;
            }

            return $operation($fresh);
        });
    }

    private function changed(User $actor, int $accountId, string $scope, array $revisions): void
    {
        AuditEvent::query()->create(['account_id' => $accountId, 'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id,
            'subject_type' => Account::class, 'subject_id' => $accountId, 'action' => 'account.ai_assist_policy.'.$scope,
            'metadata' => ['scope' => $scope, 'revisions' => $revisions], 'occurred_at' => now()]);
        DB::afterCommit(fn () => app(AiSupportUsage::class)->cancelPolicyReservations($accountId));
    }
}
