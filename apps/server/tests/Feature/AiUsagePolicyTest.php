<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\User;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsagePolicy;
use Dotenv\Repository\RepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function usagePolicyConnection(Account $account, string $source = 'account'): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection((int) $account->id, $source, 'synthetic-revision', 'openai', 'synthetic-model', '', '', 'synthetic-key');
}

function usagePolicyOwner(): User
{
    return User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
}

beforeEach(function (): void {
    config(['wayfindr.ai.usage' => [
        'daily_requests' => 100, 'daily_tokens' => 10_000, 'concurrency' => 5, 'max_output_tokens' => 500,
    ]]);
});

test('shipped usage configuration defaults deny and preserves invalid environment input for rejection', function (?string $raw): void {
    // A private environment reader avoids changing this test process's env.
    $property = new ReflectionProperty(Env::class, 'repository');
    $original = $property->getValue();
    $repository = Mockery::mock(RepositoryInterface::class);
    $repository->shouldReceive('get')->andReturnUsing(fn (string $key): ?string => $key === 'WAYFINDR_AI_DAILY_REQUESTS' ? $raw : null);
    $property->setValue(null, $repository);
    try {
        $configuration = require config_path('wayfindr.php');
    } finally {
        $property->setValue(null, $original);
    }

    expect($configuration['ai']['usage']['daily_requests'])->toBe($raw ?? 0)
        ->and($configuration['ai']['usage']['daily_tokens'])->toBe(0)
        ->and($configuration['ai']['usage']['concurrency'])->toBe(0)
        ->and($configuration['ai']['usage']['max_output_tokens'])->toBe(0);
    config(['wayfindr.ai.usage' => $configuration['ai']['usage']]);
    $connection = usagePolicyConnection(Account::factory()->create());
    if ($raw === null) {
        expect(app(AiUsagePolicy::class)->forConnection($connection)->account['requests'])->toBe(0);
    } else {
        expect(fn () => app(AiUsagePolicy::class)->forConnection($connection))->toThrow(AiNetworkException::class);
    }
})->with(['omitted' => [null], 'malformed' => ['not-a-limit']]);

test('new installation and account sources default to zero caps without enrollment', function (string $source): void {
    $account = Account::factory()->create();
    config(['wayfindr.ai.usage' => []]);
    $limits = app(AiUsagePolicy::class)->forConnection(usagePolicyConnection($account, $source));

    expect($limits->installation)->toBe(['requests' => 0, 'tokens' => 0, 'concurrency' => 0])
        ->and($limits->account)->toBe($limits->installation)
        ->and($limits->maxOutputTokens)->toBe(0)
        ->and([$limits->queueTtlSeconds, $limits->claimTtlSeconds, $limits->dispatchTtlSeconds, $limits->retryCooldownSeconds])
        ->toBe([300, 30, 30, 60]);
})->with(['installation', 'account']);

test('null inherits zero prohibits and partial patches preserve other account limits', function (): void {
    $owner = usagePolicyOwner();
    $policy = app(AiUsagePolicy::class);
    $limits = $policy->setAccountLimits($owner, (int) $owner->account_id, [
        'daily_requests' => '20', 'daily_tokens' => null, 'concurrency' => 0, 'max_output_tokens' => '200',
    ]);
    expect($limits->installation)->toBe(['requests' => 100, 'tokens' => 10_000, 'concurrency' => 5])
        ->and($limits->account)->toBe(['requests' => 20, 'tokens' => 10_000, 'concurrency' => 0])
        ->and($limits->maxOutputTokens)->toBe(200);

    $cleared = $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_requests' => null]);
    expect($cleared->account)->toBe(['requests' => 100, 'tokens' => 10_000, 'concurrency' => 0])
        ->and($cleared->maxOutputTokens)->toBe(200)
        ->and(AccountAiConnection::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'account.ai_usage_limits.updated')->latest('id')->first()->metadata)
        ->toBe(['limits' => ['daily_requests' => null]]);
});

test('same policy instance reads fresh operator ceilings and clamps earlier account overrides', function (): void {
    $owner = usagePolicyOwner();
    $policy = app(AiUsagePolicy::class);
    $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_requests' => 80, 'max_output_tokens' => 400]);
    $connection = usagePolicyConnection($owner->account);
    config(['wayfindr.ai.usage.daily_requests' => 30, 'wayfindr.ai.usage.max_output_tokens' => 100]);
    $limits = $policy->forConnection($connection);
    expect($limits->account['requests'])->toBe(30, 'A lowered installation ceiling must clamp a saved account override.')
        ->and($limits->maxOutputTokens)->toBe(100);

    DB::table('account_ai_usage_limits')->where('account_id', $owner->account_id)->update(['daily_requests' => 0]);
    expect($policy->forConnection($connection)->account['requests'])->toBe(0, 'A fresh explicit zero must not inherit the installation ceiling.')
        ->and(fn () => $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_requests' => 31]))
        ->toThrow(ValidationException::class);
});

test('account overrides are isolated and cascade on account removal', function (): void {
    $owner = usagePolicyOwner();
    $other = Account::factory()->create();
    $policy = app(AiUsagePolicy::class);
    $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_tokens' => 25]);
    expect($policy->forConnection(usagePolicyConnection($other))->account['tokens'])->toBe(10_000);
    $connection = usagePolicyConnection($owner->account);
    $owner->account->delete();
    expect(DB::table('account_ai_usage_limits')->count())->toBe(0)
        ->and(fn () => $policy->forConnection($connection))->toThrow(AiNetworkException::class);
});

test('usage-limit management uses fresh account permission without requiring support site access', function (): void {
    $owner = usagePolicyOwner();
    $role = CustomRole::factory()->for($owner->account)->create(['permissions' => [AccountPermission::ManageAiConnections->value]]);
    $manager = User::factory()->for($owner->account)->create(['custom_role_id' => $role->id]);
    $policy = app(AiUsagePolicy::class);
    expect($policy->setAccountLimits($manager, (int) $owner->account_id, ['daily_requests' => 10])->account['requests'])->toBe(10);
    $manager->load('customRole'); // A cached permission must not survive revocation.
    $role->forceFill(['permissions' => []])->save();
    expect(fn () => $policy->setAccountLimits($manager, (int) $owner->account_id, ['daily_requests' => 20]))
        ->toThrow(AuthorizationException::class);
    $staleOwner = User::query()->findOrFail($owner->id);
    $owner->forceFill(['deactivated_at' => now()])->save();
    expect(fn () => $policy->setAccountLimits($staleOwner, (int) $owner->account_id, ['daily_requests' => 20]))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('account_ai_usage_limits')->value('daily_requests'))->toBe(10);
});

