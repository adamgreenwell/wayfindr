<?php

declare(strict_types=1);

use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\Account;
use App\Models\AiAssistFeedback;
use App\Models\ConversationDraftRefinement;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Observability\AssistFeedback;
use App\Support\Ai\Refinement\DraftRefinements;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/ai-assist-preparation.php';
require_once __DIR__.'/../Fixtures/draft-refinement.php';

trait GuardsAssistFeedbackRaceDatabase
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
uses(GuardsAssistFeedbackRaceDatabase::class);
afterEach(fn () => Carbon::setTestNow());

/** Both independent processes must demonstrably block before either proceeds. */
function assistFeedbackRace(array $world, string $operation): array
{
    $connection = DB::connection()->getConfig();
    $gate = new PDO('pgsql:host=127.0.0.1;connect_timeout=2;port='.$connection['port'].';dbname='.$connection['database'], $connection['username'], $connection['password']);
    $children = [];
    try {
        $gate->beginTransaction();
        $query = $gate->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE');
        $query->execute([$world['account']->id]);
        expect((int) $query->fetchColumn())->toBe($world['account']->id);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_URL' => 'http://localhost:8000',
            'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
            'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'];
        foreach ([$world['owner'], $world['owner']] as $childIndex => $actor) {
            $input = new InputStream;
            $payload = ['operation' => $operation, 'actor_id' => $actor->id, 'conversation_id' => $world['conversation']->id,
                'purpose' => $world['purpose'], 'generation' => $world['class']::query()->sole()->generation, 'feedback' => $operation === 'assessment' ? [...$world['feedback'], 'usefulness' => $childIndex === 0 ? 'useful' : 'not_useful'] : [], 'workspace' => $world['workspace'], 'job' => $world['job'],
                'limits' => config('wayfindr.ai.usage'), 'now' => $world['clock']->time->toIso8601String()];
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/assist-feedback-race.php')], base_path(),
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
        if ($operation === 'erase') {
            $query = $gate->prepare('DELETE FROM conversations WHERE id = ?');
            $query->execute([$world['conversation']->id]);
        } elseif ($operation === 'supersede') {
            $query = $gate->prepare('UPDATE conversation_copilot_reply_drafts SET generation = ? WHERE conversation_id = ?');
            $query->execute([(string) Str::uuid(), $world['conversation']->id]);
        }
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

test('duplicate feedback processes serialize to one observation and conflicting assessments cannot overwrite', function (string $operation): void {
    $w = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($w);
    assistPreparationRun($w);
    $generation = $w['class']::query()->sole()->generation;
    if ($operation === 'assessment') {
        app(AssistFeedback::class)->record($w['owner'], $w['conversation'], $w['purpose'], ['event' => 'viewed', 'generation' => $generation]);
        $w['feedback'] = ['usefulness' => 'useful', 'correction' => 'unassessed', 'expected_revision' => 0];
    }
    $results = assistFeedbackRace($w, $operation);
    if ($operation === 'assessment') {
        expect(array_column($results, 'accepted'))->toContain(true, false);
        expect(AiAssistFeedback::query()->sole()->revision)->toBe(1);
    } else {
        expect(array_column($results, 'accepted'))->toBe([true, true]);
        expect(AiAssistFeedback::query()->sole()->viewed_at)->not->toBeNull();
    }
    expect(AiAssistFeedback::query()->count())->toBe(1);
})->with(['viewed', 'assessment']);

test('late independently blocked feedback cannot recreate erased or superseded work', function (string $operation): void {
    $w = assistPreparationWorld('conversation_reply_draft');
    assistPreparationEnsure($w);
    assistPreparationRun($w);
    $results = assistFeedbackRace($w, $operation);
    expect(array_column($results, 'accepted'))->toBe([false, false])->and(AiAssistFeedback::query()->count())->toBe(0);
})->with(['erase', 'supersede']);

test('feedback completes independently of held installation and unrelated account locks', function (string $purpose): void {
    $private = str_starts_with($purpose, 'private');
    $w = assistPreparationWorld($private ? 'conversation_reply_draft' : $purpose, $private ? 'manual' : 'prepare');
    $operation = 'viewed';
    $feedback = [];
    if ($private) {
        $service = app(DraftRefinements::class);
        $input = refinementLifecycleInput();
        $grant = $service->authorize($w['agent'], $w['conversation'], $input->draftRevision);
        $service->request($w['agent'], $w['conversation'], $input, $grant['generation']);
        $row = ConversationDraftRefinement::query()->sole();
        app()->instance(AgentCopilotProvider::class, refinementLifecycleProvider());
        app()->call([new GenerateConversationDraftRefinement($row->id, $row->generation), 'handle']);
        $w['owner'] = $w['agent'];
        $w['purpose'] = AssistFeedback::PRIVATE_PURPOSE;
        $generation = $row->generation;
        if ($purpose === 'private_applied') {
            app(AssistFeedback::class)->record($w['owner'], $w['conversation'], $w['purpose'], ['event' => 'viewed', 'generation' => $generation]);
            $applied = $service->show($w['owner'], $w['conversation'], $generation, $input->draftRevision, true);
            $operation = 'used';
            $feedback = ['receipt' => $applied['feedback_receipt']];
        }
    } else {
        assistPreparationEnsure($w);
        assistPreparationRun($w);
        $generation = $w['class']::query()->sole()->generation;
    }
    $unrelated = Account::factory()->create();
    $scopes = DB::table('ai_usage_scopes')->orderBy('scope_key')->get()->toJson();
    $connection = DB::connection()->getConfig();
    $gate = new PDO('pgsql:host=127.0.0.1;connect_timeout=2;port='.$connection['port'].';dbname='.$connection['database'], $connection['username'], $connection['password']);
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_URL' => 'http://localhost:8000',
        'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
        'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
        'BROADCAST_CONNECTION' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'];
    $payload = ['operation' => $operation, 'actor_id' => $w['owner']->id, 'conversation_id' => $w['conversation']->id,
        'purpose' => $w['purpose'], 'generation' => $generation, 'feedback' => $feedback,
        'limits' => config('wayfindr.ai.usage'), 'now' => $w['clock']->time->toIso8601String()];
    $inputStream = new InputStream;
    $process = new Process([PHP_BINARY, base_path('tests/Fixtures/assist-feedback-race.php')], base_path(),
        $environment + ['WAYFINDR_AI_PREPARATION_RACE_PAYLOAD' => json_encode($payload, JSON_THROW_ON_ERROR)], $inputStream, 20);
    try {
        $gate->beginTransaction();
        expect($gate->query("SELECT scope_key FROM ai_usage_scopes WHERE scope_key = 'installation' FOR UPDATE")->fetchColumn())->toBe('installation');
        $lock = $gate->prepare('SELECT id FROM accounts WHERE id = ? FOR UPDATE');
        $lock->execute([$unrelated->id]);
        expect((int) $lock->fetchColumn())->toBe($unrelated->id);
        $process->start();
        $deadline = microtime(true) + 6;
        do {
            $ready = json_decode(explode("\n", $process->getOutput())[0], true);
            if (($ready['ready'] ?? false) === true) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        expect($ready['ready'] ?? false)->toBeTrue('The independent worker must reach the start barrier.');
        $inputStream->write("go\n");
        $inputStream->close();
        $deadline = microtime(true) + 4;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect($process->isRunning())->toBeFalse('Feedback must finish while unrelated account and installation locks remain held.');
        $lines = explode("\n", trim($process->getOutput()));
        $result = json_decode(end($lines), true, 32, JSON_THROW_ON_ERROR);
        expect($result)->toBe(['ok' => true, 'accepted' => true])->and($process->getExitCode())->toBe(0);
        expect(AiAssistFeedback::query()->count())->toBe(1)
            ->and(AiAssistFeedback::query()->sole()->used_at !== null)->toBe($operation === 'used')
            ->and(DB::table('ai_usage_scopes')->orderBy('scope_key')->get()->toJson())->toBe($scopes);
    } finally {
        if ($gate->inTransaction()) {
            $gate->rollBack();
        }
        $inputStream->close();
        $process->stop(0.1);
    }
})->with(['conversation_summary', 'conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion', 'private_ready', 'private_applied']);
