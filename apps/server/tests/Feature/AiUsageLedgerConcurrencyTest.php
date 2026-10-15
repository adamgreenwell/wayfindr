<?php

declare(strict_types=1);

use App\Models\Account;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/ai-usage-race.php';

trait GuardsAiUsageRaceDatabase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('True ledger contention requires PostgreSQL.');
        }
        $connection = config('database.connections.pgsql');
        $database = $connection['database'] ?? '';
        $ci = getenv('GITHUB_ACTIONS') === 'true'
            && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql'
            && $database === 'wayfindr_test' && (string) $connection['port'] === '5432';
        $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1
            && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;

        // This hook precedes DatabaseMigrations::migrate:fresh, including a
        // cached/misconfigured connection. Never destroy an existing local DB.
        if (! app()->environment('testing') || ($connection['url'] ?? '') !== ''
            || ($connection['host'] ?? '') !== '127.0.0.1' || (! $ci && ! $local)) {
            throw new RuntimeException('Refusing race migrations outside the explicit disposable test database.');
        }
    }
}

uses(GuardsAiUsageRaceDatabase::class);

function aiUsageRaceLimits(array $overrides = []): array
{
    return $overrides + [
        'daily_requests' => 100, 'daily_tokens' => 10000, 'concurrency' => 10,
        'max_output_tokens' => 10, 'queue_ttl_seconds' => 300,
        'claim_ttl_seconds' => 30, 'dispatch_ttl_seconds' => 30, 'retry_cooldown_seconds' => 60,
    ];
}

function aiUsageRaceWork(int $accountId, array $overrides = []): array
{
    return $overrides + [
        'action' => 'reserve', 'account_id' => $accountId,
        'attempt_id' => (string) Str::uuid(), 'fingerprint' => hash('sha256', Str::uuid()->toString()),
    ];
}

