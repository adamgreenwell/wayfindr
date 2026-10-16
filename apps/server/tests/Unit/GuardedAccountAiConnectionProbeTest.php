<?php

declare(strict_types=1);

use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiExecutionContext;
use App\Support\Ai\Execution\AiGuardedSession;
use App\Support\Ai\GuardedAccountAiConnectionProbe;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiHttpResult;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

final class ProbeSessionFixture implements AiGuardedSession
{
    public array $settlements = [];

    public ?Closure $duringSettle = null;

    public function __construct(private ResolvedAgentCopilotConnection $resolved) {}

    public function connection(): ResolvedAgentCopilotConnection
    {
        return $this->resolved;
    }

    public function maxOutputTokens(): int
    {
        return 64;
    }

    public function dispatch(string $url, array $headers, string $json): AiHttpResult
    {
        throw new LogicException('The fake provider owns the synthetic response.');
    }

    public function request(string $method, string $url, array $headers, string $body = ''): AiHttpResult
    {
        throw new LogicException('The fake provider owns the synthetic response.');
    }

    public function recordResponse(AiHttpResult $receipt, ?int $inputTokens, ?int $outputTokens): void {}

    public function settle(string $reason = 'completed'): void
    {
        $this->settlements[] = $reason;
        if ($this->duringSettle !== null) {
            ($this->duringSettle)();
        }
    }

    public function usage(): array
    {
        return ['input' => null, 'output' => null];
    }
}

function guardedProbeFixture(string $text = 'OK', array $network = []): array
{
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public function resolve(string $host, float $deadline): array
        {
            $this->calls++;
            throw new LogicException('A unit probe fixture must not resolve DNS.');
        }
    };
    $configuration = new Repository(['app' => ['key' => 'synthetic-policy-key'], 'wayfindr' => ['ai' => ['max_context_characters' => 30_000]]]);
    $connection = new ResolvedAgentCopilotConnection(7, 'account', str_repeat('a', 64), 'openai-compatible', 'exact-model', 'https://private-host/v1', '', 'synthetic-private-key');
    $context = AiExecutionContext::probe(7, 11, '11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222');
    $session = new ProbeSessionFixture($connection);
    $provider = new class($text, $session) implements AgentCopilotProvider
    {
        public array $prompts = [];

        public ?Closure $duringGenerate = null;

        public function __construct(private string $text, private AiGuardedSession $session) {}

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->prompts[] = $prompt;
            if ($this->duringGenerate !== null) {
                ($this->duringGenerate)();
            }

            return new AgentCopilotResult($this->text, 'openai-compatible', 'exact-model', null, null, $this->session);
        }

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('An account proposal must never use the installation probe method.');
        }
    };
    $container = new Container;
    $container->instance('config', $configuration);
    $container->instance(AgentCopilotProvider::class, $provider);
    $policy = new AiNetworkPolicy($dns, ['mode' => 'public-only', ...$network], 'synthetic-network-key');

    return [new GuardedAccountAiConnectionProbe($policy, $container), $connection, $context, $provider, $session, $configuration, $dns];
}

function guardedProbeFailure(Closure $operation, string $reason): void
{
    try {
        $operation();
        test()->fail('Expected sanitized probe refusal: '.$reason);
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('private-provider-marker', 'synthetic-private-key', 'private-host');
    }
}

test('account probe sends fixed synthetic text through the exact proposal execution and settles after acknowledgement validation', function (): void {
    [$probe, $connection, $context, $provider, $session, $configuration, $dns] = guardedProbeFixture();
    $result = $probe->probe($connection, $context);
    expect($result->text)->toBe('OK')->and($provider->prompts)->toHaveCount(1)
        ->and($session->settlements)->toBe(['completed']);
    $prompt = $provider->prompts[0];
    expect($prompt->connection)->toBe($connection)
        ->and($prompt->execution)->toBe($context)
        ->and($prompt->purpose)->toBe('configuration_probe')
        ->and($prompt->timeoutSeconds)->toBe(20)
        ->and($prompt->input)->toContain('synthetic connection check', 'No customer, visitor, conversation or article data')
        ->not->toContain($connection->apiKey(), $connection->endpoint, $connection->model)
        ->and($dns->calls)->toBe(0);
});

