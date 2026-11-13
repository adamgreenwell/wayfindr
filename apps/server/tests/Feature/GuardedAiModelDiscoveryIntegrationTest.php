<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiConnection;
use App\Models\User;
use App\Support\Ai\AccountAiConnectionProbe;
use App\Support\Ai\AccountAiConnections;
use App\Support\Ai\Execution\AiGuardedTransportFactory;
use App\Support\Ai\Execution\AiRequestAuthority;
use App\Support\Ai\GuardedAccountAiConnectionProbe;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiModelDiscovery;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Network\GuardedAiModelDiscovery;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

/** Full real admission and authority; only DNS and the terminal HTTP are fake. */
function guardedDiscoveryWorld(array $limits = [], string $response = '{"data":[{"id":"exact-model:tag"}]}'): array
{
    config(['wayfindr.ai.usage' => [
        'daily_requests' => 10, 'daily_tokens' => 10_000, 'concurrency' => 1, 'max_output_tokens' => 64, ...$limits,
    ]]);
    app()->instance(AiUsageClock::class, new class implements AiUsageClock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC');
        }
    });
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public ?Closure $during = null;

        public string $ip = '8.8.8.8';

        public function resolve(string $host, float $deadline): array
        {
            $this->calls++;
            if ($this->during !== null) {
                ($this->during)();
            }

            return [$this->ip];
        }
    };
    $wire = new class
    {
        public array $requests = [];
    };
    $policy = new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-network-key');
    app()->instance(AiNetworkPolicy::class, $policy);
    app()->bind(AccountAiConnectionProbe::class, GuardedAccountAiConnectionProbe::class);
    app()->bind(AiModelDiscovery::class, GuardedAiModelDiscovery::class);
    app()->instance(AiGuardedTransportFactory::class, new AiGuardedTransportFactory(
        $policy, app(AiUsageLedger::class), app(AiRequestAuthority::class),
        function (RequestInterface $request) use ($wire, $response) {
            $wire->requests[] = $request;

            return Create::promiseFor(new Response(200, [], $response));
        },
    ));
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    $service = app(AccountAiConnections::class);
    $proposal = $service->propose($owner, (int) $owner->account_id, [
        'source' => 'account', 'provider' => 'openai-compatible', 'model' => '',
        'endpoint' => 'https://models.example.test/v1', 'api_key' => 'synthetic-private-key',
    ]);

    return compact('owner', 'service', 'proposal', 'dns', 'wire');
}

function guardedDiscoveryIntegrationReason(Closure $operation, string $expected): void
{
    try {
        $operation();
        test()->fail('Expected guarded discovery refusal: '.$expected);
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($expected)
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('synthetic-private-key', 'models.example.test');
    }
}

test('guarded discovery works before model selection without activating or testing support processing', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'dns' => $dns, 'wire' => $wire] = guardedDiscoveryWorld();
    expect($service->discoverModels($owner, (int) $owner->account_id, $proposal->proposed_revision))->toBe(['exact-model:tag'])
        ->and($dns->calls)->toBe(1)->and($wire->requests)->toHaveCount(1);
    $request = $wire->requests[0];
    expect($request->getMethod())->toBe('GET')
        ->and((string) $request->getUri())->toBe('https://models.example.test/v1/models')
        ->and((string) $request->getBody())->toBe('')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer synthetic-private-key');
    $fresh = $proposal->fresh();
    expect($fresh->active_revision)->toBeNull()->and($fresh->tested_revision)->toBeNull()
        ->and($fresh->proposedConfiguration()['model'])->toBe('');
    $summary = app(AiUsageLedger::class)->summary((int) $owner->account_id);
    expect($summary['account'])->toBe(['held_requests' => 0, 'held_tokens' => 0, 'charged_requests' => 1, 'charged_tokens' => 0, 'concurrency' => 0])
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe('completed');
});

test('guarded discovery budgets stop before DNS or HTTP', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'dns' => $dns, 'wire' => $wire] = guardedDiscoveryWorld(['daily_requests' => 0]);
    guardedDiscoveryIntegrationReason(fn () => $service->discoverModels($owner, (int) $owner->account_id, $proposal->proposed_revision), 'usage_request_limit');
    expect($dns->calls)->toBe(0)->and($wire->requests)->toBe([])->and(DB::table('ai_usage_attempts')->count())->toBe(0);
});

