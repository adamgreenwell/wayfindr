<?php

use App\Models\Account;
use App\Support\Ai\Network\AiDnsResolver;
use App\Support\Ai\Network\AiHttpTransport;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Network\AiNetworkPolicy;
use App\Support\Ai\Network\LedgerAiOutboundAdmission;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

class AiAdmissionFoundationClock implements AiUsageClock
{
    public bool $unavailable = false;

    public function __construct(public CarbonImmutable $value) {}

    public function now(): CarbonImmutable
    {
        if ($this->unavailable) {
            throw new AiNetworkException('usage_clock_unavailable');
        }

        return $this->value;
    }
}

function aiAdmissionFoundation(): array
{
    config(['wayfindr.ai.usage' => [
        'daily_requests' => 10, 'daily_tokens' => 10_000,
        'concurrency' => 2, 'max_output_tokens' => 500,
    ]]);
    $clock = new AiAdmissionFoundationClock(CarbonImmutable::parse('2026-10-15 08:00:00', 'UTC'));
    app()->instance(AiUsageClock::class, $clock);
    $account = Account::factory()->create();
    $connection = new ResolvedAgentCopilotConnection($account->id, 'account', str_repeat('a', 64), 'openai-compatible', 'synthetic-model', 'https://models.example.test/v1', '', 'synthetic-key');

    return [app(AiUsageLedger::class), $clock, $connection];
}

function aiAdmissionFoundationPolicy(?string $ip = '8.8.8.8'): AiNetworkPolicy
{
    $dns = new class($ip) implements AiDnsResolver
    {
        public function __construct(private string $ip) {}

        public function resolve(string $host, float $deadline): array
        {
            return [$this->ip];
        }
    };

    return new AiNetworkPolicy($dns, ['mode' => 'public-only'], 'synthetic-policy-key');
}

test('ledger transport receipts retain conservative usage until explicit reported zero is reconciled', function (): void {
    [$ledger, $clock, $connection] = aiAdmissionFoundation();
    $reservation = $ledger->reserve($connection, 'configuration_probe', (string) Str::uuid(), 128, 64, hash('sha256', 'synthetic-work'));
    $calls = 0;
    $transport = new AiHttpTransport(aiAdmissionFoundationPolicy(), new LedgerAiOutboundAdmission($ledger), function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Response(200, [], '{}'));
    });
    $result = $transport->send($connection, 'configuration_probe', $reservation->attemptId, 'POST', $connection->endpoint.'/chat', [], '{}');
    $attempt = DB::table('ai_usage_attempts')->where('attempt_id', $result->attemptId)->first();
    expect([$attempt->state, (int) $attempt->debited_tokens, (bool) $attempt->concurrency_held])->toBe(['unknown', 192, true])
        ->and($attempt->reported_input_tokens)->toBeNull()
        ->and($attempt->reported_output_tokens)->toBeNull();
    try {
        $transport->send($connection, 'configuration_probe', $reservation->attemptId, 'POST', $connection->endpoint.'/chat', [], '{}');
        $reason = 'unexpected second call';
    } catch (AiNetworkException $exception) {
        $reason = $exception->reason;
    }
    expect($reason)->toBe('usage_attempt_terminal')->and($calls)->toBe(1, 'A returned receipt must never authorize another HTTP request.');

    $ledger->reconcile($result->attemptId, $result->dispatchNonce, 0, 0);
    $summary = $ledger->summary($connection->accountId);
    expect($summary['account']['charged_requests'])->toBe(1)
        ->and($summary['account']['charged_tokens'])->toBe(0, 'Explicit reported zero is known usage; missing usage remains reserved.')
        ->and($summary['account']['concurrency'])->toBe(0);
});

test('ledger transport releases a denied destination without charging or sending', function (): void {
    [$ledger, $clock, $connection] = aiAdmissionFoundation();
    $reservation = $ledger->reserve($connection, 'configuration_probe', (string) Str::uuid(), 128, 64, hash('sha256', 'synthetic-work'));
    $calls = 0;
    $transport = new AiHttpTransport(aiAdmissionFoundationPolicy('127.0.0.1'), new LedgerAiOutboundAdmission($ledger), function () use (&$calls) {
        $calls++;

        return Create::promiseFor(new Response(200));
    });
    try {
        $transport->send($connection, 'configuration_probe', $reservation->attemptId, 'POST', $connection->endpoint.'/chat', [], '{}');
        $reason = 'unexpected call';
    } catch (AiNetworkException $exception) {
        $reason = $exception->reason;
    }
    $summary = $ledger->summary($connection->accountId);
    expect($reason)->toBe('destination_denied')->and($calls)->toBe(0)
        ->and($summary['account'])->toBe(['held_requests' => 0, 'held_tokens' => 0, 'charged_requests' => 0, 'charged_tokens' => 0, 'concurrency' => 0])
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $reservation->attemptId)->value('state'))->toBe('cancelled');
});

test('scheduled AI expiry prioritizes capacity and never refunds unknown dispatch tokens', function (): void {
    [$ledger, $clock, $connection] = aiAdmissionFoundation();
    $queued = $ledger->reserve($connection, 'configuration_probe', (string) Str::uuid(), 10, 10, hash('sha256', 'queued'));
    $running = $ledger->reserve($connection, 'configuration_probe', (string) Str::uuid(), 10, 10, hash('sha256', 'running'));
    $claim = $ledger->claim($connection, 'configuration_probe', $running->attemptId);
    $dispatch = $ledger->beginDispatch($connection, 'configuration_probe', $running->attemptId, $claim);
    $ledger->markUnknown($running->attemptId, $dispatch->dispatchNonce, 'timeout');
    $clock->value = $clock->value->addSeconds(301);

    $this->artisan('wayfindr:expire-ai-usage-reservations', ['--limit' => '1'])
        ->expectsOutputToContain('Expired 1 AI usage reservations.')->assertSuccessful();
    $summary = $ledger->summary($connection->accountId);
    expect($summary['account']['concurrency'])->toBe(0, 'Expired concurrency must not wait behind older unsent holds.')
        ->and($summary['account']['held_requests'])->toBe(1)
        ->and($summary['account']['charged_tokens'])->toBe(20);
    $this->artisan('wayfindr:expire-ai-usage-reservations', ['--limit' => '1'])
        ->expectsOutputToContain('Expired 1 AI usage reservations.')->assertSuccessful();
    $summary = $ledger->summary($connection->accountId);
    expect($summary['account']['held_requests'])->toBe(0)
        ->and($summary['account']['charged_requests'])->toBe(1)
        ->and($summary['account']['charged_tokens'])->toBe(20)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $queued->attemptId)->value('state'))->toBe('expired');
});

test('AI expiry refuses unbounded batch sizes', function (string $limit): void {
    $this->artisan('wayfindr:expire-ai-usage-reservations', ['--limit' => $limit])
        ->expectsOutputToContain('Choose a whole-number limit from 1 to 1000.')
        ->assertExitCode(Command::INVALID);
})->with(['0', '-1', '1001', '1e3', 'unlimited']);

test('AI expiry reports a sanitized failure when database time is unavailable', function (): void {
    [$ledger, $clock] = aiAdmissionFoundation();
    $clock->unavailable = true;
    $this->artisan('wayfindr:expire-ai-usage-reservations')
        ->expectsOutputToContain('AI usage reservations could not be expired.')
        ->assertFailed();
});

test('bounded AI hold recovery runs every minute through the existing scheduler', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'wayfindr:expire-ai-usage-reservations'));
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->command)->toContain('wayfindr:expire-ai-usage-reservations');
});
