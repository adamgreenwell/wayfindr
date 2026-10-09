<?php

declare(strict_types=1);

use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;

class HostUpdaterProtocolFixture extends HostUpdaterClient
{
    public array $requests = [];

    public array $credential = ['schema' => 1, 'installation_id' => 'a8d0b678-e191-4d71-8253-d63182786d0b', 'token' => ''];

    public mixed $mutateResponse = null;

    public mixed $mutateWire = null;

    public mixed $transportFailure = null;

    public function __construct()
    {
        $this->credential['token'] = str_repeat('a', 64);
    }

    protected function credentials(): array
    {
        return $this->credential;
    }

    protected function exchange(string $request): string
    {
        if ($this->transportFailure !== null) {
            throw $this->transportFailure;
        }

        $envelope = json_decode($request, true, flags: JSON_THROW_ON_ERROR);
        expect($envelope['mac'])->toBe(hash_hmac('sha256', "wayfindr-updater-v1:request\n".$envelope['payload'], $this->credential['token']));
        $payload = json_decode(base64_decode($envelope['payload'], true), true, flags: JSON_THROW_ON_ERROR);
        $this->requests[] = $payload;
        $result = match ($payload['action']) {
            'capabilities' => hostUpdaterCapabilityFixture($this->credential['installation_id']),
            'logs' => [
                'operation_id' => $payload['operation_id'],
                'events' => [['revision' => 1, 'at' => 100, 'code' => 'operation_accepted', 'phase' => 'accepted']],
                'next_cursor' => 1,
                'has_more' => false,
            ],
            default => hostUpdaterStatusFixture($this->credential['installation_id'], $payload['operation_id'] ?? ($payload['action'] === 'prepare' ? '1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46' : null)),
        };
        $response = ['protocol' => 1, 'installation_id' => $payload['installation_id'], 'nonce' => $payload['nonce'], 'ok' => true, 'result' => $result];

        if ($this->mutateResponse !== null) {
            $response = ($this->mutateResponse)($response, $payload);
        }

        $encoded = base64_encode(json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $wire = json_encode([
            'payload' => $encoded,
            'mac' => hash_hmac('sha256', "wayfindr-updater-v1:response\n".$encoded, $this->credential['token']),
        ], JSON_THROW_ON_ERROR)."\n";

        return $this->mutateWire === null ? $wire : ($this->mutateWire)($wire, $request);
    }

    public function inspectTrustedPath(string $path, string $kind): array
    {
        return $this->trustedPath($path, $kind);
    }
}

function hostUpdaterCapabilityFixture(string $installationId): array
{
    return [
        'ownership' => 'installer-managed',
        'installation_id' => $installationId,
        'enrolled' => true,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'image_reference' => 'ghcr.io/adamgreenwell/wayfindr:0.9.0',
        'helper' => ['protocol' => 1, 'version' => '0.1.0', 'capabilities' => ['plan', 'status']],
        'managed_policy' => ['require_remote_backup' => true],
    ];
}

function hostUpdaterStatusFixture(string $installationId, ?string $operationId = null): array
{
    $generation = '58df0152-ec7a-4b32-b0d0-fc93f4558bfc';

    return [
        'schema' => 1,
        'installation_id' => $installationId,
        'revision' => 1,
        'helper_version' => '0.1.0',
        'generation' => $generation,
        'heartbeat_at' => 100,
        'active_operation' => $operationId,
        'operation' => $operationId === null ? null : [
            'operation_id' => $operationId,
            'request_id' => 'b2b1e130-2786-49b8-92dd-6bc779bc459a',
            'release_tag' => 'v0.10.0',
            'phase' => 'accepted',
            'checkpoint' => 'accepted',
            'executor_generation' => $generation,
            'executor_version' => '0.1.0',
            'mutation_started' => false,
            'created_at' => 100,
            'updated_at' => 100,
            'revision' => 1,
            'error' => null,
            'source' => ['version' => '0.9.0', 'commit' => str_repeat('a', 40)],
            'target' => ['tag' => 'v0.10.0', 'version' => '0.10.0', 'commit' => str_repeat('b', 40), 'image_digest' => 'sha256:'.str_repeat('c', 64)],
            'plan_id' => str_repeat('d', 64),
            'events' => [['revision' => 1, 'at' => 100, 'code' => 'operation_accepted', 'phase' => 'accepted']],
        ],
    ];
}

beforeEach(function (): void {
    config()->set('wayfindr.updates.helper_enabled', true);
});

test('authenticated host capabilities bind the installation without advertising execution', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $capabilities = $client->capabilities('image');

    expect($capabilities->helperAuthenticated)->toBeTrue()
        ->and($capabilities->installationId)->toBe($client->credential['installation_id'])
        ->and($capabilities->platform)->toBe('linux')
        ->and($capabilities->architecture)->toBe('amd64')
        ->and($capabilities->helperCapabilities)->toBe(['plan', 'status'])
        ->and($capabilities->managedBlockers())->toContain('helper_capability_missing:apply', 'helper_capability_missing:recover')
        ->and($capabilities->toArray()['managed_update_eligible'])->toBeFalse();
});