/** Independent PHP workers must demonstrably contend on the real singleton. */
function aiUsageRace(array $operations, array $limits, string $now = '2026-10-15 12:00:00', bool $firstCreation = false): array
{
    expect($operations)->toHaveCount(2);
    if ($firstCreation) {
        expect(DB::table('ai_usage_scopes')->where('scope_key', 'installation')->count())->toBe(0);
    } else {
        aiUsageRaceLedger($limits, $now)->summary($operations[0]['account_id']);
    }
    $config = DB::connection()->getConfig();
    $environment = [
        'APP_ENV' => 'testing', 'APP_KEY' => 'testtesttesttesttesttesttesttest',
        'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $config['host'],
        'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'],
        'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array', 'BROADCAST_CONNECTION' => 'null',
        'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
    ];
    $children = [];
    $gate = new PDO('pgsql:host=127.0.0.1;connect_timeout=2;port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password']);
    try {
        if (! $firstCreation) {
            $gate->beginTransaction();
            expect($gate->query("SELECT scope_key FROM ai_usage_scopes WHERE scope_key = 'installation' FOR UPDATE")->fetchColumn())->toBe('installation');
        }
        foreach ($operations as $operation) {
            $input = new InputStream;
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/ai-usage-race.php')], base_path(), $environment + [
                'WAYFINDR_AI_USAGE_RACE_PAYLOAD' => json_encode(compact('operation', 'limits', 'now') + ['hold_before_commit' => $firstCreation], JSON_THROW_ON_ERROR),
            ], $input, 15);
            $process->start();
            $children[] = ['process' => $process, 'input' => $input];
        }
        $deadline = microtime(true) + 6;
        do {
            $ready = [];
            foreach ($children as $child) {
                $lines = explode("\n", $child['process']->getOutput());
                $ready[] = json_decode($lines[0], true);
                $child['process']->checkTimeout();
            }
            if (count(array_filter($ready, fn ($receipt) => ($receipt['ready'] ?? false) === true)) === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect(array_column($ready, 'ready'))->toBe([true, true], 'Both worker processes must reach the start barrier.')
            ->and($ready[0]['pid'])->not->toBe($ready[1]['pid'])
            ->and($ready[0]['backend'])->not->toBe($ready[1]['backend']);
        foreach ($children as $child) {
            $child['input']->write("go\n");
            if (! $firstCreation) {
                $child['input']->close();
            }
        }
        $query = $gate->prepare($firstCreation
            ? "SELECT count(*) FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock' AND ? = ANY(pg_blocking_pids(pid))"
            : "SELECT count(*) FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'");
        $deadline = microtime(true) + 5;
        do {
            $holders = [];
            foreach ($children as $index => $child) {
                $child['process']->isRunning(); // Pump the buffered stdin start signal.
                $child['process']->checkTimeout();
                $lines = explode("\n", $child['process']->getOutput());
                if (($phase = json_decode($lines[1] ?? '', true)) && ($phase['holding'] ?? false)) {
                    $holders[] = $index;
                }
            }
            $gate->query('SELECT pg_stat_clear_snapshot()');
            $waiting = 0;
            if (! $firstCreation || count($holders) === 1) {
                $query->execute($firstCreation
                    ? [$ready[1 - $holders[0]]['backend'], $ready[$holders[0]]['backend']]
                    : [$ready[0]['backend'], $ready[1]['backend']]);
                $waiting = (int) $query->fetchColumn();
            }
            if ($waiting === ($firstCreation ? 1 : 2)) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($firstCreation) {
            expect($waiting)->toBe(1, 'The second first-time worker must block on the first worker before its mutex creation commits.')
                ->and(DB::table('ai_usage_scopes')->where('scope_key', 'installation')->count())->toBe(0);
            foreach ($children as $child) {
                $child['input']->write("commit\n");
                $child['input']->close();
                $child['process']->isRunning();
            }
        } else {
            expect($waiting)->toBe(2, 'Both PostgreSQL sessions must actually contend before the installation lock is released.');
            $gate->commit();
        }
        do {
            $running = false;
            foreach ($children as $child) {
                $running = $child['process']->isRunning() || $running;
                $child['process']->checkTimeout();
            }
            if ($running) {
                usleep(10000);
            }
        } while ($running);
        $results = [];
        foreach ($children as $child) {
            $child['process']->wait();
            $lines = explode("\n", trim($child['process']->getOutput()));
            $result = json_decode(end($lines), true, 16, JSON_THROW_ON_ERROR);
            expect($result)->not->toHaveKey('fixture_error', 'The synthetic child must not fail: '.($result['fixture_error'] ?? ''))
                ->and($child['process']->getExitCode())->toBe(0, 'The bounded child fixture must complete normally.');
            $results[] = $result;
        }

        return $results;
    } finally {
        if ($gate->inTransaction()) {
            $gate->rollBack();
        }
        foreach ($children as $child) {
            $child['input']->close();
            $child['process']->stop(0.1);
        }
    }
}

function aiUsageRacePrepare(array $work, array $limits, string $now = '2026-10-15 12:00:00', bool $dispatch = false): array
{
    $ledger = aiUsageRaceLedger($limits, $now);
    expect(aiUsageRaceOperation($ledger, $work)['ok'])->toBeTrue();
    $claim = aiUsageRaceOperation($ledger, ['action' => 'claim'] + $work);
    expect($claim['ok'])->toBeTrue();
    $work['claim_nonce'] = $claim['claim_nonce'];
    if ($dispatch) {
        $sent = aiUsageRaceOperation($ledger, ['action' => 'dispatch'] + $work);
        expect($sent['ok'])->toBeTrue();
        $work['dispatch_nonce'] = $sent['dispatch_nonce'];
    }

    return $work;
}

function aiUsageRacePeriod(string $scope, string $day = '2026-10-15'): array
{
    $row = (array) DB::table('ai_usage_periods')->where('scope_key', $scope)->where('period_day', $day)->first();

    return array_map(fn ($key) => (int) ($row[$key] ?? 0), ['held_requests', 'held_tokens', 'charged_requests', 'charged_tokens']);
}

test('concurrent first-time workers create one mutex and respect the first budget without a preseed', function (): void {
    $first = Account::factory()->create();
    $second = Account::factory()->create();
    $results = aiUsageRace([
        aiUsageRaceWork($first->id), aiUsageRaceWork($second->id),
    ], aiUsageRaceLimits(['daily_requests' => 1]), firstCreation: true);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_values(array_column($results, 'reason')))->toBe(['usage_request_limit'])
        ->and(DB::table('ai_usage_scopes')->where('scope_key', 'installation')->count())->toBe(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and(aiUsageRacePeriod('installation'))->toBe([1, 20, 0, 0]);
});

test('contending independent reservations cannot exceed either daily scope budget', function (string $scope, string $budget): void {
    $first = Account::factory()->create();
    $second = $scope === 'installation' ? Account::factory()->create() : $first;
    $limit = $budget === 'requests' ? 1 : 30;
    $limits = aiUsageRaceLimits($scope === 'installation' ? ['daily_'.$budget => $limit] : []);
    if ($scope === 'account') {
        DB::table('account_ai_usage_limits')->insert(['account_id' => $first->id, 'daily_'.$budget => $limit]);
    }
    $results = aiUsageRace([
        aiUsageRaceWork($first->id, ['source' => 'installation', 'revision' => str_repeat('b', 64)]),
        aiUsageRaceWork($second->id, ['source' => 'account', 'revision' => str_repeat('c', 64)]),
    ], $limits);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_values(array_column($results, 'reason')))->toBe(['usage_'.rtrim($budget, 's').'_limit'])
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and(aiUsageRacePeriod('installation'))->toBe([1, 20, 0, 0]);
    if ($scope === 'account') {
        expect(aiUsageRacePeriod('account:'.$first->id))->toBe([1, 20, 0, 0]);
    }
})->with([['installation', 'requests'], ['installation', 'tokens'], ['account', 'requests'], ['account', 'tokens']]);

