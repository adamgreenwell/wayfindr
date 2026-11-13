<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Support\Ai\Policy\AiAssistPolicies;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Synthetic Off policy only. Never configure, probe or call a provider.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $payload = json_decode((string) getenv('WAYFINDR_AI_POLICY_RACE_PAYLOAD'), true, 16, JSON_THROW_ON_ERROR);
    $connection = DB::connection()->getConfig();
    $database = $connection['database'] ?? '';
    $ci = getenv('GITHUB_ACTIONS') === 'true' && getenv('WAYFINDR_EXPECTED_DB_DRIVER') === 'pgsql'
        && $database === 'wayfindr_test' && (string) $connection['port'] === '5432';
    $local = preg_match('/\Awf_ai_usage_race_[a-f0-9]{16}\z/', $database) === 1
        && getenv('WAYFINDR_AI_USAGE_RACE_DATABASE') === $database;
    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
        || ($connection['host'] ?? '') !== '127.0.0.1' || ($connection['url'] ?? '') !== '' || (! $ci && ! $local)) {
        throw new RuntimeException('Refusing policy race outside the explicit disposable database.');
    }
    DB::statement("SET lock_timeout = '8s'");
    DB::statement("SET statement_timeout = '10s'");
    echo json_encode(['ready' => true, 'pid' => getmypid(), 'backend' => (int) DB::scalar('SELECT pg_backend_pid()')])."\n";
    fflush(STDOUT);
    stream_set_timeout(STDIN, 8);
    if (trim((string) fgets(STDIN)) !== 'go') {
        throw new RuntimeException('Start not received.');
    }
    $actor = User::query()->findOrFail($payload['actor_id']);
    $service = app(AiAssistPolicies::class);
    $values = ['mode' => 'off', 'summary' => true, 'reply_draft' => true,
        'relevant_articles' => false, 'ticket_details' => true, 'article_context_consent' => false,
        'max_context_characters' => null];
    if ($payload['role'] === 'mutate') {
        DB::transaction(function () use ($payload, $actor, $service, $values): void {
            Account::query()->whereKey($actor->account_id)->lockForUpdate()->firstOrFail();
            $fresh = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($payload['mutation'] === 'revoke') {
                $fresh->forceFill(['deactivated_at' => now()])->save();
            } else {
                $service->saveAccount($fresh, (int) $fresh->account_id, [...$values, 'summary' => false], $payload['token']);
            }
            echo json_encode(['holding' => true])."\n";
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'commit') {
                throw new RuntimeException('Commit not received.');
            }
        });
        $result = ['ok' => true];
    } else {
        try {
            $service->saveAccount($actor, (int) $actor->account_id, [...$values, 'ticket_details' => false], $payload['token']);
            $result = ['ok' => true];
        } catch (ValidationException) {
            $result = ['ok' => false, 'reason' => 'policy_conflict'];
        } catch (AuthorizationException) {
            $result = ['ok' => false, 'reason' => 'access_revoked'];
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $error) {
    echo json_encode(['fixture_error' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