test('authenticated ownership does not change the application runtime profile', function (): void {
    $capabilities = (new HostUpdaterProtocolFixture)->capabilities('host');

    expect($capabilities->runtimeProfile)->toBe('host')
        ->and($capabilities->managedBlockers())->toContain('runtime_profile_not_image');
});

test('prepare status and bounded log requests use fresh authenticated frames without retrying', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $requestId = 'b2b1e130-2786-49b8-92dd-6bc779bc459a';
    $prepared = $client->prepare('v0.10.0', $requestId);
    $operationId = $prepared['operation']['operation_id'];
    $client->status($operationId);
    $client->logs($operationId, 0, 100);

    expect(array_column($client->requests, 'action'))->toBe(['prepare', 'status', 'logs'])
        ->and(count(array_unique(array_column($client->requests, 'nonce'))))->toBe(3)
        ->and($client->requests[0]['request_id'])->toBe($requestId)
        ->and($client->requests[0]['release_tag'])->toBe('v0.10.0')
        ->and($client->requests[0]['issued_at'])->toBeInt()
        ->and($client->requests[2]['cursor'])->toBe(0)
        ->and($client->requests[2]['limit'])->toBe(100);
});

test('the helper is opt in and disabled configuration performs no transport request', function (mixed $enabled): void {
    config()->set('wayfindr.updates.helper_enabled', $enabled);
    $client = new HostUpdaterProtocolFixture;

    expect(fn () => $client->status())->toThrow(HostUpdaterException::class, 'helper_disabled')
        ->and($client->requests)->toBe([]);
})->with([false, null, 'true', 1]);

test('credentials reject malformed and unexpected enrollment claims before connecting', function (array $changes): void {
    $client = new HostUpdaterProtocolFixture;
    $client->credential = array_replace($client->credential, $changes);

    expect(fn () => $client->status())->toThrow(HostUpdaterException::class, 'helper_credentials_invalid')
        ->and($client->requests)->toBe([]);
})->with([
    [['schema' => '1']],
    [['installation_id' => 'not-an-installation']],
    [['token' => str_repeat('A', 64)]],
    [['token' => 'credential-secret']],
    [['extra' => 'credential-secret']],
]);

test('unsigned forged replayed reflected and oversized response frames cannot authenticate the helper', function (Closure $mutate, string $reason): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateWire = $mutate;

    expect(fn () => $client->capabilities('image'))->toThrow(HostUpdaterException::class, $reason);
})->with([
    'wrong MAC' => [static function (string $wire): string {
        $envelope = json_decode($wire, true);
        $envelope['mac'] = str_repeat('0', 64);

        return json_encode($envelope)."\n";
    }, 'helper_authentication_failed'],
    'request reflection' => [static fn (string $wire, string $request): string => $request, 'helper_authentication_failed'],
    'extra envelope field' => [static function (string $wire): string {
        $envelope = json_decode($wire, true);
        $envelope['token'] = 'private-token';

        return json_encode($envelope)."\n";
    }, 'helper_authentication_failed'],
    'incomplete line' => [static fn (string $wire): string => rtrim($wire, "\n"), 'helper_response_invalid'],
    'two frames' => [static fn (string $wire): string => $wire.$wire, 'helper_response_invalid'],
    'oversized frame' => [static fn (): string => str_repeat('x', 1024 * 1024)."\n", 'helper_response_too_large'],
]);

test('a previous valid response cannot satisfy a new request nonce', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $captured = null;
    $client->mutateWire = static function (string $wire) use (&$captured): string {
        return $captured ??= $wire;
    };
    $client->status();

    expect(fn () => $client->status())->toThrow(HostUpdaterException::class, 'helper_response_invalid');
});

