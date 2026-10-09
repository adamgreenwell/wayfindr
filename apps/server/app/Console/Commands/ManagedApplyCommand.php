<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Backup\BackupService;
use App\Support\Release\ReleaseManifest;
use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeGuard;
use App\Support\Updates\ManagedMigrationContext;
use App\Support\Updates\ManagedUpdateGate;
use App\Support\Visitors\ErasureLedger;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/** The host's fixed target-image migration owner; never releases maintenance. */
class ManagedApplyCommand extends Command
{
    protected $signature = 'wayfindr:managed-apply
        {operation : Update operation UUID}
        {--action=assess : assess, baseline, migrate, verify, or receipt}
        {--plan-id= : Frozen plan SHA256}
        {--target-version= : Exact canonical stable target version}
        {--commit= : Full target source commit}
        {--binding= : Frozen database, storage, and key binding SHA256}
        {--json : Emit the redacted host receipt}';

    protected $description = 'Assess, migrate, or verify one held managed update';

    private const HASH = '/^[a-f0-9]{64}$/D';

    private const RECEIPT_KEYS = [
        'schema', 'operation_id', 'plan_id', 'phase', 'target', 'binding_sha256',
        'manifest_sha256', 'history_sha256', 'migrations_sha256', 'release_state_sha256',
        'pending_migrations', 'guards_clear', 'database_verified', 'redis_verified', 'hold_owned',
    ];

    private const REASONS = [
        'managed_apply_request_invalid', 'managed_apply_busy', 'managed_apply_target_mismatch',
        'managed_apply_binding_mismatch', 'managed_apply_artifact_invalid', 'managed_apply_database_unverified',
        'managed_apply_redis_unverified', 'managed_apply_schema_mismatch', 'managed_apply_guard_blocked',
        'managed_apply_state_mismatch', 'managed_apply_receipt_invalid', 'managed_apply_receipt_unavailable',
        'managed_apply_already_started', 'managed_apply_migration_failed', 'managed_apply_verification_failed',
        'managed_update_busy', 'managed_update_request_invalid', 'managed_update_state_invalid',
        'managed_update_state_unavailable',
    ];