test('contending equivalent work reuses one receipt and one hold', function (): void {
    $account = Account::factory()->create();
    $work = aiUsageRaceWork($account->id);
    $results = aiUsageRace([$work, ['attempt_id' => (string) Str::uuid()] + $work], aiUsageRaceLimits());
    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and($results[0]['attempt_id'])->toBe($results[1]['attempt_id'])
        ->and(array_column($results, 'reused'))->toContain(true, false)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and(aiUsageRacePeriod('installation'))->toBe([1, 20, 0, 0]);
});

test('contending workers cannot claim or dispatch the same attempt twice', function (string $action): void {
    $work = aiUsageRaceWork(Account::factory()->create()->id);
    $limits = aiUsageRaceLimits();
    if ($action === 'claim') {
        expect(aiUsageRaceOperation(aiUsageRaceLedger($limits, '2026-10-15 12:00:00'), $work)['ok'])->toBeTrue();
    } else {
        $work = aiUsageRacePrepare($work, $limits);
    }
    $results = aiUsageRace([['action' => $action] + $work, ['action' => $action] + $work], $limits);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_values(array_column($results, 'reason')))->toBe(['usage_attempt_'.($action === 'claim' ? 'claimed' : 'dispatched')])
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe($action === 'claim' ? 'claimed' : 'dispatched')
        ->and(aiUsageRacePeriod('installation'))->toBe($action === 'claim' ? [1, 20, 0, 0] : [0, 0, 1, 20]);
})->with(['claim', 'dispatch']);

test('contending dispatches enforce installation and account concurrency across connection revisions', function (string $scope): void {
    $first = Account::factory()->create();
    $second = $scope === 'installation' ? Account::factory()->create() : $first;
    $limits = aiUsageRaceLimits(['concurrency' => $scope === 'installation' ? 1 : 2]);
    if ($scope === 'account') {
        DB::table('account_ai_usage_limits')->insert(['account_id' => $first->id, 'concurrency' => 1]);
    }
    $a = aiUsageRacePrepare(aiUsageRaceWork($first->id, ['source' => 'installation']), $limits);
    $b = aiUsageRacePrepare(aiUsageRaceWork($second->id, ['revision' => str_repeat('d', 64)]), $limits);
    $results = aiUsageRace([['action' => 'dispatch'] + $a, ['action' => 'dispatch'] + $b], $limits);
    expect(array_column($results, 'ok'))->toContain(true, false)
        ->and(array_values(array_column($results, 'reason')))->toBe(['usage_concurrency_limit'])
        ->and((int) DB::table('ai_usage_scopes')->where('scope_key', 'installation')->value('concurrency_in_use'))->toBe(1)
        ->and(aiUsageRacePeriod('installation'))->toBe([0, 0, 1, 20])
        ->and(DB::table('ai_usage_attempts')->orderBy('state')->pluck('state')->all())->toBe(['cancelled', 'dispatched']);
    if ($scope === 'account') {
        expect((int) DB::table('ai_usage_scopes')->where('scope_key', 'account:'.$first->id)->value('concurrency_in_use'))->toBe(1);
    }
})->with(['installation', 'account']);

