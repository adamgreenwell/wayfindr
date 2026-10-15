<?php

declare(strict_types=1);

use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Ai\AgentCopilotSdkFactory;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiModelDiscovery;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiOutboundAdmission;
use App\Support\Ai\Network\AiProviderRoutes;
use App\Support\Ai\Network\UnavailableAiModelDiscovery;
use App\Support\Ai\Network\UnavailableAiOutboundAdmission;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function aiRouteContractConnection(string $provider, string $endpoint = ''): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection(
        1, 'account', 'synthetic-route-revision', $provider, 'exact-model',
        $endpoint, 'synthetic-upstream', 'synthetic-route-key',
    );
}

test('guarded route defaults match every installed SDK gateway without issuing a request', function (string $provider): void {
    Http::preventStrayRequests();
    $endpoint = $provider === 'openai-compatible' ? 'https://models.example.test/v1' : '';
    $connection = aiRouteContractConnection($provider, $endpoint);
    $sdk = app(AgentCopilotSdkFactory::class)->make($provider, $connection->model, $endpoint, $connection->apiKey());
    $gateway = $sdk->textGateway();
    $base = (new ReflectionMethod($gateway, 'baseUrl'))->invoke($gateway, $sdk);
    expect(AiProviderRoutes::baseUrl($connection))->toBe($base);

    $custom = aiRouteContractConnection($provider, 'https://models.example.test/custom/');
    $sdk = app(AgentCopilotSdkFactory::class)->make($provider, $custom->model, $custom->endpoint, $custom->apiKey());
    $gateway = $sdk->textGateway();
    expect(AiProviderRoutes::baseUrl($custom))->toBe((new ReflectionMethod($gateway, 'baseUrl'))->invoke($gateway, $sdk))
        ->and(AiProviderRoutes::baseUrl($custom))->toBe('https://models.example.test/custom');
    Http::assertNothingSent();
})->with(AgentCopilotConfiguration::PROVIDERS);

test('adding a supported provider requires an explicit guarded route contract', function (): void {
    expect(array_keys(AiProviderRoutes::DEFAULTS))->toBe(AgentCopilotConfiguration::PROVIDERS);
    expect(fn () => AiProviderRoutes::baseUrl(aiRouteContractConnection('other-platform')))
        ->toThrow(AiNetworkException::class)
        ->and(fn () => AiProviderRoutes::baseUrl(aiRouteContractConnection('openai-compatible')))
        ->toThrow(AiNetworkException::class);
});

test('default discovery and usage admission cannot authorize a new account call', function (): void {
    Http::preventStrayRequests();
    $connection = aiRouteContractConnection('openai');
    expect(app(AiOutboundAdmission::class))->toBeInstanceOf(UnavailableAiOutboundAdmission::class)
        ->and(app(AiModelDiscovery::class))->toBeInstanceOf(UnavailableAiModelDiscovery::class)
        ->and(app(AiModelDiscovery::class)->discover($connection))->toBeNull();
    try {
        app(AiOutboundAdmission::class)->assertAllowed($connection, 'synthetic_probe', 'synthetic-attempt');
        $reason = 'incorrectly admitted';
    } catch (AiNetworkException $exception) {
        $reason = $exception->reason;
        expect($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('synthetic-route-key', 'exact-model');
    }
    expect($reason)->toBe('usage_admission_unavailable')
        ->and(app(AgentCopilotConfiguration::class)->assessValues(
            'openai', 'manually-entered-exact-model', '', true,
        )['status'])->toBe('ready');
    Http::assertNothingSent();
});

test('route and admission exception traces redact their live credential-bearing connection', function (): void {
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        $connection = aiRouteContractConnection('unsupported-synthetic-provider');
        foreach ([
            [fn () => AiProviderRoutes::baseUrl($connection), AiProviderRoutes::class, 'baseUrl'],
            [fn () => app(AiOutboundAdmission::class)->assertAllowed($connection, 'synthetic_probe', 'synthetic-attempt'), UnavailableAiOutboundAdmission::class, 'assertAllowed'],
        ] as [$operation, $class, $method]) {
            try {
                $operation();
                $trace = [];
            } catch (AiNetworkException $exception) {
                $trace = $exception->getTrace();
            }
            $frames = array_values(array_filter($trace, fn (array $frame): bool => ($frame['class'] ?? '') === $class && ($frame['function'] ?? '') === $method));
            expect($frames)->not->toBeEmpty()
                ->and($frames[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('the default container transport refuses before DNS or terminal network activity', function (): void {
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public function resolve(string $host, float $deadline): array
        {
            $this->calls++;
            throw new AiNetworkException('incorrectly_reached_dns');
        }
    };
    app()->instance(AiDnsResolver::class, $dns);
    try {
        app(AiHttpTransport::class)->send(aiRouteContractConnection('openai'), 'synthetic_probe', 'synthetic-attempt', 'POST', 'https://api.openai.com/v1/responses', [], '{}');
        $reason = 'incorrectly admitted';
    } catch (AiNetworkException $exception) {
        $reason = $exception->reason;
    }
    expect($reason)->toBe('usage_admission_unavailable')->and($dns->calls)->toBe(0);
});
