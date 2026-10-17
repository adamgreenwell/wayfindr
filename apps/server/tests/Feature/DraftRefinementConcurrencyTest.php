<?php

declare(strict_types=1);

use App\Models\ConversationDraftRefinement;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Usage\AiUsageLedger;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
require_once __DIR__.'/../Fixtures/draft-refinement.php';

trait GuardsPrivateRefinementRaceDatabase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Private refinement contention requires PostgreSQL.');
        }
        $connection = config('database.connections.pgsql');
        $database = $connection['database'] ?? '';
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql' && $database === 'wayfindr_test' && (string) $connection['port'] === '5432';
        $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1 && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;
        if (! app()->environment('testing') || ($connection['url'] ?? '') !== '' || ($connection['host'] ?? '') !== '127.0.0.1' || (! $ci && ! $local)) {
            throw new LogicException('Refuse private refinement migrations outside the disposable database.');
        }
    }
}
uses(GuardsPrivateRefinementRaceDatabase::class);
afterEach(fn () => Carbon::setTestNow());

function privateRefinementRace(array $world, string $operation, ?ConversationDraftRefinement $row = null): array
{
    $connection = DB::connection()->getConfig();
    $gate = new PDO('pgsql:host=127.0.0.1;connect_timeout=2;port='.$connection['port'].';dbname='.$connection['database'], $connection['username'], $connection['password']);
    $children = [];
    try {
        $gate->beginTransaction();
        expect($gate->query("SELECT scope_key FROM ai_usage_scopes WHERE scope_key = 'installation' FOR UPDATE")->fetchColumn())->toBe('installation');
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_URL' => 'http://localhost:8000', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null'];
        $revision = (string) Str::uuid();
        foreach ([0, 1] as $index) {
            $input = new InputStream;
            $payload = ['operation' => $operation, 'actor_id' => $world['agent']->id, 'conversation_id' => $world['conversation']->id,
                'draft_revision' => $revision, 'id' => $row?->id, 'generation' => $row?->generation, 'limits' => config('wayfindr.ai.usage'), 'now' => $world['clock']->time->toIso8601String()];
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/draft-refinement-race.php')], base_path(),
                $environment + ['WAYFINDR_PRIVATE_DRAFT_RACE' => json_encode($payload, JSON_THROW_ON_ERROR)], $input, 20);
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
            if (count(array_filter($ready, fn ($value) => ($value['ready'] ?? false) === true)) === 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect(array_column($ready, 'ready'))->toBe([true, true])->and($ready[0]['backend'])->not->toBe($ready[1]['backend']);
        foreach ($children as $child) {
            $child['input']->write("go\n");
            $child['input']->close();
        }
        $query = $gate->prepare("SELECT count(*) FROM pg_stat_activity WHERE pid IN (?, ?) AND wait_event_type = 'Lock'");
        $waiting = 0;
        $deadline = microtime(true) + 5;
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
        expect($waiting)->toBe(2, 'Both independent private workers must actually contend at admission.');
        $gate->commit();
        $results = [];
        foreach ($children as $child) {
            $child['process']->wait();
            $lines = explode("\n", trim($child['process']->getOutput()));
            $result = json_decode(end($lines), true, 16, JSON_THROW_ON_ERROR);
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

test('contending captures for the same private owner admit one attempt and queue no draft text', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    app(AiUsageLedger::class)->summary($w['account']->id);
    $results = privateRefinementRace($w, 'request');
    expect(array_sum(array_column($results, 'accepted')))->toBe(1)->and(ConversationDraftRefinement::query()->count())->toBe(1)
        ->and(DB::table('ai_usage_attempts')->count())->toBe(1)->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->sole()->payload)->not->toContain('It may take', 'synthetic-not-a-secret');
});

test('independent duplicate private workers admit one provider call and publish one preview', function (): void {
    $w = assistPreparationWorld('conversation_reply_draft', 'manual');
    app(DraftRefinements::class)->request($w['agent'], $w['conversation'], refinementLifecycleInput());
    $results = privateRefinementRace($w, 'worker', ConversationDraftRefinement::query()->sole());
    expect(array_sum(array_column($results, 'provider_calls')))->toBe(1)->and(array_sum(array_column($results, 'dispatches')))->toBe(1)
        ->and(ConversationDraftRefinement::query()->sole()->status)->toBe('ready')->and(ConversationDraftRefinement::query()->sole()->input)->toBeNull()
        ->and(DB::table('conversation_draft_refinement_sends')->count())->toBe(0)->and(DB::table('ai_usage_attempts')->sole()->state)->toBe('completed');
});