test('ordinary agents and cross-account managers cannot modify usage limits', function (): void {
    $owner = usagePolicyOwner();
    $agent = User::factory()->for($owner->account)->create(['account_role' => AccountRole::Agent]);
    $foreign = usagePolicyOwner();
    foreach ([$agent, $foreign] as $actor) {
        expect(fn () => app(AiUsagePolicy::class)->setAccountLimits($actor, (int) $owner->account_id, ['daily_requests' => 1]))
            ->toThrow(AuthorizationException::class);
    }
    expect(DB::table('account_ai_usage_limits')->count())->toBe(0);
});

test('invalid account patches are sanitized and atomically leave settings and audit unchanged', function (array $patch): void {
    $owner = usagePolicyOwner();
    try {
        app(AiUsagePolicy::class)->setAccountLimits($owner, (int) $owner->account_id, $patch);
        test()->fail('Invalid account limits must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->getMessage())->not->toContain('private-input-marker')
            ->and(DB::table('account_ai_usage_limits')->count())->toBe(0)
            ->and(AuditEvent::query()->where('action', 'account.ai_usage_limits.updated')->count())->toBe(0);
    }
})->with([
    'above installation' => [['daily_requests' => 101]],
    'negative' => [['daily_tokens' => -1]],
    'float' => [['concurrency' => 1.0]],
    'boolean' => [['concurrency' => true]],
    'scientific' => [['daily_requests' => '1e1']],
    'leading zero' => [['daily_requests' => '01']],
    'overflow' => [['daily_tokens' => '99999999999999999999999999999999']],
    'unknown field' => [['private-input-marker' => 1]],
    'untrusted text' => [['max_output_tokens' => 'private-input-marker']],
]);

test('malformed installation policy fails closed instead of silently widening or defaulting', function (string $field, mixed $value): void {
    $account = Account::factory()->create();
    config(['wayfindr.ai.usage.'.$field => $value]);
    try {
        app(AiUsagePolicy::class)->forConnection(usagePolicyConnection($account));
        test()->fail('Malformed installation limits must fail closed.');
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe('usage_policy_unavailable')
            ->and($exception->getPrevious())->toBeNull();
    }
})->with([
    'explicit null' => ['daily_requests', null],
    'negative cap' => ['daily_requests', -1],
    'overflow cap' => ['daily_tokens', '9223372036854775808'],
    'unbounded concurrency' => ['concurrency', 1001],
    'unbounded output' => ['max_output_tokens', 32769],
    'invalid decimal' => ['daily_tokens', '1.5'],
    'queue expires immediately' => ['queue_ttl_seconds', 0],
    'claim shorter than transport' => ['claim_ttl_seconds', 20],
    'dispatch shorter than transport' => ['dispatch_ttl_seconds', 20],
    'no retry floor' => ['retry_cooldown_seconds', 59],
    'unbounded retention' => ['queue_ttl_seconds', 86401],
    'null duration' => ['claim_ttl_seconds', null],
]);

test('invalid stored limits or unavailable storage cannot fall back to installation caps', function (): void {
    $owner = usagePolicyOwner();
    $policy = app(AiUsagePolicy::class);
    $policy->setAccountLimits($owner, (int) $owner->account_id, []);
    // Valid SQL bigint on PostgreSQL and SQLite, invalid policy ceiling.
    DB::table('account_ai_usage_limits')->where('account_id', $owner->account_id)->update(['daily_requests' => 1_000_001]);
    try {
        $policy->forConnection(usagePolicyConnection($owner->account));
        test()->fail('Unreadable account limits must deny admission.');
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe('usage_policy_unavailable')
            ->and($exception->getMessage())->not->toContain('1000001', 'daily_requests')
            ->and($exception->getPrevious())->toBeNull();
    }
    Schema::drop('account_ai_usage_limits');
    expect(fn () => $policy->forConnection(usagePolicyConnection($owner->account)))->toThrow(AiNetworkException::class);
});

test('reviewed usage tokens compare current stored fields under the account lock before any patch', function (): void {
    $owner = usagePolicyOwner();
    $policy = app(AiUsagePolicy::class);
    $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_tokens' => 1000, 'concurrency' => 1]);
    $token = $policy->accountSettings((int) $owner->account_id)['limits_token'];
    $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_tokens' => 100]);
    try {
        $policy->setAccountLimits($owner, (int) $owner->account_id, ['daily_tokens' => 1000, 'concurrency' => 2], $token);
        $this->fail('The locked usage-limit token check must reject an old snapshot before applying any fields.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('limits_token');
    }
    expect($policy->accountSettings((int) $owner->account_id)['overrides'])->toMatchArray(['daily_tokens' => 100, 'concurrency' => 1]);
});
