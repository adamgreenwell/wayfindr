<?php

declare(strict_types=1);

use App\Models\AiAssistPreparationReceipt;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';

trait GuardsAiPreparationRaceDatabase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Preparation contention requires PostgreSQL.');
        }
        $connection = config('database.connections.pgsql');
        $database = $connection['database'] ?? '';
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql'
            && $database === 'wayfindr_test' && (string) $connection['port'] === '5432';
        $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1
            && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;
        if (! app()->environment('testing') || ($connection['url'] ?? '') !== ''
            || ($connection['host'] ?? '') !== '127.0.0.1' || (! $ci && ! $local)) {
            throw new RuntimeException('Refusing preparation migrations outside the explicit disposable database.');
        }
    }
}
uses(GuardsAiPreparationRaceDatabase::class);
afterEach(fn () => Carbon::setTestNow());

/** Both independent processes must demonstrably block before either proceeds. */
function assistPreparationRace(array $world, string $operation): array
{
    $connection = DB::connection()->getConfig();
    $gate = new PDO('pgsql:host=127.0.0.1;connect_timeout=2;port='.$connection['port'].';dbname='.$connection['database'], $connection['username'], $connection['password']);
    $children = [];
    try {
        $gate->beginTransaction();
        if ($operation === 'ensure') {
            expect($gate->query("SELECT scope_key FROM ai_usage_scopes WHERE scope_key = 'installation' FOR UPDATE")->fetchColumn())->toBe('installation');
        } else {
            $table = (new $world['class'])->getTable();
            expect(in_array($table, ['conversation_copilot_summaries', 'conversation_copilot_reply_drafts', 'conversation_copilot_knowledge_suggestions', 'conversation_copilot_ticket_suggestions'], true))->toBeTrue();
            $query = $gate->prepare('SELECT generation FROM '.$table.' WHERE conversation_id = ? FOR UPDATE');
            $query->execute([$world['conversation']->id]);
            expect($query->fetchColumn())->not->toBeFalse();
        }
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_URL' => 'http://localhost:8000',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
            'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'];
        foreach ([$world['owner'], $world['agent']] as $actor) {
            $input = new InputStream;
            $payload = ['operation' => $operation, 'actor_id' => $actor->id, 'conversation_id' => $world['conversation']->id,
                'purpose' => $world['purpose'], 'workspace' => $world['workspace'], 'job' => $world['job'],
                'limits' => config('wayfindr.ai.usage'), 'now' => $world['clock']->time->toIso8601String()];
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/ai-assist-preparation-race.php')], base_path(),
                $environment + ['WAYFINDR_AI_PREPARATION_RACE_PAYLOAD' => json_encode($payload, JSON_THROW_ON_ERROR)], $input, 20);
            $process->start();
            $children[] = compact('process', 'input');
        }
        $ready = [];
        $deadline = microtime(true) + 6;
        do {
            foreach ($children as $index => $child) {
                $ready[$index] = json_decode(explode("\n", $child['process']->getOutput())[0], true);
                $child['process']->checkTimeout();
            }
            if (count(array_filter($ready, fn ($row) => ($row['ready'] ?? false) === true)) === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect(array_column($ready, 'ready'))->toBe([true, true], 'Both preparation workers must reach the start barrier.')
            ->and($ready[0]['pid'])->not->toBe($ready[1]['pid'])->and($ready[0]['backend'])->not->toBe($ready[1]['backend']);
        foreach ($children as $child) {
            $child['input']->write("go\n");
            $child['input']->close();
        }
        $waiting = 0;
        $deadline = microtime(true) + 5;
        $query = $gate->prepare("SELECT count(*) FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'");
        do {
            foreach ($children as $child) {
                $child['process']->isRunning();
                $child['process']->checkTimeout();
            }
            $gate->query('SELECT pg_stat_clear_snapshot()');
            $query->execute([$ready[0]['backend'], $ready[1]['backend']]);
            $waiting = (int) $query->fetchColumn();
            if ($waiting === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($waiting)->toBe(2, 'Both independent preparation sessions must actually contend on the admission or claim lock.');
        $gate->commit();
        $results = [];
        foreach ($children as $child) {
            $child['process']->wait();
            $lines = explode("\n", trim($child['process']->getOutput()));
            $result = json_decode(end($lines), true, 32, JSON_THROW_ON_ERROR);
            expect($result)->not->toHaveKey('fixture_error')->and($child['process']->getExitCode())->toBe(0);
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

test('concurrent equivalent opens create one generation reservation and queued database job', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    app(AiUsageLedger::class)->summary($world['account']->id);
    expect($world['class']::query()->count())->toBe(0);
    $results = assistPreparationRace($world, 'ensure');
    expect(array_unique(array_column($results, 'generation')))->toHaveCount(1, 'Equivalent concurrent viewers must share one generation.')
        ->and(array_sum(array_column($results, 'queued')))->toBe(1)
        ->and($world['class']::query()->count())->toBe(1)->and(AiAssistPreparationReceipt::query()->count())->toBe(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
    expect(DB::table('jobs')->sole()->payload)->not->toContain('Synthetic checkout request', 'synthetic-not-a-secret');
})->with(['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion']);

test('contending duplicate deliveries claim one generation and charge one synthetic provider call', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    assistPreparationEnsure($world);
    $results = assistPreparationRace($world, 'worker');
    expect(array_sum(array_column($results, 'provider_calls')))->toBe(1, 'Duplicate workers must admit exactly one provider call.')
        ->and($world['class']::query()->sole()->status)->toBe('ready')
        ->and(AiAssistPreparationReceipt::query()->sole()->status)->toBe('ready')
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)
        ->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('completed')
        ->and((int) DB::table('ai_usage_periods')->where('scope_key', 'installation')->value('charged_requests'))->toBe(1);
})->with(['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion']);

test('queue failure owns the erasure Account mutex before artifact and audit updates', function (string $purpose): void {
    $world = assistPreparationWorld($purpose);
    $connection = DB::connection()->getConfig();
    $probe = new PDO('pgsql:host=127.0.0.1;connect_timeout=2;port='.$connection['port'].';dbname='.$connection['database'], $connection['username'], $connection['password']);
    $statement = $probe->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE NOWAIT');
    $table = (new $world['class'])->getTable();
    $observed = [];
    DB::listen(function (QueryExecuted $query) use ($statement, $table, $world, &$observed): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, $table)
            && in_array('queue', $query->bindings, true)) {
            try {
                $statement->execute([$world['account']->id]);
                $observed[] = false;
            } catch (PDOException $failure) {
                $observed[] = $failure->getCode() === '55P03';
            }
        }
    });
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Synthetic queue acknowledgement failure.'));
    assistPreparationEnsure($world);
    expect($observed)->toBe([true], 'Queue failure must own Account before changing artifacts or creating audits, so erasure cannot deadlock or be bypassed.');
    expect($world['class']::query()->sole()->status)->toBe('failed');
})->with(['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion']);