test('duplicate reconciliation and unknown-result races release and charge only once', function (string $otherAction): void {
    $limits = aiUsageRaceLimits();
    $work = aiUsageRacePrepare(aiUsageRaceWork(Account::factory()->create()->id), $limits, dispatch: true);
    $results = aiUsageRace([['action' => 'reconcile'] + $work, ['action' => $otherAction] + $work], $limits);
    expect(array_column($results, 'ok'))->toBe([true, true])
        ->and(DB::table('ai_usage_attempts')->value('state'))->toBe('completed')
        ->and((int) DB::table('ai_usage_scopes')->where('scope_key', 'installation')->value('concurrency_in_use'))->toBe(0)
        ->and(aiUsageRacePeriod('installation'))->toBe([0, 0, 1, 7])
        ->and(aiUsageRacePeriod('account:'.$work['account_id']))->toBe([0, 0, 1, 7]);
})->with(['reconcile', 'unknown']);

test('unknown dispatches hold concurrency until expiry and competing expiry releases once', function (): void {
    $limits = aiUsageRaceLimits(['concurrency' => 1]);
    $work = aiUsageRacePrepare(aiUsageRaceWork(Account::factory()->create()->id), $limits, dispatch: true);
    expect(aiUsageRaceOperation(aiUsageRaceLedger($limits, '2026-10-15 12:00:01'), ['action' => 'unknown'] + $work)['ok'])->toBeTrue();
    $other = aiUsageRacePrepare(aiUsageRaceWork($work['account_id']), $limits, '2026-10-15 12:00:20');
    $beforeExpiry = aiUsageRaceLedger($limits, '2026-10-15 12:00:29');
    expect($beforeExpiry->expire(100))->toBe(0)
        ->and(aiUsageRaceOperation($beforeExpiry, ['action' => 'dispatch'] + $other))->toBe(['ok' => false, 'reason' => 'usage_concurrency_limit'])
        ->and((int) DB::table('ai_usage_scopes')->where('scope_key', 'installation')->value('concurrency_in_use'))->toBe(1);
    $results = aiUsageRace([['action' => 'expire'] + $work, ['action' => 'expire'] + $work], $limits, '2026-10-15 12:00:31');
    expect(array_sum(array_column($results, 'expired')))->toBe(1)
        ->and((int) DB::table('ai_usage_scopes')->where('scope_key', 'installation')->value('concurrency_in_use'))->toBe(0)
        ->and(aiUsageRacePeriod('installation'))->toBe([0, 0, 1, 20]);
});

test('midnight reservation racing an old completion cannot credit the new UTC budget', function (): void {
    $limits = aiUsageRaceLimits(['daily_requests' => 1, 'daily_tokens' => 20]);
    $account = Account::factory()->create();
    $old = aiUsageRacePrepare(aiUsageRaceWork($account->id), $limits, '2026-10-15 23:59:50', true);
    $new = aiUsageRaceWork($account->id);
    $results = aiUsageRace([['action' => 'reconcile'] + $old, $new], $limits, '2026-10-16 00:00:00');
    expect(array_column($results, 'ok'))->toBe([true, true]);
    foreach (['installation', 'account:'.$account->id] as $scope) {
        expect(aiUsageRacePeriod($scope, '2026-10-15'))->toBe([0, 0, 1, 7])
            ->and(aiUsageRacePeriod($scope, '2026-10-16'))->toBe([1, 20, 0, 0]);
    }
});
