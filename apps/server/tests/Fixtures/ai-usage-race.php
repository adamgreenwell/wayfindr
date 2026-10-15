<?php

declare(strict_types=1);

use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\ResolvedAgentCopilotConnection;
use App\Support\Ai\Usage\AiUsageClock;
use App\Support\Ai\Usage\AiUsageLedger;
use App\Support\Ai\Usage\AiUsagePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Synthetic inputs only: this fixture never resolves or calls a provider. */
function aiUsageRaceLedger(array $limits, string $now): AiUsageLedger
{
    config()->set('wayfindr.ai.usage', $limits);
    $clock = new class($now) implements AiUsageClock
    {
        public function __construct(private string $now) {}

        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse($this->now, 'UTC');
        }
    };

    return new AiUsageLedger(app(AiUsagePolicy::class), $clock);
}

function aiUsageRaceOperation(AiUsageLedger $ledger, array $operation): array
{
    $connection = new ResolvedAgentCopilotConnection(
        $operation['account_id'], $operation['source'] ?? 'account',
        $operation['revision'] ?? str_repeat('a', 64), 'openai', 'synthetic-model',
        '', '', 'synthetic-unused-key',
    );
    $attempt = $operation['attempt_id'];
    $purpose = 'configuration_probe';

    try {
        $result = match ($operation['action']) {
            'reserve' => (function () use ($ledger, $connection, $purpose, $attempt, $operation): array {
                $receipt = $ledger->reserve($connection, $purpose, $attempt, 10, 10, $operation['fingerprint']);

                return ['attempt_id' => $receipt->attemptId, 'reused' => $receipt->reused];
            })(),
            'claim' => ['claim_nonce' => $ledger->claim($connection, $purpose, $attempt)],
            'dispatch' => ['dispatch_nonce' => $ledger->beginDispatch($connection, $purpose, $attempt, $operation['claim_nonce'])->dispatchNonce],
            'reconcile' => (function () use ($ledger, $attempt, $operation): array {
                $ledger->reconcile($attempt, $operation['dispatch_nonce'], 3, 4);

                return [];
            })(),
            'unknown' => (function () use ($ledger, $attempt, $operation): array {
                $ledger->markUnknown($attempt, $operation['dispatch_nonce'], 'timeout');

                return [];
            })(),
            'expire' => ['expired' => $ledger->expire(100)],
            default => throw new RuntimeException('Unsupported synthetic race operation.'),
        };

        return ['ok' => true] + $result;
    } catch (AiNetworkException $failure) {
        return ['ok' => false, 'reason' => $failure->reason];
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $payload = json_decode((string) getenv('WAYFINDR_AI_USAGE_RACE_PAYLOAD'), true, 32, JSON_THROW_ON_ERROR);
        if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Race fixture requires its guarded PostgreSQL test database.');
        }
        DB::statement("SET lock_timeout = '8s'");
        DB::statement("SET statement_timeout = '10s'");
        $backend = (int) DB::scalar('SELECT pg_backend_pid()');
        echo json_encode(['ready' => true, 'pid' => getmypid(), 'backend' => $backend])."\n";
        fflush(STDOUT);
        stream_set_timeout(STDIN, 8);
        if (trim((string) fgets(STDIN)) !== 'go') {
            throw new RuntimeException('Race start was not received.');
        }
        $hold = $payload['hold_before_commit'] ?? false;
        if ($hold) {
            DB::beginTransaction();
        }
        try {
            $result = aiUsageRaceOperation(aiUsageRaceLedger($payload['limits'], $payload['now']), $payload['operation']);
            if ($hold) {
                echo json_encode(['holding' => true])."\n";
                fflush(STDOUT);
                if (trim((string) fgets(STDIN)) !== 'commit') {
                    throw new RuntimeException('Race commit was not received.');
                }
                DB::commit();
            }
        } finally {
            if ($hold && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $failure) {
        // Never emit raw exceptions, SQL, credentials or connection topology.
        echo json_encode(['ok' => false, 'fixture_error' => $failure::class])."\n";
        exit(1);
    }
}
