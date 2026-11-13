<?php

declare(strict_types=1);

use App\Models\Account;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function aiUsageStateWorld(array $changes = []): array
{
    config()->set('wayfindr.ai.usage', [
        'daily_requests' => 100, 'daily_tokens' => 10_000, 'concurrency' => 2,
        'max_output_tokens' => 100, 'queue_ttl_seconds' => 300,
        'claim_ttl_seconds' => 30, 'dispatch_ttl_seconds' => 30,
        'retry_cooldown_seconds' => 60, ...$changes,
    ]);
    $clock = new class implements AiUsageClock
    {
        public CarbonImmutable $time;

        public function __construct()
        {
            $this->time = CarbonImmutable::parse('2026-10-15 23:59:50', 'UTC');
        }

        public function now(): CarbonImmutable
        {
            return $this->time;
        }
    };
    $account = Account::factory()->create();
    $connection = new ResolvedAgentCopilotConnection(
        (int) $account->id, 'account', str_repeat('a', 64), 'openai', 'synthetic-model',
        'https://private-topology.example.test/v1', '', 'synthetic-secret-key',
    );
    $ledger = new AiUsageLedger(app(AiUsagePolicy::class), $clock);

    return compact('clock', 'account', 'connection', 'ledger');
}

function aiUsageStateReserve(array $world, ?string $fingerprint = null, ?string $attempt = null, ?string $replacement = null)
{
    return $world['ledger']->reserve($world['connection'], 'configuration_probe', $attempt ?? (string) Str::uuid(), 10, 10,
        $fingerprint ?? hash('sha256', (string) Str::uuid()), replacementOf: $replacement);
}

function aiUsageStateDispatch(array $world, ?string $fingerprint = null): array
{
    $reservation = aiUsageStateReserve($world, $fingerprint);
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $reservation->attemptId);
    $dispatch = $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $reservation->attemptId, $claim);

    return compact('reservation', 'claim', 'dispatch');
}

function aiUsageStateFailure(Closure $operation, string $reason): void
{
    try {
        $operation();
    } catch (AiNetworkException $exception) {
        expect($exception->reason)->toBe($reason)
            ->and($exception->getPrevious())->toBeNull();

        return;
    }

    test()->fail('Expected usage refusal: '.$reason);
}

test('usage reservations charge both scopes only once and bind exact identity', function (): void {
    $world = aiUsageStateWorld();
    $fingerprint = str_repeat('b', 64);
    $first = aiUsageStateReserve($world, $fingerprint);
    $same = aiUsageStateReserve($world, $fingerprint, $first->attemptId);
    $equivalent = aiUsageStateReserve($world, $fingerprint);
    expect($same->reused)->toBeTrue()->and($equivalent->attemptId)->toBe($first->attemptId)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1);
    foreach (['installation', 'account'] as $scope) {
        $summary = $world['ledger']->summary($world['account']->id)[$scope];
        expect($summary['held_requests'])->toBe(1)->and($summary['held_tokens'])->toBe(20)
            ->and($summary['charged_requests'])->toBe(0)->and($summary['concurrency'])->toBe(0);
    }
    aiUsageStateFailure(fn () => aiUsageStateReserve($world, str_repeat('c', 64), $first->attemptId), 'usage_attempt_mismatch');
    expect(DB::table('ai_usage_attempts')->count())->toBe(1);
});

test('usage request token and output limits fail without a partial hold', function (array $limits, string $reason): void {
    $world = aiUsageStateWorld($limits);
    aiUsageStateFailure(fn () => aiUsageStateReserve($world), $reason);
    expect(DB::table('ai_usage_attempts')->count())->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->sum('held_tokens'))->toBe(0);
})->with([
    [['daily_requests' => 0], 'usage_request_limit'],
    [['daily_tokens' => 19], 'usage_token_limit'],
    [['concurrency' => 0], 'usage_concurrency_limit'],
    [['max_output_tokens' => 9], 'usage_output_limit'],
]);