test('freshly revoked discovery management stops after DNS before credential transmission', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'dns' => $dns, 'wire' => $wire] = guardedDiscoveryWorld();
    $dns->during = fn () => $owner->forceFill(['account_role' => AccountRole::Agent])->save();
    guardedDiscoveryIntegrationReason(fn () => $service->discoverModels($owner, (int) $owner->account_id, $proposal->proposed_revision), 'access_revoked');
    expect($dns->calls)->toBe(1)->and($wire->requests)->toBe([])
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe('cancelled')
        ->and((int) DB::table('ai_usage_periods')->sum('held_requests'))->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->sum('charged_requests'))->toBe(0);
});

test('changing a discovery proposal during DNS cannot forward its earlier credential', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'dns' => $dns, 'wire' => $wire] = guardedDiscoveryWorld();
    $dns->during = fn () => $service->propose($owner, (int) $owner->account_id, [
        'source' => 'account', 'provider' => 'openai-compatible', 'model' => '',
        'endpoint' => 'https://replacement.example.test/v1', 'api_key' => 'replacement-synthetic-key',
    ]);
    guardedDiscoveryIntegrationReason(fn () => $service->discoverModels($owner, (int) $owner->account_id, $proposal->proposed_revision), 'connection_changed');
    expect($wire->requests)->toBe([])->and(DB::table('ai_usage_attempts')->value('state'))->toBe('cancelled');
});

test('foreign account discovery cannot reach the guarded runner', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'dns' => $dns, 'wire' => $wire] = guardedDiscoveryWorld();
    $foreign = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    expect(fn () => $service->discoverModels($foreign, (int) $owner->account_id, $proposal->proposed_revision))->toThrow(AuthorizationException::class)
        ->and($dns->calls)->toBe(0)->and($wire->requests)->toBe([]);
});

test('private discovery destinations still require an exact operator grant', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'dns' => $dns, 'wire' => $wire] = guardedDiscoveryWorld();
    $dns->ip = '127.0.0.1';
    guardedDiscoveryIntegrationReason(fn () => $service->discoverModels($owner, (int) $owner->account_id, $proposal->proposed_revision), 'destination_denied');
    expect($wire->requests)->toBe([])->and(DB::table('ai_usage_attempts')->value('state'))->toBe('cancelled');
});

test('malformed catalogue output still charges its request and preserves failed-attempt cooldown', function (): void {
    ['owner' => $owner, 'service' => $service, 'proposal' => $proposal, 'wire' => $wire] = guardedDiscoveryWorld(response: 'private-provider-invalid-catalogue');
    guardedDiscoveryIntegrationReason(fn () => $service->discoverModels($owner, (int) $owner->account_id, $proposal->proposed_revision), 'discovery_invalid_response');
    $attempt = DB::table('ai_usage_attempts')->first();
    expect($wire->requests)->toHaveCount(1)->and($attempt->state)->toBe('failed')
        ->and($attempt->reason)->toBe('invalid_output')->and($attempt->retry_not_before)->not->toBeNull()
        ->and(app(AiUsageLedger::class)->summary((int) $owner->account_id)['account']['charged_requests'])->toBe(1)
        ->and(json_encode((array) $attempt, JSON_THROW_ON_ERROR))->not->toContain('private-provider-invalid-catalogue', 'synthetic-private-key', 'models.example.test');
});

function guardedProbeWireResponse(string $text = 'OK', ?array $usage = ['prompt_tokens' => 17, 'completion_tokens' => 1]): string
{
    return json_encode([
        'id' => 'synthetic-response', 'object' => 'chat.completion', 'model' => 'exact-probe-model',
        'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
        ...($usage === null ? [] : ['usage' => $usage]),
    ], JSON_THROW_ON_ERROR);
}

function guardedProbeSelectModel(array $world): AccountAiConnection
{
    return $world['service']->propose($world['owner'], (int) $world['owner']->account_id, [
        'source' => 'account', 'provider' => 'openai-compatible', 'model' => 'exact-probe-model',
        'endpoint' => 'https://models.example.test/v1', 'api_key' => '',
    ]);
}

