<?php

declare(strict_types=1);
use App\Jobs\GenerateConversationDraftRefinement;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Refinement\DraftRefinementAction;
use App\Support\Ai\Refinement\DraftRefinementInput;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Usage\AiUsageClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        require __DIR__.'/ai-assist-preparation.php';
        require __DIR__.'/draft-refinement.php';
        $payload = json_decode((string) getenv('WAYFINDR_PRIVATE_DRAFT_RACE'), true, 16, JSON_THROW_ON_ERROR);
        $db = DB::connection();
        $database = $db->getConfig('database');
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql' && $database === 'wayfindr_test';
        $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1 && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;
        if (! $app->environment('testing') || $db->getDriverName() !== 'pgsql' || $db->getConfig('host') !== '127.0.0.1' || (! $ci && ! $local)) {
            throw new LogicException('Private race fixture requires a disposable PostgreSQL database.');
        }
        config(['queue.default' => 'database', 'cache.default' => 'array', 'wayfindr.ai.usage' => $payload['limits']]);
        $clock = new AssistPreparationClock(CarbonImmutable::parse($payload['now'], 'UTC'));
        app()->instance(AiUsageClock::class, $clock);
        Carbon\Carbon::setTestNow($clock->time);
        $db->statement("SET lock_timeout = '8s'");
        $db->statement("SET statement_timeout = '10s'");
        echo json_encode(['ready' => true, 'pid' => getmypid(), 'backend' => (int) $db->scalar('SELECT pg_backend_pid()')])."\n";
        fflush(STDOUT);
        stream_set_timeout(STDIN, 8);
        if (trim((string) fgets(STDIN)) !== 'go') {
            throw new LogicException('No start barrier.');
        }
        if ($payload['operation'] === 'request') {
            try {
                $state = app(DraftRefinements::class)->request(
                    User::query()->findOrFail($payload['actor_id']), Conversation::query()->findOrFail($payload['conversation_id']),
                    new DraftRefinementInput('It may take 3 days.', $payload['draft_revision'], DraftRefinementAction::Clarify));
                $result = ['accepted' => true, 'generation' => $state['generation']];
            } catch (AiNetworkException $exception) {
                if ($exception->reason !== 'usage_retry_cooldown') {
                    throw $exception;
                }
                $result = ['accepted' => false, 'reason' => 'usage_retry_cooldown'];
            }
        } else {
            $provider = refinementLifecycleProvider();
            app()->instance(AgentCopilotProvider::class, $provider);
            app()->call([new GenerateConversationDraftRefinement($payload['id'], $payload['generation']), 'handle']);
            $result = ['provider_calls' => $provider->calls, 'dispatches' => $provider->dispatches];
        }
        echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $exception) {
        echo json_encode(['fixture_error' => $exception::class])."\n";
        exit(1);
    }
}