test('usage installation budget spans accounts and connection rotations', function (): void {
    $world = aiUsageStateWorld(['daily_requests' => 1]);
    aiUsageStateReserve($world);
    $other = Account::factory()->create();
    $otherConnection = new ResolvedAgentCopilotConnection((int) $other->id, 'installation', str_repeat('c', 64), 'openai', 'other-model', '', '', 'other-key');
    aiUsageStateFailure(fn () => $world['ledger']->reserve($otherConnection, 'configuration_probe', (string) Str::uuid(), 10, 10, str_repeat('d', 64)), 'usage_request_limit');
    $rotated = new ResolvedAgentCopilotConnection((int) $world['account']->id, 'account', str_repeat('e', 64), 'openai', 'rotated-model', '', '', 'rotated-key');
    aiUsageStateFailure(fn () => $world['ledger']->reserve($rotated, 'configuration_probe', (string) Str::uuid(), 10, 10, str_repeat('f', 64)), 'usage_request_limit');
    expect(DB::table('ai_usage_attempts')->count())->toBe(1);
});

test('usage claim and dispatch are single use and prevent foreign revision access', function (): void {
    $world = aiUsageStateWorld();
    $receipt = aiUsageStateReserve($world);
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId);
    aiUsageStateFailure(fn () => $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId), 'usage_attempt_claimed');
    $rotated = new ResolvedAgentCopilotConnection((int) $world['account']->id, 'account', str_repeat('b', 64), 'openai', 'model', '', '', 'key');
    aiUsageStateFailure(fn () => $world['ledger']->beginDispatch($rotated, 'configuration_probe', $receipt->attemptId, $claim), 'usage_attempt_mismatch');
    aiUsageStateFailure(fn () => $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, (string) Str::uuid()), 'usage_attempt_mismatch');
    $dispatch = $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, $claim);
    aiUsageStateFailure(fn () => $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, $claim), 'usage_attempt_dispatched');
    aiUsageStateFailure(fn () => $world['ledger']->cancelUnsent($receipt->attemptId, $claim), 'usage_attempt_dispatched');
    expect($dispatch->attemptId)->toBe($receipt->attemptId)
        ->and($world['ledger']->summary($world['account']->id)['account']['charged_requests'])->toBe(1);
});

test('usage rereads narrowed policy before DNS claim and before dispatch', function (bool $afterClaim): void {
    $world = aiUsageStateWorld();
    $receipt = aiUsageStateReserve($world);
    $claim = $afterClaim ? $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId) : null;
    config()->set('wayfindr.ai.usage.daily_tokens', 0);
    aiUsageStateFailure(fn () => $afterClaim
        ? $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, $claim)
        : $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId), 'usage_token_limit');
    expect(DB::table('ai_usage_attempts')->value('state'))->toBe('cancelled')
        ->and((int) DB::table('ai_usage_periods')->sum('held_tokens'))->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->sum('charged_tokens'))->toBe(0);
})->with([false, true]);

test('known unsent failures release holds once and cache terminal work', function (): void {
    $world = aiUsageStateWorld();
    $receipt = aiUsageStateReserve($world, str_repeat('b', 64));
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId);
    $world['ledger']->cancelUnsent($receipt->attemptId, $claim);
    $world['ledger']->cancelUnsent($receipt->attemptId, $claim);
    $cached = aiUsageStateReserve($world, str_repeat('b', 64));
    expect($cached->state)->toBe('cancelled')->and($cached->attemptId)->toBe($receipt->attemptId)
        ->and((int) DB::table('ai_usage_periods')->sum('held_requests'))->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->sum('charged_requests'))->toBe(0);
});

test('unknown usage retains debit and concurrency until fixed expiry across midnight', function (): void {
    $world = aiUsageStateWorld(['concurrency' => 1]);
    $work = aiUsageStateDispatch($world);
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 'timeout');
    $world['clock']->time = $world['clock']->time->addSeconds(29);
    expect($world['ledger']->expire())->toBe(0);
    $next = aiUsageStateReserve($world);
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $next->attemptId);
    aiUsageStateFailure(fn () => $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $next->attemptId, $claim), 'usage_concurrency_limit');
    expect((int) DB::table('ai_usage_scopes')->where('scope_key', 'installation')->value('concurrency_in_use'))->toBe(1);
    $world['clock']->time = $world['clock']->time->addSeconds(2);
    expect($world['ledger']->expire())->toBe(1)->and($world['ledger']->expire())->toBe(0)
        ->and((int) DB::table('ai_usage_scopes')->sum('concurrency_in_use'))->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->where('period_day', '2026-10-15')->value('charged_tokens'))->toBe(20);
});