test('authenticated responses require exact protocol identity nonce boolean and payload fields', function (Closure $mutate): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = $mutate;

    expect(fn () => $client->status())->toThrow(HostUpdaterException::class, 'helper_response_invalid');
})->with([
    'wrong installation' => [static fn (array $response): array => array_replace($response, ['installation_id' => '78d4f68c-1276-4e32-9278-a4cb75137954'])],
    'wrong nonce' => [static fn (array $response): array => array_replace($response, ['nonce' => str_repeat('0', 32)])],
    'protocol string' => [static fn (array $response): array => array_replace($response, ['protocol' => '1'])],
    'protocol unknown' => [static fn (array $response): array => array_replace($response, ['protocol' => 2])],
    'truthy ok' => [static fn (array $response): array => array_replace($response, ['ok' => 1])],
    'surprise response field' => [static fn (array $response): array => $response + ['credential' => 'private-token']],
    'array result' => [static fn (array $response): array => array_replace($response, ['result' => ['unexpected']])],
    'null result' => [static fn (array $response): array => array_replace($response, ['result' => null])],
    'unknown error code' => [static fn (array $response): array => array_replace(array_diff_key($response, ['result' => true]), ['error' => 'private_token', 'ok' => false])],
]);

test('known refusal codes stay sanitized and are never retried', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = static function (array $response): array {
        unset($response['result']);
        $response['ok'] = false;
        $response['error'] = 'journal_corrupt';

        return $response;
    };

    expect(fn () => $client->status())->toThrow(HostUpdaterException::class, 'helper_refused:journal_corrupt')
        ->and($client->requests)->toHaveCount(1);
});

test('transport details and secrets never become an exception message', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $client->transportFailure = new RuntimeException('/private/path?token=private-transport-token');

    try {
        $client->status();
        test()->fail('The transport failure must refuse the request.');
    } catch (HostUpdaterException $exception) {
        expect($exception->reason)->toBe('helper_unavailable')
            ->and($exception->getMessage())->not->toContain('private-transport-token', '/private/path')
            ->and($exception->getPrevious())->toBeNull();
    }
});

test('capabilities reject unexpected secret fields and malformed host identity facts', function (Closure $mutate, string $reason): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = static function (array $response) use ($mutate): array {
        $response['result'] = $mutate($response['result']);

        return $response;
    };

    expect(fn () => $client->capabilities('image'))->toThrow(HostUpdaterException::class, $reason);
})->with([
    'extra report field' => [static fn (array $report): array => $report + ['credential_token' => 'private-token'], 'helper_response_invalid'],
    'extra helper field' => [static function (array $report): array {
        $report['helper']['token'] = 'private-token';

        return $report;
    }, 'helper_response_invalid'],
    'wrong report installation' => [static fn (array $report): array => array_replace($report, ['installation_id' => '78d4f68c-1276-4e32-9278-a4cb75137954']), 'helper_response_invalid'],
    'unsupported host platform' => [static fn (array $report): array => array_replace($report, ['platform' => 'darwin']), 'helper_capabilities_invalid'],
    'unsupported architecture' => [static fn (array $report): array => array_replace($report, ['architecture' => '386']), 'helper_capabilities_invalid'],
    'unknown capability' => [static function (array $report): array {
        $report['helper']['capabilities'][] = 'shell';

        return $report;
    }, 'helper_capabilities_invalid'],
    'nonstring capability' => [static function (array $report): array {
        $report['helper']['capabilities'][] = ['private-token'];

        return $report;
    }, 'helper_capabilities_invalid'],
    'secret managed policy' => [static fn (array $report): array => array_replace($report, ['managed_policy' => ['token' => 'private-token']]), 'helper_response_invalid'],
    'image transport secret' => [static fn (array $report): array => array_replace($report, ['image_reference' => 'https://user:private-token@example.test/image']), 'helper_capabilities_invalid'],
]);

test('status snapshots reject unexpected fields malformed records and future mutation phases', function (Closure $mutate): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = static function (array $response) use ($mutate): array {
        $response['result'] = $mutate($response['result']);

        return $response;
    };

    expect(fn () => $client->status('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46'))->toThrow(HostUpdaterException::class, 'helper_response_invalid');
})->with([
    'secret snapshot field' => [static fn (array $status): array => $status + ['token' => 'private-token']],
    'negative revision' => [static fn (array $status): array => array_replace($status, ['revision' => -1])],
    'untyped heartbeat' => [static fn (array $status): array => array_replace($status, ['heartbeat_at' => '100'])],
    'unknown generation' => [static fn (array $status): array => array_replace($status, ['generation' => 'unknown'])],
    'absent requested operation' => [static fn (array $status): array => array_replace($status, ['operation' => null])],
    'operation secret' => [static function (array $status): array {
        $status['operation']['token'] = 'private-token';

        return $status;
    }],
    'future apply phase' => [static function (array $status): array {
        $status['operation']['phase'] = 'applying';

        return $status;
    }],
    'schema mutation' => [static function (array $status): array {
        $status['operation']['mutation_started'] = true;

        return $status;
    }],
    'target secret' => [static function (array $status): array {
        $status['operation']['target']['token'] = 'private-token';

        return $status;
    }],
    'invalid plan fingerprint' => [static function (array $status): array {
        $status['operation']['plan_id'] = 'unverified';

        return $status;
    }],
    'event detail' => [static function (array $status): array {
        $status['operation']['events'][0]['detail'] = 'private-token';

        return $status;
    }],
    'unknown event' => [static function (array $status): array {
        $status['operation']['events'][0]['code'] = 'private_token';

        return $status;
    }],
]);

