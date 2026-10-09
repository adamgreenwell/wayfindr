<?php

declare(strict_types=1);

use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;

/** Use the real PHP framing and Python server with isolated, unprivileged files. */
final class HostUpdaterIpcClient extends HostUpdaterClient
{
    public bool $disconnectAfterWrite = false;

    protected function credentials(): array
    {
        return [
            'schema' => 1,
            'installation_id' => 'a8d0b678-e191-4d71-8253-d63182786d0b',
            'token' => str_repeat('a', 64),
        ];
    }

    protected function trustedPath(mixed $path, string $kind): array
    {
        // The fixture is owned by the test runner. Production has no setting
        // that bypasses root-owned path validation.
        return [];
    }

    protected function exchange(string $request): string
    {
        if (! $this->disconnectAfterWrite) {
            return parent::exchange($request);
        }

        $stream = stream_socket_client('unix://'.config('wayfindr.updates.helper_socket'), timeout: 2);
        expect($stream)->not->toBeFalse();

        try {
            $offset = 0;

            while ($offset < strlen($request)) {
                $written = fwrite($stream, substr($request, $offset));
                expect($written)->toBeGreaterThan(0);
                $offset += $written;
            }
        } finally {
            fclose($stream);
        }

        throw new HostUpdaterException('helper_unavailable');
    }
}

final class HostUpdaterIpcHarness
{
    public readonly string $directory;

    public readonly HostUpdaterIpcClient $client;

