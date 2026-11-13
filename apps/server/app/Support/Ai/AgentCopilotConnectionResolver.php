<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Settings\OperatorSettings;
use SensitiveParameter;
use Throwable;

/** Resolve afresh for each boundary. Never change installation-global config. */
final readonly class AgentCopilotConnectionResolver
{
    public function __construct(
        private OperatorSettings $settings,
        private AgentCopilotConfiguration $configuration,
        private AccountAiConnectionProbe $probe,
    ) {}

    public function forAccount(int $accountId): ?ResolvedAgentCopilotConnection
    {
        try {
            if (! Account::query()->whereKey($accountId)->exists()) {
                return null;
            }

            $record = AccountAiConnection::query()->where('account_id', $accountId)->first();

            if ($record !== null && ! $record->enabled) {
                return null;
            }

            // Drafting the first account connection must not change the active
            // manual installation path. There is no automatic preparation here.
            if ($record?->active_revision === null) {
                return $record === null || $record->uses_installation_default
                    ? $this->installation($accountId) : null;
            }

            $values = $record->activeConfiguration();

            if (! is_array($values)
                || ($values['processing_policy_revision'] ?? null) !== $this->probe->policyRevision()) {
                return null;
            }

            return $this->candidate($accountId, $record->active_revision, $values);
        } catch (Throwable) {
            // Neither an unreadable encrypted value nor a failed store read
            // may cause a fallback to a different processor or credential.
            return null;
        }
    }

    public function isCurrent(int $accountId, ?string $revision): bool
    {
        if ($revision === null || $revision === '') {
            return false;
        }

        $current = $this->forAccount($accountId);

        return $current !== null && hash_equals($current->revision, $revision);
    }

    /** Only the pre-existing unselected operator path retains legacy SDK I/O. */
    public function isLegacyInstallationDefault(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection): bool
    {
        if ($connection->source !== AccountAiConnection::INSTALLATION || ! Account::query()->whereKey($connection->accountId)->exists()
            || app(AiAssistPolicyResolver::class)->hasExplicitAccountPolicy($connection->accountId)) {
            return false;
        }
        $record = AccountAiConnection::query()->where('account_id', $connection->accountId)->first();
        if ($record !== null && (! $record->enabled || $record->active_revision !== null || ! $record->uses_installation_default)) {
            return false;
        }
        $current = $this->installation($connection->accountId);

        return $current !== null && hash_equals($current->revision, $connection->revision);
    }

    /** Discovery may precede model selection; every other required field remains valid. */
    public function candidateForDiscovery(int $accountId, #[SensitiveParameter] string $revision, #[SensitiveParameter] array $values): ?ResolvedAgentCopilotConnection
    {
        if (($values['source'] ?? null) !== AccountAiConnection::ACCOUNT || trim((string) ($values['model'] ?? '')) !== '') {
            return $this->candidate($accountId, $revision, $values);
        }
        $assessment = $this->configuration->assessValues((string) ($values['provider'] ?? ''), 'discovery-placeholder',
            (string) ($values['endpoint'] ?? ''), (string) ($values['api_key'] ?? '') !== '', (string) ($values['openrouter_provider'] ?? ''));
        if ($assessment['status'] !== 'ready') {
            return null;
        }

        return new ResolvedAgentCopilotConnection($accountId, AccountAiConnection::ACCOUNT,
            $this->fingerprint($values, $revision.':'.$this->probe->policyRevision()),
            $assessment['provider'], '', $assessment['endpoint'], $assessment['openrouter_provider'], (string) ($values['api_key'] ?? ''));
    }

    public function isCurrentConnection(ResolvedAgentCopilotConnection $connection): bool
    {
        $current = $this->forAccount($connection->accountId);

        return $current !== null
            && $current->source === $connection->source
            && hash_equals($current->revision, $connection->revision);
    }

    public function installation(int $accountId): ?ResolvedAgentCopilotConnection
    {
        try {
            $values = $this->settings->freshValuesForGroup('ai');
            $provider = strtolower(trim((string) $values['ai.provider']));
            $model = trim((string) $values['ai.model']);
            $endpoint = trim((string) $values['ai.endpoint']);
            $upstream = strtolower(trim((string) $values['ai.openrouter_provider']));
            $key = trim((string) $values['ai.api_key']);
            $assessment = $this->configuration->assessValues($provider, $model, $endpoint, $key !== '', $upstream);

            if ($assessment['status'] !== 'ready') {
                return null;
            }

            $revision = $this->fingerprint([
                'source' => AccountAiConnection::INSTALLATION,
                'provider' => $provider,
                'model' => $model,
                'endpoint' => $endpoint,
                'openrouter_provider' => $upstream,
                'api_key' => $key,
            ], 'installation-v1:'.$accountId);

            return new ResolvedAgentCopilotConnection(
                $accountId, AccountAiConnection::INSTALLATION, $revision,
                $provider, $model, $endpoint, $upstream, $key,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Construct a proposed/active immutable context. Installation references
     * contain its revision, never a copied operator credential.
     *
     * @param  array<string, mixed>  $values
     */
    public function candidate(int $accountId, string $revision, array $values): ?ResolvedAgentCopilotConnection
    {
        $source = $values['source'] ?? null;

        if ($source === AccountAiConnection::INSTALLATION) {
            $installation = $this->installation($accountId);

            if ($installation === null || ($values['installation_revision'] ?? null) !== $installation->revision) {
                return null;
            }

            return new ResolvedAgentCopilotConnection(
                $accountId, $source,
                $this->fingerprint($values, $revision.':'.$this->probe->policyRevision()),
                $installation->provider, $installation->model, $installation->endpoint,
                $installation->openRouterProvider, $installation->apiKey(),
            );
        }

        if ($source !== AccountAiConnection::ACCOUNT) {
            return null;
        }

        $assessment = $this->configuration->assessValues(
            (string) ($values['provider'] ?? ''), (string) ($values['model'] ?? ''),
            (string) ($values['endpoint'] ?? ''), (string) ($values['api_key'] ?? '') !== '',
            (string) ($values['openrouter_provider'] ?? ''),
        );

        if ($assessment['status'] !== 'ready') {
            return null;
        }

        return new ResolvedAgentCopilotConnection(
            $accountId, $source,
            $this->fingerprint($values, $revision.':'.$this->probe->policyRevision()),
            $assessment['provider'], $assessment['model'], $assessment['endpoint'],
            $assessment['openrouter_provider'], (string) ($values['api_key'] ?? ''),
        );
    }

    /** Opaque keyed identity; never store a plain hash of a credential. */
    public function fingerprint(array $values, string $policyRevision): string
    {
        $identity = [];

        foreach (['source', 'provider', 'model', 'endpoint', 'openrouter_provider', 'api_key', 'credential_revision', 'installation_revision'] as $key) {
            $identity[$key] = $values[$key] ?? '';
        }

        $identity['policy'] = $policyRevision;
        $identity['context_limit'] = (int) config('wayfindr.ai.max_context_characters', 30_000);

        return hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