test('real SDK proposal probe uses synthetic text exact model and enforced output cap before attesting connectivity', function (): void {
    $world = guardedDiscoveryWorld(response: guardedProbeWireResponse());
    $proposal = guardedProbeSelectModel($world);
    $tested = $world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision);
    expect($tested->test_status)->toBe('ready')->and($tested->tested_revision)->toBe($proposal->proposed_revision)
        ->and($tested->active_revision)->toBeNull()->and($world['wire']->requests)->toHaveCount(1);
    $request = $world['wire']->requests[0];
    $body = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
    expect((string) $request->getUri())->toBe('https://models.example.test/v1/chat/completions')
        ->and($body['model'])->toBe('exact-probe-model')->and($body['max_tokens'])->toBe(64)
        ->and(array_column($body['messages'], 'role'))->toBe(['system', 'user'])
        ->and($body['messages'][1]['content'])->toBe('Wayfindr synthetic connection check. No customer, visitor, conversation or article data is included.')
        ->and((string) $request->getBody())->not->toContain('synthetic-private-key', 'models.example.test')
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe('completed');
    $summary = app(AiUsageLedger::class)->summary((int) $world['owner']->account_id);
    expect($summary['account']['charged_requests'])->toBe(1)->and($summary['account']['charged_tokens'])->toBe(18)
        ->and($summary['account']['concurrency'])->toBe(0);
});

test('concurrent proposal probe requests cannot duplicate the synthetic call', function (): void {
    $world = guardedDiscoveryWorld(response: guardedProbeWireResponse());
    $proposal = guardedProbeSelectModel($world);
    $world['dns']->during = function () use ($world, $proposal): void {
        expect(fn () => $world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision))
            ->toThrow(ValidationException::class, 'A connection test is already running.');
    };
    expect($world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision)->test_status)->toBe('ready')
        ->and($world['wire']->requests)->toHaveCount(1)->and(DB::table('ai_usage_attempts')->count())->toBe(1);
});

test('proposal probe budget exhaustion cannot reach DNS or attest connectivity', function (): void {
    $world = guardedDiscoveryWorld(['daily_requests' => 0], guardedProbeWireResponse());
    $proposal = guardedProbeSelectModel($world);
    $tested = $world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision);
    expect($tested->test_status)->toBe('unavailable')->and($tested->tested_revision)->toBeNull()
        ->and($tested->test_failure_code)->toBe('usage_request_limit')
        ->and($world['dns']->calls)->toBe(0)->and($world['wire']->requests)->toBe([]);
});

test('proposal probe revocation during DNS blocks the wire and final test receipt', function (): void {
    $world = guardedDiscoveryWorld(response: guardedProbeWireResponse());
    $proposal = guardedProbeSelectModel($world);
    $world['dns']->during = fn () => $world['owner']->forceFill(['account_role' => AccountRole::Agent])->save();
    expect(fn () => $world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision))
        ->toThrow(AuthorizationException::class)
        ->and($world['wire']->requests)->toBe([])->and($proposal->fresh()->tested_revision)->toBeNull()
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe('cancelled');
});

test('successful probe with missing usage retains the conservative debit instead of SDK default zero', function (): void {
    $world = guardedDiscoveryWorld(response: guardedProbeWireResponse(usage: null));
    $proposal = guardedProbeSelectModel($world);
    $tested = $world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision);
    $attempt = DB::table('ai_usage_attempts')->first();
    expect($tested->test_status)->toBe('ready')->and($attempt->state)->toBe('unknown')
        ->and($attempt->reported_input_tokens)->toBeNull()->and($attempt->reported_output_tokens)->toBeNull()
        ->and((int) $attempt->debited_tokens)->toBeGreaterThan(64, 'Missing usage must retain input and output allowance rather than fabricated zero.')
        ->and((bool) $attempt->concurrency_held)->toBeTrue();
});

test('excessive synthetic acknowledgement cannot become a successful probe and retains failure cooldown', function (): void {
    $world = guardedDiscoveryWorld(response: guardedProbeWireResponse(str_repeat('a', 257)));
    $proposal = guardedProbeSelectModel($world);
    $tested = $world['service']->test($world['owner'], (int) $world['owner']->account_id, $proposal->proposed_revision);
    $attempt = DB::table('ai_usage_attempts')->first();
    expect($tested->test_status)->toBe('unavailable')->and($tested->tested_revision)->toBeNull()
        ->and($attempt->state)->toBe('failed')->and($attempt->reason)->toBe('invalid_output')
        ->and($attempt->retry_not_before)->not->toBeNull()->and((bool) $attempt->concurrency_held)->toBeFalse();
});