test('invalid target and operation inputs cannot become host requests', function (Closure $call): void {
    $client = new HostUpdaterProtocolFixture;

    expect(fn () => $call($client))->toThrow(HostUpdaterException::class, 'helper_request_invalid')
        ->and($client->requests)->toBe([]);
})->with([
    'floating target' => [static fn (HostUpdaterClient $client) => $client->prepare('latest', 'b2b1e130-2786-49b8-92dd-6bc779bc459a')],
    'unprefixed target' => [static fn (HostUpdaterClient $client) => $client->prepare('0.10.0', 'b2b1e130-2786-49b8-92dd-6bc779bc459a')],
    'prerelease target' => [static fn (HostUpdaterClient $client) => $client->prepare('v0.10.0-rc.1', 'b2b1e130-2786-49b8-92dd-6bc779bc459a')],
    'build metadata target' => [static fn (HostUpdaterClient $client) => $client->prepare('v0.10.0+abc', 'b2b1e130-2786-49b8-92dd-6bc779bc459a')],
    'invalid idempotency ID' => [static fn (HostUpdaterClient $client) => $client->prepare('v0.10.0', 'request-1')],
    'invalid operation ID' => [static fn (HostUpdaterClient $client) => $client->status('../../private-token')],
    'negative cursor' => [static fn (HostUpdaterClient $client) => $client->logs('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46', -1)],
    'zero limit' => [static fn (HostUpdaterClient $client) => $client->logs('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46', 0, 0)],
    'unbounded limit' => [static fn (HostUpdaterClient $client) => $client->logs('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46', 0, 101)],
]);

test('an app reported plan remains a terminal blocked observation without apply authority', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = static function (array $response): array {
        $response['result']['active_operation'] = null;
        $response['result']['revision'] = 4;
        $operation = &$response['result']['operation'];
        $operation['revision'] = 4;
        $operation['phase'] = 'blocked';
        $operation['checkpoint'] = 'plan_reported';
        $operation['error'] = 'execution_not_available';
        $operation['events'] = [
            ['revision' => 1, 'at' => 100, 'code' => 'operation_accepted', 'phase' => 'accepted'],
            ['revision' => 2, 'at' => 101, 'code' => 'prepare_started', 'phase' => 'preparing'],
            ['revision' => 3, 'at' => 102, 'code' => 'plan_reported', 'phase' => 'preparing'],
            ['revision' => 4, 'at' => 102, 'code' => 'operation_blocked', 'phase' => 'blocked'],
        ];

        return $response;
    };
    $result = $client->status('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46');

    expect($result['operation']['checkpoint'])->toBe('plan_reported')
        ->and($result['operation']['mutation_started'])->toBeFalse()
        ->and($result['operation']['phase'])->toBe('blocked')
        ->and($result['operation']['error'])->toBe('execution_not_available');
});

test('log pagination uses journal revisions rather than event list offsets', function (): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = static function (array $response): array {
        $response['result']['events'] = [
            ['revision' => 7, 'at' => 100, 'code' => 'prepare_started', 'phase' => 'preparing'],
            ['revision' => 11, 'at' => 101, 'code' => 'plan_reported', 'phase' => 'preparing'],
        ];
        $response['result']['next_cursor'] = 11;

        return $response;
    };

    expect($client->logs('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46', 5, 2)['next_cursor'])->toBe(11);
});