test('reported zero is known usage and partial usage remains conservatively charged', function (): void {
    $world = aiUsageStateWorld();
    $work = aiUsageStateDispatch($world);
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 5, null);
    $summary = $world['ledger']->summary($world['account']->id)['account'];
    expect($summary['charged_tokens'])->toBe(20)->and($summary['concurrency'])->toBe(1);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, null, 7);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 5, 7);
    aiUsageStateFailure(fn () => $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 6, 7), 'usage_receipt_mismatch');
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce);
    expect($world['ledger']->summary($world['account']->id)['account']['charged_tokens'])->toBe(12)
        ->and($world['ledger']->summary($world['account']->id)['observed_tokens'])->toBe(12)
        ->and((int) DB::table('ai_usage_scopes')->sum('concurrency_in_use'))->toBe(0);
    $zero = aiUsageStateDispatch($world);
    $world['ledger']->reconcile($zero['dispatch']->attemptId, $zero['dispatch']->dispatchNonce, 0, 0);
    expect($world['ledger']->summary($world['account']->id)['account']['charged_tokens'])->toBe(12)
        ->and($world['ledger']->summary($world['account']->id)['account']['charged_requests'])->toBe(2);
});

test('provider usage above its reservation is recorded rather than clipped into a free allowance', function (): void {
    $world = aiUsageStateWorld(['daily_tokens' => 100]);
    $work = aiUsageStateDispatch($world);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 120, 5);
    expect($world['ledger']->summary($world['account']->id)['account']['charged_tokens'])->toBe(125);
    aiUsageStateFailure(fn () => aiUsageStateReserve($world), 'usage_token_limit');
});

test('queued midnight rollover releases old holds and charges only the new dispatch day', function (): void {
    $world = aiUsageStateWorld();
    $receipt = aiUsageStateReserve($world);
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId);
    $world['clock']->time = $world['clock']->time->addSeconds(15);
    $dispatch = $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, $claim);
    expect($dispatch->dispatchDay)->toBe('2026-10-16');
    $old = DB::table('ai_usage_periods')->where('scope_key', 'installation')->where('period_day', '2026-10-15')->first();
    $new = $world['ledger']->summary($world['account']->id)['installation'];
    expect((int) $old->held_requests)->toBe(0)->and((int) $old->charged_requests)->toBe(0)
        ->and($new['held_tokens'])->toBe(0)->and($new['charged_requests'])->toBe(1)->and($new['charged_tokens'])->toBe(20);
});

test('midnight exhaustion commits old hold release before refusing dispatch', function (): void {
    $world = aiUsageStateWorld(['daily_requests' => 1]);
    $old = aiUsageStateReserve($world);
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $old->attemptId);
    $world['clock']->time = $world['clock']->time->addSeconds(15);
    aiUsageStateReserve($world);
    aiUsageStateFailure(fn () => $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $old->attemptId, $claim), 'usage_request_limit');
    expect((int) DB::table('ai_usage_periods')->where('period_day', '2026-10-15')->sum('held_requests'))->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->where('period_day', '2026-10-16')->sum('held_requests'))->toBe(2)
        ->and(DB::table('ai_usage_attempts')->where('attempt_id', $old->attemptId)->value('state'))->toBe('cancelled');
});

