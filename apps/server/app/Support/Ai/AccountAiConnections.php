<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Network\AiModelDiscovery;
use App\Support\Ai\Network\AiNetworkException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Account-scoped, locked proposed → tested → active lifecycle. */
final readonly class AccountAiConnections
{
    public function __construct(
        private AgentCopilotConnectionResolver $resolver,
        private AgentCopilotConfiguration $configuration,
        private AccountAiConnectionProbe $probe,
        private AiModelDiscovery $discovery,
    ) {}

    public function propose(User $actor, int $accountId, array $input): AccountAiConnection
    {
        return $this->locked($actor, $accountId, function (AccountAiConnection $record, User $freshActor) use ($input): AccountAiConnection {
            $source = $input['source'] ?? '';

            if (! in_array($source, [AccountAiConnection::INSTALLATION, AccountAiConnection::ACCOUNT], true)) {
                throw ValidationException::withMessages(['source' => 'Choose a connection source.']);
            }

            $name = trim((string) ($input['name'] ?? $record->name));

            if (mb_strlen($name) > 100) {
                throw ValidationException::withMessages(['name' => 'The connection name is too long.']);
            }

            if ($source === AccountAiConnection::INSTALLATION) {
                $installation = $this->resolver->installation((int) $record->account_id);

                if ($installation === null) {
                    throw ValidationException::withMessages(['source' => 'The installation connection is unavailable.']);
                }

                $values = ['source' => $source, 'installation_revision' => $installation->revision];
            } else {
                $values = $this->accountValues($record, $input);
            }

            $proposed = $record->proposedConfiguration();
            $same = ! $record->hasUnreadableConfiguration() && $proposed !== null
                && hash_equals(
                    $this->resolver->fingerprint($proposed, ''),
                    $this->resolver->fingerprint($values, ''),
                );
            $changes = ['name' => $name];

            if (! $same) {
                $changes += [
                    'proposed_revision' => (string) Str::uuid(),
                    'proposed_configuration' => $values,
                    ...$this->clearTest(),
                ];
            }

            $record->forceFill($changes)->save();
            $this->audit($record, $freshActor, 'proposed');

            return $record;
        });
    }

    public function test(User $actor, int $accountId, string $revision): AccountAiConnection
    {
        [$generation, $candidate, $policy] = $this->locked($actor, $accountId, function (AccountAiConnection $record) use ($revision): array {
            $proposed = $this->requireRevision($record, $revision);

            if ($record->test_status === 'running' && $record->test_started_at?->isAfter(now()->subSeconds(60))) {
                throw ValidationException::withMessages(['connection' => 'A connection test is already running.']);
            }

            $candidate = $this->resolver->candidate((int) $record->account_id, $revision, $proposed);

            if ($candidate === null) {
                throw ValidationException::withMessages(['connection' => 'The proposed connection is incomplete or stale.']);
            }

            $generation = (string) Str::uuid();
            $record->forceFill([
                ...$this->clearTest(), 'test_generation' => $generation,
                'test_status' => 'running', 'test_started_at' => now(),
            ])->save();

            return [$generation, $candidate, $this->probe->policyRevision()];
        });

        // Never hold database locks across an external synthetic request. The
        // executor rechecks proposal, actor and generation under usage locks.
        $success = false;
        $failure = 'provider_unavailable';
        try {
            $result = $this->probe->probe($candidate, AiExecutionContext::probe($accountId, (int) $actor->id, $revision, $generation));
            $success = trim($result->text) !== '';
        } catch (Throwable $exception) {
            $failure = $this->failureCode($exception);
        }

        return $this->locked($actor, $accountId, function (AccountAiConnection $record, User $freshActor) use ($revision, $generation, $candidate, $policy, $success, $failure): AccountAiConnection {
            $proposed = $this->requireRevision($record, $revision);
            $current = $this->resolver->candidate((int) $record->account_id, $revision, $proposed);

            if ($record->test_generation !== $generation || $current === null
                || ! hash_equals($current->revision, $candidate->revision)
                || $policy !== $this->probe->policyRevision()) {
                throw ValidationException::withMessages(['connection' => 'The connection changed during its test.']);
            }

            $record->forceFill([
                'tested_revision' => $success ? $revision : null,
                'tested_fingerprint' => $success ? $candidate->revision : null,
                'tested_policy_revision' => $success ? $policy : null,
                'tested_at' => $success ? now() : null,
                'test_status' => $success ? 'ready' : 'unavailable',
                'test_failure_code' => $success ? null : $failure,
                'test_generation' => null,
            ])->save();
            $this->audit($record, $freshActor, $success ? 'tested' : 'test_failed');

            return $record;
        });
    }

    /** First-page suggestions only. Exact manual model entry remains available. */
    public function discoverModels(User $actor, int $accountId, string $revision): ?array
    {
        $candidate = $this->locked($actor, $accountId, function (AccountAiConnection $record) use ($revision): ResolvedAgentCopilotConnection {
            $values = $this->requireRevision($record, $revision);
            $candidate = $this->resolver->candidateForDiscovery((int) $record->account_id, $revision, $values);
            if ($candidate === null) {
                throw ValidationException::withMessages(['connection' => 'Complete the provider, credential and endpoint fields first.']);
            }

            return $candidate;
        });
        $models = $this->discovery->discover($candidate,
            AiExecutionContext::discovery($accountId, (int) $actor->id, $revision, (string) Str::uuid()));

        return $this->locked($actor, $accountId, function (AccountAiConnection $record) use ($revision, $candidate, $models): ?array {
            $values = $this->requireRevision($record, $revision);
            $current = $this->resolver->candidateForDiscovery((int) $record->account_id, $revision, $values);
            if ($current === null || ! hash_equals($current->revision, $candidate->revision)) {
                throw ValidationException::withMessages(['connection' => 'The connection changed during model discovery.']);
            }

            return $models;
        });
    }

    private function failureCode(Throwable $exception): string
    {
        $code = $exception instanceof AiNetworkException ? $exception->reason : '';
        if ($code === 'probe_invalid_output') {
            return 'invalid_output';
        }

        return in_array($code, [
            'key_rejected', 'model_unavailable', 'deadline_exceeded', 'dns_deadline', 'dns_unavailable',
            'destination_denied', 'private_destination_denied', 'redirect_denied', 'endpoint_mismatch',
            'usage_request_limit', 'usage_token_limit', 'usage_concurrency_limit', 'usage_output_limit',
            'usage_retry_cooldown', 'usage_attempt_reused', 'usage_reconciliation_unavailable',
            'usage_policy_unavailable', 'usage_store_unavailable', 'response_too_large', 'invalid_output',
            'connection_changed', 'access_revoked', 'context_changed', 'provider_rate_limited',
        ], true) ? $code : 'provider_unavailable';
    }

    public function activate(User $actor, int $accountId, string $revision): AccountAiConnection
    {
        return $this->locked($actor, $accountId, function (AccountAiConnection $record, User $freshActor) use ($revision): AccountAiConnection {
            $this->requireAffectedSiteAccess($record, $freshActor);
            $proposed = $this->requireRevision($record, $revision);
            $candidate = $this->resolver->candidate((int) $record->account_id, $revision, $proposed);

            if ($candidate === null || $record->tested_revision !== $revision
                || $record->test_status !== 'ready' || $record->tested_at === null
                || $record->tested_policy_revision !== $this->probe->policyRevision()
                || ! hash_equals((string) $record->tested_fingerprint, $candidate->revision)) {
                throw ValidationException::withMessages(['connection' => 'Test this exact connection before saving it.']);
            }

            $record->forceFill([
                'active_revision' => $revision,
                'uses_installation_default' => false,
                'active_configuration' => [
                    ...$proposed,
                    'processing_policy_revision' => $record->tested_policy_revision,
                ],
            ])->save();
            $this->audit($record, $freshActor, 'activated');

            return $record;
        });
    }

    public function setEnabled(User $actor, int $accountId, bool $enabled): AccountAiConnection
    {
        return $this->locked($actor, $accountId, function (AccountAiConnection $record, User $freshActor) use ($enabled): AccountAiConnection {
            if ($enabled) {
                $this->requireAffectedSiteAccess($record, $freshActor);
                if ($record->active_revision === null && ! $record->uses_installation_default) {
                    throw ValidationException::withMessages(['connection' => 'Choose and test a connection before re-enabling.']);
                }
            }
            $record->forceFill(['enabled' => $enabled])->save();
            $this->audit($record, $freshActor, $enabled ? 'enabled' : 'disabled');

            return $record;
        });
    }

    public function disconnect(User $actor, int $accountId): AccountAiConnection
    {
        return $this->locked($actor, $accountId, function (AccountAiConnection $record, User $freshActor): AccountAiConnection {
            $record->forceFill([
                'enabled' => false, 'active_revision' => null, 'active_configuration' => null,
                'uses_installation_default' => false,
                'proposed_revision' => null, 'proposed_configuration' => null,
                ...$this->clearTest(),
            ])->save();
            $this->audit($record, $freshActor, 'disconnected');

            return $record;
        });
    }

    private function locked(User $actor, int $accountId, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $accountId, $operation): mixed {
            $account = Account::query()->whereKey($accountId)->lockForUpdate()->first();
            $freshActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();

            if ($account === null || $freshActor === null || (int) $freshActor->account_id !== $accountId
                || ! $freshActor->hasAccountPermission(AccountPermission::ManageAiConnections)) {
                throw new AuthorizationException;
            }

            $record = AccountAiConnection::query()->where('account_id', $accountId)->lockForUpdate()->first();
            if ($record === null) {
                $record = new AccountAiConnection;
                $record->forceFill(['account_id' => $accountId, 'enabled' => true, 'uses_installation_default' => true])->save();
            }

            return $operation($record, $freshActor);
        });
    }

    private function requireRevision(AccountAiConnection $record, string $revision): array
    {
        $proposed = $record->proposedConfiguration();

        if ($record->proposed_revision !== $revision || $proposed === null) {
            throw ValidationException::withMessages(['connection' => 'The proposed connection has changed.']);
        }

        return $proposed;
    }

    private function requireAffectedSiteAccess(AccountAiConnection $record, User $actor): void
    {
        // Until #1169 introduces narrower per-site enablement, every account
        // site can use the existing manual path and is potentially affected.
        // Site/access writers share the account lock held by locked().
        foreach ($record->account->sites()->get() as $site) {
            if (! $site->supportsAgent($actor)) {
                throw new AuthorizationException('Connection activation requires access to every affected site.');
            }
        }
    }

    private function accountValues(AccountAiConnection $record, array $input): array
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        $unreadable = $record->hasUnreadableConfiguration();
        $submittedKey = trim((string) ($input['api_key'] ?? ''));

        if ($unreadable && $submittedKey === '' && empty($input['clear_api_key'])) {
            throw ValidationException::withMessages(['api_key' => 'The saved credential is unreadable. Replace or explicitly clear it before saving.']);
        }

        $previous = $unreadable ? [] : ($record->proposedConfiguration() ?? $record->activeConfiguration() ?? []);
        $endpoint = rtrim(trim((string) ($input['endpoint'] ?? '')), '/');
        // Changing driver or destination must never forward a saved
        // key to a new processor. Retain it only within the same endpoint.
        $previous = ($previous['source'] ?? null) === AccountAiConnection::ACCOUNT
            && ($previous['provider'] ?? null) === $provider
            && ($previous['endpoint'] ?? '') === $endpoint ? $previous : [];
        $key = ! empty($input['clear_api_key']) ? ''
            : ($submittedKey !== '' ? $submittedKey : (string) ($previous['api_key'] ?? ''));
        $credentialRevision = $key === (string) ($previous['api_key'] ?? '')
            ? ($previous['credential_revision'] ?? (string) Str::uuid()) : (string) Str::uuid();
        $values = [
            'source' => AccountAiConnection::ACCOUNT, 'provider' => $provider,
            'model' => trim((string) ($input['model'] ?? '')),
            'endpoint' => $endpoint,
            'openrouter_provider' => strtolower(trim((string) ($input['openrouter_provider'] ?? ''))),
            'api_key' => $key, 'credential_revision' => $credentialRevision,
        ];
        $assessment = $this->configuration->assessValues(
            $values['provider'], $values['model'], $values['endpoint'], $key !== '', $values['openrouter_provider'],
        );
        $url = $values['endpoint'] === '' ? [] : parse_url($values['endpoint']);

        if (mb_strlen($values['model']) > 255 || mb_strlen($values['endpoint']) > 2048
            || mb_strlen($key) > 8192 || $url === false
            || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($url)) !== []
            || in_array($assessment['status'], ['unset', 'unsupported'], true)
            || in_array('valid_endpoint', $assessment['missing'], true)) {
            throw ValidationException::withMessages(['connection' => 'The proposed connection fields are invalid.']);
        }

        // Incomplete drafts (including clearing a required key) remain editable
        // but cannot pass a test or replace the active connection.
        return $values;
    }

    private function clearTest(): array
    {
        return [
            'tested_revision' => null, 'tested_fingerprint' => null,
            'tested_policy_revision' => null, 'tested_at' => null,
            'test_generation' => null, 'test_status' => 'untested', 'test_started_at' => null, 'test_failure_code' => null,
        ];
    }

    private function audit(AccountAiConnection $record, User $actor, string $event): void
    {
        AuditEvent::query()->create([
            'account_id' => $record->account_id,
            'actor_type' => $actor->getMorphClass(), 'actor_id' => $actor->id,
            'subject_type' => Account::class, 'subject_id' => $record->account_id,
            'action' => 'account.ai_connection.'.$event,
            'metadata' => ['proposed_revision' => $record->proposed_revision, 'active_revision' => $record->active_revision],
            'occurred_at' => now(),
        ]);
    }
}