    /** @var resource|null */
    private mixed $process = null;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().'/wf-updater-ipc-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->client = new HostUpdaterIpcClient;
        config()->set('wayfindr.updates.helper_enabled', true);
        config()->set('wayfindr.updates.helper_socket', $this->directory.'/helper.sock');
        file_put_contents($this->directory.'/harness.py', <<<'PYTHON'
        import importlib.util
        import os
        import sys
        import time
        from pathlib import Path

        source, directory, mode = sys.argv[1:]
        directory = Path(directory)
        spec = importlib.util.spec_from_file_location("wayfindr_updater_ipc_fixture", source)
        updater = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(updater)
        installation_id = "a8d0b678-e191-4d71-8253-d63182786d0b"

        class FixtureConfiguration:
            token = "a" * 64
            installation_id = installation_id
            value = {"client_uid": os.getuid(), "client_gid": os.getgid()}

            def verify_files(self):
                pass

            def capabilities(self):
                return {"ownership": "installer-managed", "installation_id": self.installation_id,
                        "enrolled": True, "platform": "linux", "architecture": "amd64",
                        "image_reference": "ghcr.io/adamgreenwell/wayfindr:0.9.0",
                        "helper": {"protocol": updater.PROTOCOL, "version": updater.VERSION,
                                   "capabilities": ["plan", "status"]}, "managed_policy": {}}

        def prepare(tag):
            (directory / "preparing").write_text(tag)
            if mode == "slow":
                time.sleep(20)
            return "execution_not_available", {
                "source": {"version": "0.9.0", "commit": "b" * 40},
                "target": {"tag": tag, "version": tag[1:], "commit": "c" * 40,
                           "image_digest": "sha256:" + "d" * 64}, "plan_id": "e" * 64}

        # Only this test process substitutes trusted fixture paths. Actual
        # transport authentication, framing, journal, lock and restart logic run.
        updater.trusted = lambda *args, **kwargs: None
        journal_path = directory / "journal.json"
        if not journal_path.exists():
            updater.atomic_write(journal_path, updater.initial_journal(installation_id))
        with updater.HostLock(directory / "helper.lock", secure=False):
            journal = updater.Journal(journal_path, installation_id, secure=False)
            controller = updater.Controller(FixtureConfiguration(), journal, preparer=prepare)
            updater.Server(controller, directory / "helper.sock").serve()
        PYTHON);
    }

    public function start(string $mode = 'fast'): array
    {
        $this->process = proc_open([
            'python3', '-B', $this->directory.'/harness.py',
            dirname(base_path(), 2).'/scripts/self-host/updater.py', $this->directory, $mode,
        ], [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory.'/stdout.log', 'a'],
            2 => ['file', $this->directory.'/stderr.log', 'a'],
        ], $pipes);
        expect($this->process)->not->toBeFalse();

        return $this->awaitStatus(fn (array $status): bool => $status['generation'] !== null);
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function awaitStatus(Closure $ready, ?string $operationId = null): array
    {
        $deadline = microtime(true) + 5;

        do {
            try {
                $status = $this->client->status($operationId);

                if ($ready($status)) {
                    return $status;
                }
            } catch (HostUpdaterException) {
                // Startup may briefly expose the old socket inode. The real
                // client refuses it; wait for the new authenticated endpoint.
            }

            if (! proc_get_status($this->process)['running']) {
                throw new RuntimeException('The isolated Python helper stopped: '.file_get_contents($this->directory.'/stderr.log'));
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The isolated Python helper did not reach the expected durable state.');
    }

    public function journal(): array
    {
        return json_decode(file_get_contents($this->directory.'/journal.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function close(): void
    {
        $this->stop();

        foreach (scandir($this->directory) as $file) {
            if (! in_array($file, ['.', '..'], true)) {
                unlink($this->directory.'/'.$file);
            }
        }

        rmdir($this->directory);
    }
}

beforeEach(function (): void {
    if (PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('The host protocol requires Linux SO_PEERCRED.');
    }

    $process = proc_open(['python3', '-c', 'import socket, sys; raise SystemExit(0 if sys.version_info >= (3, 11) and hasattr(socket, "SO_PEERCRED") else 1)'], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ], $pipes);

    if (! is_resource($process) || proc_close($process) !== 0) {
        $this->markTestSkipped('Python 3.11+ and Linux SO_PEERCRED are required.');
    }
});

test('PHP and Python agree on authenticated preparation status and revision log cursors across operations', function (): void {
    $harness = new HostUpdaterIpcHarness;

    try {
        $harness->start();
        $capabilities = $harness->client->capabilities('image');
        expect($capabilities->helperAuthenticated)->toBeTrue()
            ->and($capabilities->helperCapabilities)->toBe(['plan', 'status'])
            ->and($capabilities->managedBlockers())->toContain('helper_capability_missing:apply', 'helper_capability_missing:recover');

        $firstRequest = '94b4b6fb-3835-43eb-8fb5-32c91e2c38c6';
        $first = $harness->client->prepare('v0.10.0', $firstRequest);
        $firstId = $first['operation']['operation_id'];
        $harness->awaitStatus(fn (array $status): bool => $status['operation']['phase'] === 'blocked', $firstId);
        expect($harness->client->prepare('v0.10.0', $firstRequest)['operation']['operation_id'])->toBe($firstId);

        $second = $harness->client->prepare('v0.11.0', '58c9a0d4-c9e5-43e9-8c5c-54f2293b9f90');
        $secondId = $second['operation']['operation_id'];
        $completed = $harness->awaitStatus(fn (array $status): bool => $status['operation']['phase'] === 'blocked', $secondId);
        expect($completed['operation']['error'])->toBe('execution_not_available')
            ->and($completed['operation']['mutation_started'])->toBeFalse();

        $page = $harness->client->logs($secondId, 0, 1);
        expect($page['events'])->toHaveCount(1)
            ->and($page['events'][0]['revision'])->toBeGreaterThan(1)
            ->and($page['next_cursor'])->toBe($page['events'][0]['revision'])
            ->and($page['has_more'])->toBeTrue();
        $remaining = $harness->client->logs($secondId, $page['next_cursor'], 100);
        expect(array_column($remaining['events'], 'revision'))->toBe(array_slice(array_column($completed['operation']['events'], 'revision'), 1))
            ->and($remaining['has_more'])->toBeFalse();
        $empty = $harness->client->logs($secondId, $remaining['next_cursor'], 100);
        expect($empty['events'])->toBe([])
            ->and($empty['next_cursor'])->toBe($remaining['next_cursor']);
    } finally {
        $harness->close();
    }
});

test('a disconnected PHP request survives in the host journal and a killed helper retains exclusive reconciliation ownership', function (): void {
    $harness = new HostUpdaterIpcHarness;

    try {
        $initial = $harness->start('slow');
        $harness->client->disconnectAfterWrite = true;
        expect(fn () => $harness->client->prepare('v0.10.0', '94b4b6fb-3835-43eb-8fb5-32c91e2c38c6'))
            ->toThrow(HostUpdaterException::class, 'helper_unavailable');
        $harness->client->disconnectAfterWrite = false;
        $running = $harness->awaitStatus(fn (array $status): bool => ($status['operation']['phase'] ?? null) === 'preparing');
        $operationId = $running['operation']['operation_id'];
        $harness->stop();
        expect($harness->journal()['active_operation'])->toBe($operationId)
            ->and(json_encode($harness->journal()))->not->toContain(str_repeat('a', 64));

        $restarted = $harness->start();
        expect($restarted['generation'])->not->toBe($initial['generation'])
            ->and($restarted['active_operation'])->toBe($operationId)
            ->and($restarted['operation']['phase'])->toBe('reconciliation_required')
            ->and($restarted['operation']['mutation_started'])->toBeFalse();
        expect(fn () => $harness->client->prepare('v0.11.0', '58c9a0d4-c9e5-43e9-8c5c-54f2293b9f90'))
            ->toThrow(HostUpdaterException::class, 'helper_refused:operation_busy');
        expect($harness->client->prepare('v0.10.0', '94b4b6fb-3835-43eb-8fb5-32c91e2c38c6')['operation']['operation_id'])->toBe($operationId);
    } finally {
        $harness->close();
    }
});