test('late duplicate old-day usage cannot credit a newer period', function (): void {
    $world = aiUsageStateWorld();
    $old = aiUsageStateDispatch($world);
    $world['ledger']->markUnknown($old['dispatch']->attemptId, $old['dispatch']->dispatchNonce);
    $world['clock']->time = $world['clock']->time->addSeconds(15);
    aiUsageStateDispatch($world);
    $world['ledger']->reconcile($old['dispatch']->attemptId, $old['dispatch']->dispatchNonce, 3, 4);
    $world['ledger']->reconcile($old['dispatch']->attemptId, $old['dispatch']->dispatchNonce, 3, 4);
    expect((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->where('period_day', '2026-10-15')->value('charged_tokens'))->toBe(7)
        ->and($world['ledger']->summary($world['account']->id)['installation']['charged_tokens'])->toBe(20)
        ->and($world['ledger']->summary($world['account']->id)['installation']['concurrency'])->toBe(1);
});

test('queued and claimed expiry releases holds and fences delayed workers', function (bool $claimed): void {
    $world = aiUsageStateWorld();
    $receipt = aiUsageStateReserve($world, str_repeat('b', 64));
    $claim = $claimed ? $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId) : null;
    $world['clock']->time = $world['clock']->time->addSeconds($claimed ? 31 : 301);
    expect($world['ledger']->expire())->toBe(1)->and($world['ledger']->expire())->toBe(0);
    aiUsageStateFailure(fn () => $claimed
        ? $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, $claim)
        : $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId), 'usage_attempt_terminal');
    expect(aiUsageStateReserve($world, str_repeat('b', 64))->state)->toBe('expired')
        ->and((int) DB::table('ai_usage_periods')->sum('held_tokens'))->toBe(0);
})->with([false, true]);

test('target expiry is fenced without relying on a bounded global sweep', function (bool $claimed): void {
    $world = aiUsageStateWorld(['daily_requests' => 200]);
    for ($index = 0; $index < 105; $index++) {
        $target = aiUsageStateReserve($world);
    }
    $claim = $claimed ? $world['ledger']->claim($world['connection'], 'configuration_probe', $target->attemptId) : null;
    $world['clock']->time = $world['clock']->time->addSeconds($claimed ? 31 : 301);
    aiUsageStateFailure(fn () => $claimed
        ? $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $target->attemptId, $claim)
        : $world['ledger']->claim($world['connection'], 'configuration_probe', $target->attemptId), 'usage_attempt_terminal');
    expect(DB::table('ai_usage_attempts')->where('attempt_id', $target->attemptId)->value('state'))->toBe('expired')
        ->and(DB::table('ai_usage_attempts')->where('state', 'reserved')->count())->toBe(104)
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->sum('held_requests'))->toBe(104)
        ->and((int) DB::table('ai_usage_periods')->sum('charged_requests'))->toBe(0);
})->with([false, true]);

test('failed work needs explicit replacement and database enforced cooldown', function (): void {
    $world = aiUsageStateWorld();
    $fingerprint = str_repeat('b', 64);
    $work = aiUsageStateDispatch($world, $fingerprint);
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 'timeout');
    $world['clock']->time = $world['clock']->time->addSeconds(59);
    expect(aiUsageStateReserve($world, $fingerprint)->attemptId)->toBe($work['dispatch']->attemptId);
    aiUsageStateFailure(fn () => aiUsageStateReserve($world, $fingerprint, replacement: $work['dispatch']->attemptId), 'usage_retry_cooldown');
    $world['clock']->time = $world['clock']->time->addSecond();
    $retry = aiUsageStateReserve($world, $fingerprint, replacement: $work['dispatch']->attemptId);
    $shared = aiUsageStateReserve($world, $fingerprint, replacement: $work['dispatch']->attemptId);
    expect($retry->attemptId)->not->toBe($work['dispatch']->attemptId)->and($shared->attemptId)->toBe($retry->attemptId)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(2);
});

test('successful explicit refresh coalesces without requiring a failed-work cooldown', function (): void {
    $world = aiUsageStateWorld();
    $fingerprint = str_repeat('b', 64);
    $work = aiUsageStateDispatch($world, $fingerprint);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 2, 3);
    $refresh = aiUsageStateReserve($world, $fingerprint, replacement: $work['dispatch']->attemptId);
    expect($refresh->state)->toBe('reserved')->and($refresh->attemptId)->not->toBe($work['dispatch']->attemptId)
        ->and(aiUsageStateReserve($world, $fingerprint, replacement: $work['dispatch']->attemptId)->attemptId)->toBe($refresh->attemptId);
});