    public function handle(ManagedUpdateGate $gate, UpgradeGuard $guard, ManagedMigrationContext $context): int
    {
        $lease = null;
        $request = null;

        try {
            $request = $this->request();
            $lease = $gate->acquireProtective($request['operation_id']);
            $artifact = $this->artifact($guard, $request);
            $this->assertBinding($request['binding_sha256']);

            if ($request['action'] === 'receipt') {
                $result = $this->readReceipt($request, $artifact);
            } elseif ($request['action'] === 'verify') {
                $stored = $this->readReceipt($request, $artifact);

                if ($stored['phase'] !== 'complete') {
                    throw new RuntimeException('managed_apply_verification_failed');
                }

                $result = $this->facts($guard, $request, $artifact, 'verified', afterMigration: true);

                foreach (['migrations_sha256', 'release_state_sha256'] as $field) {
                    if (! hash_equals($stored[$field], $result[$field])) {
                        throw new RuntimeException('managed_apply_verification_failed');
                    }
                }
            } else {
                if ($request['action'] === 'migrate' && @lstat($this->receiptPath($request)) !== false) {
                    throw new RuntimeException('managed_apply_already_started');
                }

                // The source fallback has no target migration receipt. It must
                // still prove that the unchanged release is fit to serve before
                // the host can release its maintenance hold.
                $result = $this->facts($guard, $request, $artifact, 'assessed', afterMigration: $request['action'] === 'baseline');

                if ($request['action'] === 'migrate') {
                    $lease->assertProtective($request['operation_id']);
                    $result['phase'] = 'intent';
                    $this->writeReceipt($request, $result, initial: true);

                    // Kernel::call runs normal COMMAND/TERMINATE events. A
                    // Command::callSilent shortcut would skip the release and
                    // erasure listeners, and the artifact's own migration gate.
                    try {
                        $exit = $context->during($lease, $request['operation_id'], fn (): int => $this->migrate());
                    } catch (Throwable) {
                        throw new RuntimeException('managed_apply_migration_failed');
                    }

                    if ($exit !== self::SUCCESS) {
                        throw new RuntimeException('managed_apply_migration_failed');
                    }

                    $lease->assertProtective($request['operation_id']);
                    $this->assertBinding($request['binding_sha256']);
                    $currentArtifact = $this->artifact($guard, $request);

                    if ($currentArtifact !== $artifact) {
                        throw new RuntimeException('managed_apply_artifact_invalid');
                    }

                    $complete = $this->facts($guard, $request, $artifact, 'complete', afterMigration: true);
                    $this->syncReleaseState($complete['release_state_sha256']);
                    $this->writeReceipt($request, $complete, initial: false, expected: $result);
                    $result = $complete;
                }
            }

            $lease->assertProtective($request['operation_id']);
        } catch (Throwable $failure) {
            $reason = in_array($failure->getMessage(), self::REASONS, true)
                ? $failure->getMessage() : 'managed_apply_verification_failed';

            if ($this->option('json')) {
                $this->line(json_encode([
                    'schema' => 1, 'operation_id' => $request['operation_id'] ?? null,
                    'plan_id' => $request['plan_id'] ?? null, 'status' => 'failed', 'reason' => $reason,
                ], JSON_THROW_ON_ERROR));
            } else {
                $this->error('The managed update could not proceed ('.$reason.'). Maintenance remains held.');
            }

            return self::FAILURE;
        } finally {
            $lease?->release();
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Managed update phase: '.$result['phase'].'. Maintenance remains held.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        $operation = $this->argument('operation');
        $action = $this->option('action');
        $plan = $this->option('plan-id');
        $version = $this->option('target-version');
        $commit = $this->option('commit');
        $binding = $this->option('binding');

        if (! is_string($operation) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $operation) !== 1
            || ! in_array($action, ['assess', 'baseline', 'migrate', 'verify', 'receipt'], true)
            || ! is_string($plan) || preg_match(self::HASH, $plan) !== 1
            || ! is_string($binding) || preg_match(self::HASH, $binding) !== 1
            || ! is_string($version) || preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $version) !== 1
            || ! is_string($commit) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $commit) !== 1) {
            throw new RuntimeException('managed_apply_request_invalid');
        }

        return [
            'operation_id' => $operation, 'plan_id' => $plan, 'action' => $action,
            'target' => ['version' => $version, 'commit' => $commit, 'profile' => 'image'],
            'binding_sha256' => $binding,
        ];
    }

    /** @return array{manifest: string, history: string} */
    protected function artifactPaths(): array
    {
        return ['manifest' => UpgradeGuard::MANIFEST_FILE, 'history' => UpgradeGuard::HISTORY_FILE];
    }

    /** @return array{manifest_sha256: string, history_sha256: string} */
    private function artifact(UpgradeGuard $guard, array $request): array
    {
        $paths = $this->artifactPaths();

        if (config('wayfindr.release.manifest_path') !== $paths['manifest']
            || config('wayfindr.release.history_path') !== $paths['history']
            || config('wayfindr.release.state_path') !== storage_path('app/release-state.json')) {
            throw new RuntimeException('managed_apply_artifact_invalid');
        }

        $runtime = config('wayfindr.release.version');
        $commit = config('wayfindr.release.commit');

        if (! is_string($runtime) || (str_starts_with($runtime, 'v') ? substr($runtime, 1) : $runtime) !== $request['target']['version']
            || $commit !== $request['target']['commit'] || $guard->installationProfile() !== 'image') {
            throw new RuntimeException('managed_apply_target_mismatch');
        }

        $manifestBytes = $this->regularBytes($paths['manifest'], 1024 * 1024, 'managed_apply_artifact_invalid');
        $historyBytes = $this->regularBytes($paths['history'], 8 * 1024 * 1024, 'managed_apply_artifact_invalid');
        $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
        ReleaseManifest::assertPublished($manifest);

        if ($manifest['version'] !== $request['target']['version'] || $manifest['commit'] !== $request['target']['commit']) {
            throw new RuntimeException('managed_apply_target_mismatch');
        }

        return ['manifest_sha256' => hash('sha256', $manifestBytes), 'history_sha256' => hash('sha256', $historyBytes)];
    }

    protected function bindingSha256(): string
    {
        // Keep this byte-for-byte equivalent to update_protection.py's
        // CAPTURE_BINDING. Only the digest can leave private root custody.
        return hash('sha256', serialize([
            config('database'), config('filesystems'), config('wayfindr.attachments'),
            config('wayfindr.backup'), config('wayfindr.erasure'), BackupService::appKeyFingerprints(),
        ]));
    }

    private function assertBinding(string $expected): void
    {
        if (! hash_equals($expected, $this->bindingSha256())) {
            throw new RuntimeException('managed_apply_binding_mismatch');
        }
    }

    protected function verifyDatabase(): void
    {
        try {
            if (DB::connection()->getDriverName() !== 'pgsql' || (string) (DB::selectOne('SELECT 1 AS verified')->verified ?? '') !== '1') {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException('managed_apply_database_unverified');
        }
    }

    protected function verifyRedis(): void
    {
        try {
            if (! in_array(Redis::connection()->command('ping'), [true, 'PONG', '+PONG'], true)) {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException('managed_apply_redis_unverified');
        }
    }

    /** @return array{migrations_sha256: string, pending_migrations: int} */
    protected function migrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            throw new RuntimeException('managed_apply_schema_mismatch');
        }

        $shipped = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));
        $ran = $migrator->getRepository()->getRan();

        if ($shipped === [] || $ran === [] || count($shipped) > 32768 || count($ran) > 32768
            || count($ran) !== count(array_unique($ran)) || array_diff($ran, $shipped) !== []) {
            throw new RuntimeException('managed_apply_schema_mismatch');
        }

        foreach ([...$shipped, ...$ran] as $name) {
            if (! is_string($name) || strlen($name) > 512 || preg_match('/^[A-Za-z0-9_]+$/D', $name) !== 1) {
                throw new RuntimeException('managed_apply_schema_mismatch');
            }
        }

        sort($shipped, SORT_STRING);
        sort($ran, SORT_STRING);
        $batches = $migrator->getRepository()->getMigrationBatches();
        ksort($batches, SORT_STRING);

        return [
            'migrations_sha256' => hash('sha256', json_encode(['shipped' => $shipped, 'ran' => $ran, 'batches' => $batches], JSON_THROW_ON_ERROR)),
            'pending_migrations' => count(array_diff($shipped, $ran)),
        ];
    }

    /** @return array<string, mixed> */
    private function facts(UpgradeGuard $guard, array $request, array $artifact, string $phase, bool $afterMigration = false): array
    {
        $this->verifyDatabase();
        $this->verifyRedis();
        $migrations = $this->migrations();
        $assessment = $guard->assess();

        if ($assessment['blocked'] || ! $guard->lastAssessable() || $assessment['target'] !== $request['target']['version']
            || $guard->lastCommit() !== $request['target']['commit']) {
            throw new RuntimeException('managed_apply_guard_blocked');
        }

        $statePath = storage_path('app/release-state.json');
        $stateBytes = @lstat($statePath) === false ? '' : $this->regularBytes($statePath, 4096, 'managed_apply_state_mismatch');

        if ($afterMigration) {
            $state = app(ReleaseState::class);

            if ($migrations['pending_migrations'] !== 0 || $guard->assessAll() !== [] || ! $guard->lastAssessable()
                || app(ErasureLedger::class)->reapplyOutstanding()) {
                throw new RuntimeException('managed_apply_verification_failed');
            }

            if ($state->recordedVersion() !== $request['target']['version'] || $state->recordedCommit() !== $request['target']['commit']
                || $state->recordedInstallationProfile() !== 'image' || $state->satisfiedThrough() !== $request['target']['version']) {
                throw new RuntimeException('managed_apply_state_mismatch');
            }
        }

        return [
            'schema' => 1, 'operation_id' => $request['operation_id'], 'plan_id' => $request['plan_id'], 'phase' => $phase,
            'target' => $request['target'], 'binding_sha256' => $request['binding_sha256'],
            ...$artifact, ...$migrations, 'release_state_sha256' => hash('sha256', $stateBytes),
            'guards_clear' => true, 'database_verified' => true, 'redis_verified' => true, 'hold_owned' => true,
        ];
    }

    protected function migrate(): int
    {
        return app(Kernel::class)->call('migrate', ['--force' => true, '--no-interaction' => true], new NullOutput);
    }

    private function receiptPath(array $request): string
    {
        return storage_path('app/managed-updates/'.$request['operation_id'].'/migration.json');
    }

    /** @return array<string, mixed> */
    private function readReceipt(array $request, array $artifact): array
    {
        $this->receiptDirectory($request, create: false);
        $bytes = $this->regularBytes($this->receiptPath($request), 4096, 'managed_apply_receipt_invalid');
        $receipt = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);

        if (! is_array($receipt) || count($receipt) !== count(self::RECEIPT_KEYS) || array_diff(array_keys($receipt), self::RECEIPT_KEYS) !== []
            || ($receipt['schema'] ?? null) !== 1 || ! in_array($receipt['phase'] ?? null, ['intent', 'complete'], true)
            || ($receipt['operation_id'] ?? null) !== $request['operation_id'] || ($receipt['plan_id'] ?? null) !== $request['plan_id']
            || ($receipt['target'] ?? null) !== $request['target'] || ($receipt['binding_sha256'] ?? null) !== $request['binding_sha256']
            || ! is_int($receipt['pending_migrations'] ?? null) || $receipt['pending_migrations'] < 0 || $receipt['pending_migrations'] > 32768) {
            throw new RuntimeException('managed_apply_receipt_invalid');
        }

        foreach (['binding_sha256', 'manifest_sha256', 'history_sha256', 'migrations_sha256', 'release_state_sha256'] as $field) {
            if (! is_string($receipt[$field] ?? null) || preg_match(self::HASH, $receipt[$field]) !== 1
                || (isset($artifact[$field]) && ! hash_equals($artifact[$field], $receipt[$field]))) {
                throw new RuntimeException('managed_apply_receipt_invalid');
            }
        }

        foreach (['guards_clear', 'database_verified', 'redis_verified', 'hold_owned'] as $field) {
            if (($receipt[$field] ?? null) !== true) {
                throw new RuntimeException('managed_apply_receipt_invalid');
            }
        }

        if (($receipt['phase'] === 'complete' && $receipt['pending_migrations'] !== 0)
            || $bytes !== json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n") {
            throw new RuntimeException('managed_apply_receipt_invalid');
        }

        return $receipt;
    }

    private function receiptDirectory(array $request, bool $create): string
    {
        $paths = [storage_path('app'), storage_path('app/managed-updates'), dirname($this->receiptPath($request))];

        foreach ($paths as $path) {
            clearstatcache(true, $path);
            $node = @lstat($path);

            if ($node === false && $create) {
                if (! @mkdir($path, 0700)) {
                    throw new RuntimeException('managed_apply_receipt_unavailable');
                }

                // Persist each new directory entry, not only migration.json in
                // its final directory. Losing an intent's parent after a crash
                // must not make the operation appear safe to run again.
                $this->syncDirectory(dirname($path));
                $node = @lstat($path);
            }

            if (! is_array($node) || ($node['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('managed_apply_receipt_invalid');
            }
        }

        return end($paths);
    }

    private function writeReceipt(array $request, array $receipt, bool $initial, ?array $expected = null): void
    {
        $directory = $this->receiptDirectory($request, create: true);
        $path = $this->receiptPath($request);
        clearstatcache(true, $path);

        if ($initial && @lstat($path) !== false) {
            throw new RuntimeException('managed_apply_already_started');
        }

        if (! $initial && $this->readReceipt($request, ['manifest_sha256' => $receipt['manifest_sha256'], 'history_sha256' => $receipt['history_sha256']]) !== $expected) {
            throw new RuntimeException('managed_apply_receipt_invalid');
        }

        $temporary = $directory.'/.migration-'.bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'xb');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_apply_receipt_unavailable');
        }

        try {
            $bytes = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

            if (! @chmod($temporary, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('managed_apply_receipt_unavailable');
            }

            fclose($handle);
            $handle = null;

            if ($initial) {
                if (! @link($temporary, $path) || ! @unlink($temporary)) {
                    throw new RuntimeException('managed_apply_receipt_unavailable');
                }
            } elseif (! @rename($temporary, $path)) {
                throw new RuntimeException('managed_apply_receipt_unavailable');
            }

            $this->syncDirectory($directory);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (@lstat($temporary) !== false) {
                @unlink($temporary);
            }
        }
    }

    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_apply_receipt_unavailable');
        }

        try {
            if (! fsync($handle)) {
                throw new RuntimeException('managed_apply_receipt_unavailable');
            }
        } finally {
            fclose($handle);
        }
    }

    private function syncReleaseState(string $expectedHash): void
    {
        $path = storage_path('app/release-state.json');
        $handle = @fopen($path, 'r+b');

        if (! is_resource($handle)) {
            throw new RuntimeException('managed_apply_state_mismatch');
        }

        try {
            clearstatcache(true, $path);
            $node = @lstat($path);
            $opened = @fstat($handle);

            if (! is_array($node) || ! is_array($opened) || ($node['mode'] & 0170000) !== 0100000
                || $node['dev'] !== $opened['dev'] || $node['ino'] !== $opened['ino']
                || ! hash_equals($expectedHash, hash('sha256', $this->regularBytes($path, 4096, 'managed_apply_state_mismatch')))
                || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('managed_apply_state_mismatch');
            }
        } finally {
            fclose($handle);
        }

        $this->syncDirectory(storage_path('app'));
    }

    private function regularBytes(string $path, int $limit, string $reason): string
    {
        clearstatcache(true, $path);
        $node = @lstat($path);

        if (! is_array($node) || ($node['mode'] & 0170000) !== 0100000 || $node['size'] <= 0 || $node['size'] > $limit) {
            throw new RuntimeException($reason);
        }

        $bytes = @file_get_contents($path, false, null, 0, $limit + 1);

        if (! is_string($bytes) || strlen($bytes) !== $node['size']) {
            throw new RuntimeException($reason);
        }

        return $bytes;
    }
}
