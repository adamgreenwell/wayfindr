<?php

declare(strict_types=1);

use App\Support\Updates\HostUpdaterClient;
use App\Support\Updates\HostUpdaterException;
use Illuminate\Support\Facades\Artisan;

class HostUpdaterStatusCommandFixture extends HostUpdaterClient
{
    public array $calls = [];

    public mixed $failure = null;

    public function status(?string $operationId = null): array
    {
        $this->calls[] = ['status', $operationId];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return ['revision' => 3, 'active_operation' => $operationId, 'operation' => null];
    }

    public function logs(string $operationId, int $cursor = 0, int $limit = 50): array
    {
        $this->calls[] = ['logs', $operationId, $cursor, $limit];

        return ['operation_id' => $operationId, 'events' => [], 'next_cursor' => 3, 'has_more' => false];
    }
}

test('the application protocol contract is static and works before helper enrollment', function (): void {
    config()->set('wayfindr.updates.helper_enabled', false);
    config()->set('wayfindr.updates.helper_credentials', '/does-not-exist/credential.json');
    config()->set('wayfindr.updates.helper_socket', '/does-not-exist/updater.sock');
    $client = new HostUpdaterStatusCommandFixture;
    $client->failure = new RuntimeException('No helper connection is permitted.');
    app()->instance(HostUpdaterClient::class, $client);

    $exit = Artisan::call('wayfindr:updater-status', ['--protocol-contract' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result)->toBe([
            'schema' => 1, 'protocol' => 1, 'minimum_helper_version' => '0.4.0',
            'capabilities' => ['plan', 'status', 'start', 'history', 'cancel'],
        ])
        ->and($client->calls)->toBe([]);
});

test('the static contract cannot be combined with operation or log inspection', function (array $parameters): void {
    $client = new HostUpdaterStatusCommandFixture;
    app()->instance(HostUpdaterClient::class, $client);
    $exit = Artisan::call('wayfindr:updater-status', $parameters + ['--protocol-contract' => true, '--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['reason'])->toBe('helper_request_invalid')
        ->and($client->calls)->toBe([]);
})->with([
    [['operation' => '1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46']],
    [['--logs' => true]],
]);

test('the status CLI reports authenticated state without preparing an operation', function (): void {
    $client = new HostUpdaterStatusCommandFixture;
    app()->instance(HostUpdaterClient::class, $client);
    $exit = Artisan::call('wayfindr:updater-status', ['--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result)->toBe(['schema' => 1, 'status' => ['revision' => 3, 'active_operation' => null, 'operation' => null]])
        ->and($client->calls)->toBe([['status', null]]);
});

test('an operation status and its bounded first log page can be inspected together', function (): void {
    $client = new HostUpdaterStatusCommandFixture;
    app()->instance(HostUpdaterClient::class, $client);
    $operationId = '1c0a7b87-e6fc-454c-a9b4-4b6d65fb5b46';
    $exit = Artisan::call('wayfindr:updater-status', ['operation' => $operationId, '--logs' => true, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['logs'])->toBe(['operation_id' => $operationId, 'events' => [], 'next_cursor' => 3, 'has_more' => false])
        ->and($client->calls)->toBe([['status', $operationId], ['logs', $operationId, 0, 50]]);
});

test('requesting logs requires an explicit operation and performs no helper request', function (): void {
    $client = new HostUpdaterStatusCommandFixture;
    app()->instance(HostUpdaterClient::class, $client);
    $exit = Artisan::call('wayfindr:updater-status', ['--logs' => true, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['status'])->toBe('failed')
        ->and($result['reason'])->toBe('helper_request_invalid')
        ->and($client->calls)->toBe([]);
});

test('status CLI failures expose only a stable code and host recovery guidance', function (bool $json, bool $known): void {
    $client = new HostUpdaterStatusCommandFixture;
    $client->failure = $known ? new HostUpdaterException('helper_timeout') : new RuntimeException('/private/host/token-private-credential');
    app()->instance(HostUpdaterClient::class, $client);
    $exit = Artisan::call('wayfindr:updater-status', $json ? ['--json' => true] : []);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain($known ? 'helper_timeout' : 'helper_unavailable')
        ->and($output)->not->toContain('private-credential', '/private/host');

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('failed');
    } else {
        expect($output)->toContain('host updater CLI');
    }
})->with([[true, true], [true, false], [false, true], [false, false]]);