test('later partial or failed receipts cannot shorten earlier retry advice', function (?int $laterAdvice, bool $complete): void {
    $world = aiUsageStateWorld();
    $work = aiUsageStateDispatch($world);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, null, null, 'timeout', 120);
    $retryAt = DB::table('ai_usage_attempts')->value('retry_not_before');
    $world['clock']->time = $world['clock']->time->addSeconds(70);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce,
        $complete ? 3 : null, $complete ? 4 : null, $complete ? 'provider_failed' : 'timeout', $laterAdvice);
    expect(DB::table('ai_usage_attempts')->value('retry_not_before'))->toBe($retryAt)
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe($complete ? 'failed' : 'unknown');
    $world['clock']->time = $world['clock']->time->addSeconds(49);
    aiUsageStateFailure(fn () => aiUsageStateReserve($world, $work['reservation']->workFingerprint, replacement: $work['dispatch']->attemptId), 'usage_retry_cooldown');
    $world['clock']->time = $world['clock']->time->addSecond();
    expect(aiUsageStateReserve($world, $work['reservation']->workFingerprint, replacement: $work['dispatch']->attemptId)->state)->toBe('reserved');
})->with([[null, false], [30, false], [120, false], [null, true], [30, true], [120, true]]);

test('complete usage from a failed task retains failure cooldown and provider advice', function (string $reason, ?int $advice, int $wait): void {
    $world = aiUsageStateWorld();
    $work = aiUsageStateDispatch($world);
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 3, 4, $reason, $advice);
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 3, 4, $reason, $advice);
    expect(DB::table('ai_usage_attempts')->value('state'))->toBe('failed')
        ->and($world['ledger']->summary($world['account']->id)['observed_tokens'])->toBe(7)
        ->and((int) DB::table('ai_usage_scopes')->sum('concurrency_in_use'))->toBe(0);
    $world['clock']->time = $world['clock']->time->addSeconds($wait - 1);
    aiUsageStateFailure(fn () => aiUsageStateReserve($world, $work['reservation']->workFingerprint, replacement: $work['dispatch']->attemptId), 'usage_retry_cooldown');
    $world['clock']->time = $world['clock']->time->addSecond();
    expect(aiUsageStateReserve($world, $work['reservation']->workFingerprint, replacement: $work['dispatch']->attemptId)->state)->toBe('reserved');
})->with([['invalid_output', null, 60], ['provider_failed', 120, 120]]);

test('expiry prioritizes concurrency over a backlog of expired queue holds', function (): void {
    $world = aiUsageStateWorld();
    for ($index = 0; $index < 5; $index++) {
        aiUsageStateReserve($world);
    }
    $work = aiUsageStateDispatch($world);
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce);
    $world['clock']->time = $world['clock']->time->addSeconds(301);
    expect($world['ledger']->expire(1))->toBe(1)
        ->and((int) DB::table('ai_usage_scopes')->sum('concurrency_in_use'))->toBe(0)
        ->and(DB::table('ai_usage_attempts')->where('state', 'reserved')->count())->toBe(5);
});

test('content-free live receipts survive account deletion and still reconcile installation usage', function (): void {
    $world = aiUsageStateWorld();
    $work = aiUsageStateDispatch($world);
    $world['account']->delete();
    expect(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and(DB::table('ai_usage_scopes')->where('scope_key', 'account:'.$world['account']->id)->exists())->toBeTrue();
    $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce);
    $world['ledger']->reconcile($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 3, 4);
    expect((int) DB::table('ai_usage_scopes')->sum('concurrency_in_use'))->toBe(0)
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('charged_tokens'))->toBe(7);
    $serialized = json_encode(DB::table('ai_usage_attempts')->get(), JSON_THROW_ON_ERROR);
    expect($serialized)->not->toContain('synthetic-secret-key', 'private-topology', 'synthetic-model');
});

