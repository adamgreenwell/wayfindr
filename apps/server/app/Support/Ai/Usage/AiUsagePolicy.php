<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

/** Reads ceilings afresh; the ledger owns installation → account usage locking. */
final readonly class AiUsagePolicy
{
    private const CAPS = [
        'daily_requests' => 1_000_000,
        'daily_tokens' => 1_000_000_000,
        'concurrency' => 1_000,
        'max_output_tokens' => 32_768,
    ];

    private const DURATIONS = [
        'queue_ttl_seconds' => [300, 1, 86_400],
        // Both must outlive the guarded transport's hard 20-second deadline.
        'claim_ttl_seconds' => [30, 21, 300],
        'dispatch_ttl_seconds' => [30, 21, 300],
        'retry_cooldown_seconds' => [60, 60, 86_400],
    ];

    public function __construct(private Repository $config) {}

    public function forConnection(#[SensitiveParameter] ResolvedAgentCopilotConnection $connection): AiUsageLimits
    {
        return $this->forAccount($connection->accountId);
    }

    /**
     * Partial account-owned patch; explicit null restores inheritance. This
     * never takes the usage mutex or changes connection/source/enablement.
     */
    public function setAccountLimits(User $actor, int $accountId, array $overrides): AiUsageLimits
    {
        try {
            return DB::transaction(function () use ($actor, $accountId, $overrides): AiUsageLimits {
                $account = Account::query()->whereKey($accountId)->lockForUpdate()->first();
                $freshActor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if ($account === null || $freshActor === null || (int) $freshActor->account_id !== $accountId
                    || ! $freshActor->hasAccountPermission(AccountPermission::ManageAiConnections)) {
                    throw new AuthorizationException;
                }
                if (array_diff(array_keys($overrides), array_keys(self::CAPS)) !== []) {
                    throw ValidationException::withMessages(['limits' => 'Choose supported usage-limit fields.']);
                }
                $installation = $this->installation();
                $changes = [];
                foreach ($overrides as $field => $value) {
                    $normalized = $value === null ? null : $this->integer($value, 0, self::CAPS[$field]);
                    if (($value !== null && $normalized === null) || ($normalized !== null && $normalized > $installation[$field])) {
                        throw ValidationException::withMessages([$field => 'Choose a nonnegative whole-number limit within the installation ceiling, or inherit it.']);
                    }
                    $changes[$field] = $normalized;
                }

                $stored = DB::table('account_ai_usage_limits')->where('account_id', $accountId)->first();
                if ($stored === null) {
                    DB::table('account_ai_usage_limits')->insert([
                        'account_id' => $accountId,
                        ...array_fill_keys(array_keys(self::CAPS), null),
                        ...$changes,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif ($changes !== []) {
                    DB::table('account_ai_usage_limits')->where('account_id', $accountId)->update([
                        ...$changes, 'updated_at' => now(),
                    ]);
                }
                AuditEvent::query()->create([
                    'account_id' => $accountId,
                    'actor_type' => $freshActor->getMorphClass(), 'actor_id' => $freshActor->id,
                    'subject_type' => Account::class, 'subject_id' => $accountId,
                    'action' => 'account.ai_usage_limits.updated',
                    'metadata' => ['limits' => $changes], 'occurred_at' => now(),
                ]);

                return $this->forAccount($accountId);
            });
        } catch (AuthorizationException|ValidationException|AiNetworkException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new AiNetworkException('usage_policy_unavailable');
        }
    }

    /** Numeric account settings, usable before a connection is configured. */
    public function accountSettings(int $accountId): array
    {
        $limits = $this->forAccount($accountId);
        $installation = $this->installation();
        $stored = DB::table('account_ai_usage_limits')->where('account_id', $accountId)->first();
        $overrides = [];
        foreach (self::CAPS as $field => $maximum) {
            $value = $stored?->{$field};
            $overrides[$field] = $value === null ? null : $this->integer($value, 0, $maximum);
            if ($value !== null && $overrides[$field] === null) {
                throw new AiNetworkException('usage_policy_unavailable');
            }
        }

        return [
            'unavailable' => false,
            'overrides' => $overrides,
            'installation' => array_intersect_key($installation, self::CAPS),
            'effective' => [
                'daily_requests' => $limits->account['requests'], 'daily_tokens' => $limits->account['tokens'],
                'concurrency' => $limits->account['concurrency'], 'max_output_tokens' => $limits->maxOutputTokens,
            ],
        ];
    }

    public function forAccount(int $accountId): AiUsageLimits
    {
        try {
            if ($accountId <= 0 || ! Account::query()->whereKey($accountId)->exists()) {
                throw new AiNetworkException('usage_policy_unavailable');
            }
            $installation = $this->installation();
            $stored = DB::table('account_ai_usage_limits')->where('account_id', $accountId)->first();
            $effective = [];
            foreach (self::CAPS as $field => $maximum) {
                $value = $stored?->{$field};
                $override = $value === null ? null : $this->integer($value, 0, $maximum);
                if ($value !== null && $override === null) {
                    throw new AiNetworkException('usage_policy_unavailable');
                }
                $effective[$field] = min($installation[$field], $override ?? $installation[$field]);
            }

            return new AiUsageLimits(
                $this->scope($installation), $this->scope($effective), $effective['max_output_tokens'],
                $installation['queue_ttl_seconds'], $installation['claim_ttl_seconds'],
                $installation['dispatch_ttl_seconds'], $installation['retry_cooldown_seconds'],
            );
        } catch (Throwable) {
            throw new AiNetworkException('usage_policy_unavailable');
        }
    }

    /** @return array<string, int> */
    private function installation(): array
    {
        $configuration = $this->config->get('wayfindr.ai.usage', []);
        if (! is_array($configuration)) {
            throw new AiNetworkException('usage_policy_unavailable');
        }
        $values = [];
        foreach (self::CAPS as $field => $maximum) {
            $values[$field] = $this->integer(array_key_exists($field, $configuration) ? $configuration[$field] : 0, 0, $maximum);
        }
        foreach (self::DURATIONS as $field => [$default, $minimum, $maximum]) {
            $values[$field] = $this->integer(array_key_exists($field, $configuration) ? $configuration[$field] : $default, $minimum, $maximum);
        }
        if (in_array(null, $values, true)) {
            throw new AiNetworkException('usage_policy_unavailable');
        }

        return $values;
    }

    private function integer(mixed $value, int $minimum, int $maximum): ?int
    {
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1
            && strlen($value) <= strlen((string) $maximum)
            && (strlen($value) < strlen((string) $maximum) || strcmp($value, (string) $maximum) <= 0)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : null;
    }

    /** @return array{requests: int, tokens: int, concurrency: int} */
    private function scope(array $values): array
    {
        return ['requests' => $values['daily_requests'], 'tokens' => $values['daily_tokens'], 'concurrency' => $values['concurrency']];
    }
}
