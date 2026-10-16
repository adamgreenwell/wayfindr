<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountAiAssistPolicy;
use App\Models\Site;
use App\Models\User;
use App\Support\Ai\Policy\AiAssistPolicies;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

trait GuardsAiAssistPolicyRaceDatabase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('True policy contention requires PostgreSQL.');
        }
        $connection = config('database.connections.pgsql');
        $database = $connection['database'] ?? '';
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql'
            && $database === 'wayfindr_test' && (string) $connection['port'] === '5432';
        $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1
            && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;
        if (! app()->environment('testing') || ($connection['url'] ?? '') !== ''
            || ($connection['host'] ?? '') !== '127.0.0.1' || (! $ci && ! $local)) {
            throw new RuntimeException('Refusing policy race migrations outside the explicit disposable database.');
        }
    }
}

uses(GuardsAiAssistPolicyRaceDatabase::class);

test('independent stale policy editor waits for concurrent account mutation and rechecks authority', function (string $mutation): void {
    $owner = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);
    Site::factory()->for($owner->account)->create();
    $service = app(AiAssistPolicies::class);
    $values = ['mode' => 'off', 'summary' => true, 'reply_draft' => true, 'relevant_articles' => false,
        'ticket_details' => true, 'article_context_consent' => false, 'max_context_characters' => null];
    $service->saveAccount($owner, (int) $owner->account_id, $values, $service->accountToken((int) $owner->account_id));
    $token = $service->accountToken((int) $owner->account_id);
    $config = DB::connection()->getConfig();
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
        'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
        'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
        'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'];
    $children = [];
    try {
        foreach (['mutate', 'write'] as $role) {
            $input = new InputStream;
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/ai-assist-policy-race.php')], base_path(), $environment + [
                'WAYFINDR_AI_POLICY_RACE_PAYLOAD' => json_encode(['role' => $role, 'actor_id' => $owner->id,
                    'token' => $token, 'mutation' => $mutation], JSON_THROW_ON_ERROR),
            ], $input, 15);
            $process->start();
            $children[] = ['process' => $process, 'input' => $input];
        }
        $deadline = microtime(true) + 6;
        do {
            $ready = [];
            foreach ($children as $child) {
                $ready[] = json_decode(explode("\n", $child['process']->getOutput())[0], true);
                $child['process']->checkTimeout();
            }
            if (count(array_filter($ready, fn ($value): bool => ($value['ready'] ?? false) === true)) === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect(array_column($ready, 'ready'))->toBe([true, true])->and($ready[0]['pid'])->not->toBe($ready[1]['pid'])
            ->and($ready[0]['backend'])->not->toBe($ready[1]['backend']);
        $children[0]['input']->write("go\n");
        $deadline = microtime(true) + 5;
        do {
            $children[0]['process']->isRunning();
            $phase = json_decode(explode("\n", $children[0]['process']->getOutput())[1] ?? '', true);
            if (($phase['holding'] ?? false) === true) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($phase['holding'] ?? false)->toBeTrue('The mutation must hold the Account lock before the stale writer starts.');
        $children[1]['input']->write("go\n");
        $children[1]['input']->close();
        $deadline = microtime(true) + 5;
        do {
            $children[1]['process']->isRunning();
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = (int) DB::scalar("SELECT count(*) FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock' AND ? = ANY(pg_blocking_pids(pid))", [$ready[1]['backend'], $ready[0]['backend']]);
            if ($waiting === 1) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBe(1, 'The stale policy writer must actually contend on the other process Account lock.');
        $children[0]['input']->write("commit\n");
        $children[0]['input']->close();
        $results = [];
        foreach ($children as $child) {
            $child['process']->wait();
            $lines = explode("\n", trim($child['process']->getOutput()));
            $result = json_decode(end($lines), true, 16, JSON_THROW_ON_ERROR);
            expect($result)->not->toHaveKey('fixture_error', 'The bounded synthetic policy worker must complete normally.')
                ->and($child['process']->getExitCode())->toBe(0);
            $results[] = $result;
        }
        expect($results[0])->toBe(['ok' => true])
            ->and($results[1])->toBe(['ok' => false, 'reason' => $mutation === 'revoke' ? 'access_revoked' : 'policy_conflict'])
            ->and(AccountAiAssistPolicy::query()->find($owner->account_id)->ticket_details)->toBeTrue('A stale editor must never publish its rejected settings.');
    } finally {
        foreach ($children as $child) {
            $child['input']->close();
            $child['process']->stop(0.1);
        }
    }
})->with(['edit', 'revoke']);