test('strict ledger purposes and reasons refuse free text without retaining it', function (): void {
    $world = aiUsageStateWorld();
    aiUsageStateFailure(fn () => $world['ledger']->reserve($world['connection'], 'secret provider response', (string) Str::uuid(), 10, 10, str_repeat('b', 64)), 'usage_invalid_request');
    $work = aiUsageStateDispatch($world);
    aiUsageStateFailure(fn () => $world['ledger']->markUnknown($work['dispatch']->attemptId, $work['dispatch']->dispatchNonce, 'secret provider response'), 'usage_invalid_reason');
    expect(json_encode(DB::table('ai_usage_attempts')->get(), JSON_THROW_ON_ERROR))->not->toContain('secret provider response');
});

test('dispatch receipts reject serialization and redact capability nonces in debug output', function (): void {
    $work = aiUsageStateDispatch(aiUsageStateWorld());
    $dispatch = $work['dispatch'];
    expect(fn () => serialize($dispatch))->toThrow(LogicException::class)
        ->and(fn () => json_encode($dispatch, JSON_THROW_ON_ERROR))->toThrow(LogicException::class);
    ob_start();
    var_dump($dispatch);
    $debug = ob_get_clean();
    expect($debug)->toContain($dispatch->attemptId)->not->toContain($dispatch->dispatchNonce);
});

test('ledger refusal traces redact connection and nonce-bearing callback arguments', function (): void {
    $world = aiUsageStateWorld();
    $receipt = aiUsageStateReserve($world);
    $claim = $world['ledger']->claim($world['connection'], 'configuration_probe', $receipt->attemptId);
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        try {
            $world['ledger']->beginDispatch($world['connection'], 'configuration_probe', $receipt->attemptId, (string) Str::uuid());
            test()->fail('A mismatched dispatch claim must be refused.');
        } catch (AiNetworkException $exception) {
            foreach ($exception->getTrace() as $frame) {
                if (($frame['class'] ?? null) !== AiUsageLedger::class) {
                    continue;
                }
                foreach ($frame['args'] ?? [] as $argument) {
                    expect($argument)->not->toBeInstanceOf(ResolvedAgentCopilotConnection::class)
                        ->not->toBeInstanceOf(Closure::class)->not->toBeInstanceOf(stdClass::class);
                    if (is_string($argument)) {
                        expect($argument)->not->toBe($claim);
                    }
                }
            }
            expect((string) $exception)->not->toContain('synthetic-secret-key', 'private-topology.example.test');
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});

test('invalid free text is redacted before ledger argument validation throws', function (): void {
    $world = aiUsageStateWorld();
    $work = aiUsageStateDispatch($world);
    $secret = 'untrusted-provider-text-with-private-material';
    $attemptId = $work['dispatch']->attemptId;
    $nonce = $work['dispatch']->dispatchNonce;
    $operations = [
        fn () => $world['ledger']->reserve($world['connection'], $secret, (string) Str::uuid(), 10, 10, str_repeat('b', 64)),
        fn () => $world['ledger']->reserve($world['connection'], 'configuration_probe', (string) Str::uuid(), 10, 10, $secret),
        fn () => $world['ledger']->claim($world['connection'], 'configuration_probe', $secret),
        fn () => $world['ledger']->cancelUnsent($attemptId, $nonce, $secret),
        fn () => $world['ledger']->markUnknown($attemptId, $nonce, $secret),
        fn () => $world['ledger']->reconcile($attemptId, $nonce, 1, 1, $secret),
    ];
    $previous = ini_set('zend.exception_ignore_args', '0');
    try {
        foreach ($operations as $operation) {
            try {
                $operation();
                test()->fail('Invalid provider text must be refused.');
            } catch (AiNetworkException $exception) {
                expect((string) $exception)->not->toContain($secret);
                foreach ($exception->getTrace() as $frame) {
                    if (($frame['class'] ?? null) === AiUsageLedger::class) {
                        expect($frame['args'] ?? [])->not->toContain($secret);
                    }
                }
            }
        }
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
    }
});
