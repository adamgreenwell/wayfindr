<?php

declare(strict_types=1);

use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Network\LedgerAiOutboundAdmission;
use App\Support\Ai\Network\UnavailableAiOutboundAdmission;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;

function aiAdmissionTraceConnection(): ResolvedAgentCopilotConnection
{
    return new ResolvedAgentCopilotConnection(1, 'account', str_repeat('a', 64), 'openai-compatible',
        'synthetic-model', 'https://models.example.test/v1', '', 'private-key');
}

function aiAdmissionTraceRefusal(Closure $operation, array $privateValues, string $reason): void
{
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        try {
            $operation();
            test()->fail('The attempt must be refused.');
        } catch (AiNetworkException $exception) {
            expect($exception->reason)->toBe($reason)->and($exception->getPrevious())->toBeNull();
            foreach ($exception->getTrace() as $frame) {
                if (! str_starts_with($frame['class'] ?? '', 'App\\Support\\Ai\\')) {
                    continue;
                }
                foreach ($frame['args'] ?? [] as $argument) {
                    expect($argument)->not->toBeInstanceOf(ResolvedAgentCopilotConnection::class);
                    foreach ($privateValues as $private) {
                        expect($argument)->not->toBe($private);
                    }
                }
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
}

test('actual ledger admission redacts invalid input before any database access', function (string $method, string $field): void {
    $clock = new class implements AiUsageClock
    {
        public function now(): CarbonImmutable
        {
            throw new LogicException('Invalid arguments must not reach the clock or database.');
        }
    };
    $admission = new LedgerAiOutboundAdmission(new AiUsageLedger(new AiUsagePolicy(new Repository), $clock));
    $id = $field === 'attempt' ? 'private-attempt' : '00000000-0000-0000-0000-000000000001';
    $nonce = '00000000-0000-0000-0000-000000000002';
    $purpose = $field === 'purpose' ? 'private-purpose' : 'configuration_probe';
    $reason = $field === 'reason' ? 'private-reason' : 'known_unsent';
    $connection = aiAdmissionTraceConnection();
    $operation = match ($method) {
        'assertAllowed' => fn () => $admission->assertAllowed($connection, $purpose, $id),
        'beginDispatch' => fn () => $admission->beginDispatch($connection, $purpose, $id, $nonce),
        'cancelUnsent' => fn () => $admission->cancelUnsent($id, $nonce, $reason),
        'markUnknown' => fn () => $admission->markUnknown($id, $nonce, $reason),
    };

    aiAdmissionTraceRefusal($operation, ['private-attempt', 'private-purpose', 'private-reason'],
        $field === 'reason' ? 'usage_invalid_reason' : 'usage_invalid_request');
})->with([
    ['assertAllowed', 'purpose'], ['assertAllowed', 'attempt'],
    ['beginDispatch', 'purpose'], ['beginDispatch', 'attempt'],
    ['cancelUnsent', 'reason'], ['cancelUnsent', 'attempt'],
    ['markUnknown', 'reason'], ['markUnknown', 'attempt'],
]);

test('unavailable admission redacts every refused raw argument', function (string $method): void {
    $admission = new UnavailableAiOutboundAdmission;
    $connection = aiAdmissionTraceConnection();
    $operation = match ($method) {
        'assertAllowed' => fn () => $admission->assertAllowed($connection, 'private-purpose', 'private-attempt'),
        'beginDispatch' => fn () => $admission->beginDispatch($connection, 'private-purpose', 'private-attempt', 'private-claim'),
        'cancelUnsent' => fn () => $admission->cancelUnsent('private-attempt', 'private-claim', 'private-reason'),
        'markUnknown' => fn () => $admission->markUnknown('private-attempt', 'private-dispatch', 'private-reason'),
    };

    aiAdmissionTraceRefusal($operation, ['private-purpose', 'private-attempt', 'private-claim', 'private-dispatch', 'private-reason'],
        'usage_admission_unavailable');
})->with(['assertAllowed', 'beginDispatch', 'cancelUnsent', 'markUnknown']);

test('transport rebuilt failures redact raw purpose attempt and method before DNS', function (): void {
    $dns = new class implements AiDnsResolver
    {
        public int $calls = 0;

        public function resolve(string $host, float $deadline): array
        {
            $this->calls++;

            throw new LogicException('Denied admission must not resolve a destination.');
        }
    };
    $transport = new AiHttpTransport(new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-key'),
        new UnavailableAiOutboundAdmission);

    aiAdmissionTraceRefusal(fn () => $transport->send(aiAdmissionTraceConnection(), 'private-purpose', 'private-attempt',
        'private-method', 'https://models.example.test/v1/chat'), ['private-purpose', 'private-attempt', 'private-method'],
        'usage_admission_unavailable');
    expect($dns->calls)->toBe(0);
});