test('missing foreign or wrong-purpose probe contexts fail before resolving a provider', function (): void {
    [$probe, $connection, $context, $provider, $session] = guardedProbeFixture();
    guardedProbeFailure(fn () => $probe->probe($connection), 'probe_context_required');
    guardedProbeFailure(fn () => $probe->probe($connection, AiExecutionContext::probe(8, 11, $context->proposedRevision, $context->testGeneration)), 'probe_context_required');
    guardedProbeFailure(fn () => $probe->probe($connection, AiExecutionContext::discovery(7, 11, $context->proposedRevision, $context->testGeneration)), 'probe_context_required');
    expect($provider->prompts)->toBe([])->and($session->settlements)->toBe([]);
});

test('empty or excessive synthetic acknowledgements are failed outcomes rather than successful connectivity receipts', function (string $text): void {
    [$probe, $connection, $context, $provider, $session] = guardedProbeFixture($text);
    guardedProbeFailure(fn () => $probe->probe($connection, $context), 'probe_invalid_output');
    expect($session->settlements)->toBe(['invalid_output'], 'Invalid acknowledgement output must retain failed-attempt cooldown.');
})->with(['empty' => [''], 'whitespace' => ['  '], 'over character cap' => [str_repeat('a', 257)], 'multibyte over character cap' => [str_repeat('界', 257)]]);

test('probe propagates sanitized policy and budget reasons while scrubbing unexpected provider exceptions', function (bool $known): void {
    [$probe, $connection, $context, $provider, $session] = guardedProbeFixture();
    $provider->duringGenerate = fn () => throw $known ? new AiNetworkException('usage_request_limit') : new RuntimeException('private-provider-marker synthetic-private-key https://private-host');
    guardedProbeFailure(fn () => $probe->probe($connection, $context), $known ? 'usage_request_limit' : 'probe_unavailable');
    expect($session->settlements)->toBe([]);
})->with([true, false]);

test('probe does not retry an uncertain settlement or attest success when persistence fails', function (): void {
    [$probe, $connection, $context, $provider, $session] = guardedProbeFixture();
    $session->duringSettle = fn () => throw new AiNetworkException('usage_reconciliation_unavailable');
    guardedProbeFailure(fn () => $probe->probe($connection, $context), 'usage_reconciliation_unavailable');
    expect($session->settlements)->toBe(['completed']);
});

test('probe policy receipt changes with network policy context ceiling and HMAC key without exposing topology', function (): void {
    [$probe, $connection, $context, $provider, $session, $configuration, $dns] = guardedProbeFixture();
    $original = $probe->policyRevision();
    expect($original)->toMatch('/\A[a-f0-9]{64}\z/');
    $configuration->set('wayfindr.ai.max_context_characters', 12_000);
    expect($probe->policyRevision())->not->toBe($original, 'A changed context ceiling must invalidate a prior probe policy receipt.');
    $configuration->set('wayfindr.ai.max_context_characters', 30_000);
    $configuration->set('app.key', 'rotated-synthetic-policy-key');
    expect($probe->policyRevision())->not->toBe($original);
    [$other] = guardedProbeFixture(network: ['mode' => 'operator-private', 'private_grants' => [[
        'account_id' => 7, 'source' => 'account', 'origin' => 'https://private-host', 'ips' => ['127.0.0.1'],
    ]]]);
    expect($other->policyRevision())->not->toBe($original)
        ->not->toContain('private-host', '127.0.0.1', 'synthetic-policy-key')
        ->and($dns->calls)->toBe(0);
});

test('probe policy receipt fails closed when the installation HMAC key is unavailable', function (): void {
    [$probe, $connection, $context, $provider, $session, $configuration] = guardedProbeFixture();
    $configuration->set('app.key', '');
    guardedProbeFailure(fn () => $probe->policyRevision(), 'probe_policy_unavailable');
});