test('log pages reject stale events reversed revisions excess events and wrong cursors', function (Closure $mutate): void {
    $client = new HostUpdaterProtocolFixture;
    $client->mutateResponse = static function (array $response) use ($mutate): array {
        $response['result'] = $mutate($response['result']);

        return $response;
    };

    expect(fn () => $client->logs('1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46', 0, 2))->toThrow(HostUpdaterException::class, 'helper_response_invalid');
})->with([
    'wrong cursor' => [static fn (array $logs): array => array_replace($logs, ['next_cursor' => 2])],
    'stale revision' => [static function (array $logs): array {
        $logs['events'][0]['revision'] = 0;

        return $logs;
    }],
    'excess events' => [static function (array $logs): array {
        $logs['events'][] = $logs['events'][0];
        $logs['events'][] = $logs['events'][0];

        return $logs;
    }],
    'reversed revisions' => [static function (array $logs): array {
        $logs['events'][] = $logs['events'][0];
        $logs['events'][0]['revision'] = 2;

        return $logs;
    }],
    'secret log field' => [static fn (array $logs): array => $logs + ['token' => 'private-token']],
]);

test('production path validation refuses symlinks writable ancestry and non socket nodes', function (string $path, string $kind): void {
    $client = new HostUpdaterProtocolFixture;

    expect(fn () => $client->inspectTrustedPath($path, $kind))->toThrow(HostUpdaterException::class);
})->with([
    ['relative/credential.json', 'credential'],
    ['/run/../tmp/credential.json', 'credential'],
    ['/run//credential.json', 'credential'],
    ["/run/credential\0.json", 'credential'],
    ['/tmp/credential.json', 'credential'],
    ['/dev/null', 'socket'],
    ['/run/wayfindr-updater/missing.sock', 'socket'],
]);

class HostUpdaterStreamFixture extends HostUpdaterClient
{
    protected function credentials(): array
    {
        return ['schema' => 1, 'installation_id' => 'a8d0b678-e191-4d71-8253-d63182786d0b', 'token' => str_repeat('a', 64)];
    }

    protected function trustedPath(mixed $path, string $kind): array
    {
        // A fixture socket is owned by the test user. Only this in-process
        // subclass bypasses ownership; deployment configuration cannot do so.
        return [];
    }
}

test('a listening but silent host helper reaches the fixed deadline without hanging', function (): void {
    $socket = sys_get_temp_dir().'/wf-updater-silent-'.bin2hex(random_bytes(8)).'.sock';
    $server = stream_socket_server('unix://'.$socket, $errorNumber, $error);
    expect($server)->not->toBeFalse();
    config()->set('wayfindr.updates.helper_socket', $socket);
    $started = microtime(true);

    try {
        expect(fn () => (new HostUpdaterStreamFixture)->status())->toThrow(HostUpdaterException::class, 'helper_timeout')
            ->and(microtime(true) - $started)->toBeLessThan(7.0);
    } finally {
        fclose($server);
        @unlink($socket);
    }
});

test('the real Unix stream transport reads split frames and rejects an early disconnect', function (bool $complete): void {
    $socket = sys_get_temp_dir().'/wf-updater-frame-'.bin2hex(random_bytes(8)).'.sock';
    $script = tempnam(sys_get_temp_dir(), 'wf-updater-server-');
    file_put_contents($script, <<<'PHP'
    <?php
    $server = stream_socket_server('unix://'.$argv[1]);
    $client = stream_socket_accept($server, 10);
    $envelope = json_decode(fgets($client), true);
    $request = json_decode(base64_decode($envelope['payload']), true);
    $result = json_decode(base64_decode($argv[2]), true);
    $payload = base64_encode(json_encode(['protocol' => 1, 'installation_id' => $request['installation_id'], 'nonce' => $request['nonce'], 'ok' => true, 'result' => $result]));
    $wire = json_encode(['payload' => $payload, 'mac' => hash_hmac('sha256', "wayfindr-updater-v1:response\n".$payload, str_repeat('a', 64))])."\n";
    $middle = (int) (strlen($wire) / 2);
    fwrite($client, substr($wire, 0, $middle));
    usleep(50000);
    if ($argv[3] === 'complete') {
        fwrite($client, substr($wire, $middle));
    }
    fclose($client);
    fclose($server);
    PHP);
    $result = hostUpdaterStatusFixture('a8d0b678-e191-4d71-8253-d63182786d0b');
    $process = proc_open([PHP_BINARY, $script, $socket, base64_encode(json_encode($result)), $complete ? 'complete' : 'incomplete'], [], $pipes);
    expect($process)->not->toBeFalse();
    $deadline = microtime(true) + 3;

    while (! file_exists($socket) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect(file_exists($socket))->toBeTrue();
    config()->set('wayfindr.updates.helper_socket', $socket);

    try {
        if ($complete) {
            expect((new HostUpdaterStreamFixture)->status())->toBe($result);
        } else {
            expect(fn () => (new HostUpdaterStreamFixture)->status())->toThrow(HostUpdaterException::class, 'helper_response_invalid');
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
        @unlink($socket);
        @unlink($script);
    }
})->with([true, false]);
