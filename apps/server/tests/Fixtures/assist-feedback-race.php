<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\User;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Observability\AssistFeedback;
use App\Support\Ai\Usage\AiUsageClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        require __DIR__.'/ai-assist-preparation.php';
        $payload = json_decode((string) getenv('WAYFINDR_AI_PREPARATION_RACE_PAYLOAD'), true, 32, JSON_THROW_ON_ERROR);
        $database = DB::connection()->getConfig('database');
        $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql' && $database === 'wayfindr_test';
        $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1
            && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;
        if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
            || DB::connection()->getConfig('host') !== '127.0.0.1' || (! $ci && ! $local)) {
            throw new RuntimeException('Fixture requires its explicitly guarded disposable database.');
        }
        config(['queue.default' => 'database', 'cache.default' => 'array', 'wayfindr.ai.usage' => $payload['limits']]);
        $clock = new AssistPreparationClock(CarbonImmutable::parse($payload['now'], 'UTC'));
        app()->instance(AiUsageClock::class, $clock);
        Carbon::setTestNow($clock->time);
        DB::statement("SET lock_timeout = '8s'");
        DB::statement("SET statement_timeout = '10s'");
        echo json_encode(['ready' => true, 'pid' => getmypid(), 'backend' => (int) DB::scalar('SELECT pg_backend_pid()')])."\n";
        fflush(STDOUT);
        stream_set_timeout(STDIN, 8);
        if (trim((string) fgets(STDIN)) !== 'go') {
            throw new RuntimeException('Start barrier not received.');
        }
        $actor = User::query()->findOrFail($payload['actor_id']);
        $conversation = Conversation::query()->find($payload['conversation_id']);
        try {
            if ($conversation === null) {
                throw new AiNetworkException('access_revoked');
            }
            $input = ['event' => $payload['operation'] === 'assessment' ? 'assessment' : 'viewed', 'generation' => $payload['generation'], ...$payload['feedback']];
            // Two different explicit first assessments deliberately conflict at revision zero.
            if ($payload['operation'] === 'assessment' && ($payload['feedback']['usefulness'] ?? '') === 'not_useful') {
                $input['usefulness'] = 'not_useful';
            }
            app(AssistFeedback::class)->record($actor, $conversation, $payload['purpose'], $input);
            $result = ['accepted' => true];
        } catch (AiNetworkException) {
            $result = ['accepted' => false];
        }
        echo json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $failure) {
        // No raw exception, SQL, connection topology, prompt or credential output.
        echo json_encode(['ok' => false, 'fixture_error' => $failure::class])."\n";
        exit(1);
    }
}
