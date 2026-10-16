<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AccountPermission;
use App\Models\AccountAiConnection;
use App\Models\User;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use App\Support\ReaderClock;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/** Explicit browser allowlist: encrypted snapshots and resolved secrets stay here. */
final readonly class AccountAiConnectionPresenter
{
    public static function failureCode(?string $reason): string
    {
        return match ($reason) {
            'dns_deadline', 'deadline_exceeded' => 'deadline_exceeded',
            'private_destination_denied', 'endpoint_mismatch', 'redirect_denied', 'destination_denied' => 'destination_denied',
            'access_revoked', 'context_changed', 'connection_changed' => 'connection_changed',
            'key_rejected', 'model_unavailable', 'usage_request_limit', 'usage_token_limit', 'usage_concurrency_limit',
            'usage_output_limit', 'usage_retry_cooldown', 'provider_rate_limited', 'invalid_output',
            'usage_policy_unavailable', 'usage_store_unavailable' => $reason,
            default => 'provider_unavailable',
        };
    }

    public function __construct(private AgentCopilotConnectionResolver $connections, private AccountAiConnectionProbe $probe,
        private AiUsagePolicy $limits, private AiUsageLedger $usage) {}

    public function present(User $actor): array
    {
        $actor = User::query()->find($actor->id);
        if ($actor === null || ! $actor->account_id || $actor->isDeactivated()
            || ! $actor->hasAccountPermission(AccountPermission::ManageAiConnections)) {
            throw new AuthorizationException;
        }
        $accountId = (int) $actor->account_id;
        $record = AccountAiConnection::query()->where('account_id', $accountId)->first();
        $proposed = $record?->proposedConfiguration();
        $active = $record?->activeConfiguration();
        $installation = $this->connections->installation($accountId);
        $current = $this->connections->forAccount($accountId);
        $values = $proposed ?? $active ?? [];
        $source = $values['source'] ?? ($record !== null && ! $record->uses_installation_default ? ''
            : ($installation === null ? AccountAiConnection::ACCOUNT : AccountAiConnection::INSTALLATION));
        $own = $source === AccountAiConnection::ACCOUNT;
        $installationSource = $source === AccountAiConnection::INSTALLATION;
        $credential = $record?->credentialStatus() ?? ($own ? 'missing' : 'installation');
        if ($proposed === null && $active !== null && ! $record->hasUnreadableConfiguration()) {
            $credential = $own ? (trim((string) ($active['api_key'] ?? '')) === '' ? 'missing' : 'saved') : 'installation';
        }
        try {
            $candidate = $proposed === null ? null : $this->connections->candidate($accountId, $record->proposed_revision, $proposed);
            $canSave = $candidate !== null && $record->test_status === 'ready'
                && $record->tested_revision === $record->proposed_revision && $record->tested_at !== null
                && $record->tested_policy_revision === $this->probe->policyRevision()
                && hash_equals((string) $record->tested_fingerprint, $candidate->revision);
        } catch (Throwable) {
            $canSave = false;
        }
        $preparing = $record?->test_status === 'running' && $record->test_started_at?->isAfter(now()->subSeconds(60));
        $state = match (true) {
            $credential === 'unreadable' => 'unavailable',
            $preparing => 'preparing',
            $canSave => 'ready',
            in_array($record?->test_status, ['unavailable', 'running'], true) => 'unavailable',
            $proposed !== null && ($record->active_revision !== null || $record->tested_revision !== null) => 'stale',
            default => 'untested',
        };
        try {
            $limits = $this->limits->accountSettings($accountId);
        } catch (Throwable) {
            $limits = ['unavailable' => true, 'overrides' => [], 'installation' => [], 'effective' => []];
        }
        try {
            $summary = $this->usage->summary($accountId);
            $usage = ['period_day' => $summary['period_day'], 'account' => $summary['account'], 'observed_tokens' => $summary['observed_tokens']];
        } catch (Throwable) {
            $usage = null;
        }

        return [
            'form' => [
                'source' => $source, 'name' => $record?->name ?? '',
                'provider' => $own ? (string) ($values['provider'] ?? '') : ($installationSource ? ($installation?->provider ?? '') : ''),
                'model' => $own ? (string) ($values['model'] ?? '') : ($installationSource ? ($installation?->model ?? '') : ''),
                'endpoint' => $own ? (string) ($values['endpoint'] ?? '') : '',
                'openrouter_provider' => $own ? (string) ($values['openrouter_provider'] ?? '') : '',
                'expected_revision' => $record?->proposed_revision, 'credential_status' => $credential,
                'editor_token' => AccountAiConnections::editorToken($accountId, $record),
            ],
            'state' => $state,
            'test' => [
                'tested_revision' => $record?->tested_revision,
                'tested_at' => $record?->tested_at === null ? null : ReaderClock::dateTime($record->tested_at),
                'failure_code' => $state === 'unavailable' ? self::failureCode($record?->test_failure_code) : null, 'can_save' => $canSave,
            ],
            'active' => [
                'configured' => $record?->active_revision !== null || ($record === null || $record->uses_installation_default) && $installation !== null,
                'enabled' => $record?->enabled ?? true, 'available' => $current !== null,
                'legacy' => $record?->active_revision === null && ($record === null || $record->uses_installation_default),
                'source' => $active['source'] ?? ($current?->source),
                'provider' => $active['provider'] ?? $current?->provider, 'model' => $active['model'] ?? $current?->model,
            ],
            'installation' => ['available' => $installation !== null, 'provider' => $installation?->provider, 'model' => $installation?->model],
            'providers' => AgentCopilotConfiguration::PROVIDERS,
            'limits' => $limits, 'usage' => $usage, 'usage_unavailable' => $usage === null, 'models' => [],
        ];
    }
}
