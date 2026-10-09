<?php

declare(strict_types=1);

use App\Support\Updates\ManagedUpdateGate;
use App\Support\Updates\ManagedUpdateMaintenanceMode;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->ordinaryStorage = app()->storagePath();
    $this->windowStorage = sys_get_temp_dir().'/wayfindr-upgrade-window-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->windowStorage);

    foreach (['app', 'framework', 'framework/views', 'framework/sessions', 'logs'] as $directory) {
        mkdir($this->windowStorage.'/'.$directory, 0700, true);
    }

    config()->set('wayfindr.release.version', '1.1.1');
    config()->set('wayfindr.release.commit', str_repeat('a', 40));
    config()->set('wayfindr.release.installation_profile', 'image');
    config()->set('wayfindr.erasure.ledger_path', storage_path('app/erasure-ledger'));
    $this->windowOperation = '80117cf8-0a4d-4709-a7d3-2635a24739cd';
});

afterEach(function (): void {
    PreventRequestsDuringMaintenance::flushState();
    app()->useStoragePath($this->ordinaryStorage);
    File::deleteDirectory($this->windowStorage);
});

function upgradeWindowReceipt(string $operation, string $action = 'status'): array
{
    $exit = Artisan::call('wayfindr:upgrade-window', [
        'operation' => $operation, '--action' => $action, '--json' => true,
    ]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

test('the window CLI reports a fixed receipt and idempotently holds across instances', function (): void {
    [$exit, $receipt] = upgradeWindowReceipt($this->windowOperation, 'enter');

    expect($exit)->toBe(0)
        ->and($receipt)->toBe([
            'schema' => 1,
            'operation_id' => $this->windowOperation,
            'held' => true,
            'ordinary_maintenance' => false,
            'source' => ['version' => '1.1.1', 'commit' => str_repeat('a', 40), 'profile' => 'image'],
            'ledger_supported' => true,
        ]);

    $marker = file_get_contents(storage_path('framework/managed-upgrade.json'));
    expect((new ManagedUpdateGate)->status()['operation_id'])->toBe($this->windowOperation);
    expect(upgradeWindowReceipt($this->windowOperation, 'enter'))->toBe([$exit, $receipt]);
    expect(file_get_contents(storage_path('framework/managed-upgrade.json')))->toBe($marker);

    [$released, $result] = upgradeWindowReceipt($this->windowOperation, 'release');
    expect($released)->toBe(0)
        ->and($result['held'])->toBeFalse()
        ->and($result['operation_id'])->toBeNull()
        ->and(app()->isDownForMaintenance())->toBeFalse();
});

test('enter and release preserve pre-existing ordinary maintenance bytes and options', function (): void {
    $ordinary = json_encode([
        'except' => [], 'secret' => 'operator-secret', 'refresh' => '37',
        'retry' => 123, 'status' => 503, 'template' => null,
    ], JSON_PRETTY_PRINT);
    file_put_contents(storage_path('framework/down'), $ordinary);
    file_put_contents(storage_path('framework/maintenance.php'), '<?php /* ordinary pre-render marker */');

    [$entered, $receipt] = upgradeWindowReceipt($this->windowOperation, 'enter');
    expect($entered)->toBe(0)
        ->and($receipt['ordinary_maintenance'])->toBeTrue()
        ->and(app()->maintenanceMode())->toBeInstanceOf(ManagedUpdateMaintenanceMode::class)
        ->and(app()->maintenanceMode()->data()['secret'])->toBeNull();

    expect(Artisan::call('up'))->toBe(1)
        ->and(file_get_contents(storage_path('framework/down')))->toBe($ordinary)
        ->and(file_get_contents(storage_path('framework/maintenance.php')))->toBe('<?php /* ordinary pre-render marker */')
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue();

    [$released, $receipt] = upgradeWindowReceipt($this->windowOperation, 'release');
    expect($released)->toBe(0)
        ->and($receipt['ordinary_maintenance'])->toBeTrue()
        ->and(file_get_contents(storage_path('framework/down')))->toBe($ordinary)
        ->and(app()->maintenanceMode()->data()['secret'])->toBe('operator-secret');
});

test('artisan up cannot clear a managed hold when ordinary maintenance is absent', function (): void {
    app(ManagedUpdateGate::class)->enter($this->windowOperation);

    expect(Artisan::call('up'))->toBe(1)
        ->and(app(ManagedUpdateGate::class)->active())->toBeTrue()
        ->and(app()->isDownForMaintenance())->toBeTrue();
});

test('the managed hold rejects PHP intake before any database query', function (string $method, string $path): void {
    app(ManagedUpdateGate::class)->enter($this->windowOperation);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->json($method, $path, ['content' => 'held request']);

    $response->assertStatus(503)
        ->assertHeader('Retry-After', '60')
        ->assertDontSee($this->windowOperation);
    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($queries)->toBe([]);
})->with([
    ['POST', '/api/widget/presence'],
    ['POST', '/api/conversations'],
    ['POST', '/api/conversations/fixture/attachments'],
    ['POST', '/api/conversations/fixture/cobrowse-mutations'],
    ['POST', '/api/widget/broadcasting/auth'],
    ['POST', '/api/mail/inbound'],
    ['POST', '/api/integrations/github/webhook/1'],
    ['POST', '/api/integrations/gitlab/webhook/1'],
    ['POST', '/api/integrations/jira/webhook/1'],
    ['POST', '/api/v1/conversations'],
    ['PATCH', '/api/v1/tickets/1'],
    ['POST', '/broadcasting/auth'],
    ['GET', '/widget.js'],
    ['GET', '/up'],
]);

test('a valid ordinary maintenance bypass cookie cannot enter the managed window', function (): void {
    $calls = 0;
    Route::post('/managed-window-write-probe', function () use (&$calls) {
        $calls++;

        return response('writer reached');
    });
    app()->maintenanceMode()->activate(['status' => 503, 'secret' => 'operator-secret']);
    $cookie = MaintenanceModeBypassCookie::create('operator-secret');
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())
        ->post('/managed-window-write-probe')->assertOk();

    app(ManagedUpdateGate::class)->enter($this->windowOperation);
    $this->post('/managed-window-write-probe')->assertStatus(503);
    expect($calls)->toBe(1);
});

test('ordinary excluded paths cannot bypass the managed window', function (): void {
    $calls = 0;
    Route::post('/managed-window-write-probe', function () use (&$calls) {
        $calls++;

        return response('writer reached');
    });
    PreventRequestsDuringMaintenance::except('managed-window-write-probe');
    app()->maintenanceMode()->activate(['status' => 503]);
    $this->post('/managed-window-write-probe')->assertOk();

    app(ManagedUpdateGate::class)->enter($this->windowOperation);
    $this->post('/managed-window-write-probe')->assertStatus(503);
    expect($calls)->toBe(1);
});

test('the managed maintenance binding pauses the real queue worker and scheduled events', function (): void {
    $worker = app('queue.worker');
    $shouldRun = new ReflectionMethod($worker, 'daemonShouldRun');
    $options = new WorkerOptions;
    $event = Schedule::command('inspire')->everyMinute();

    expect($shouldRun->invoke($worker, $options, 'sync', 'default'))->toBeTrue()
        ->and($event->isDue(app()))->toBeTrue();

    app(ManagedUpdateGate::class)->enter($this->windowOperation);
    expect($shouldRun->invoke($worker, $options, 'sync', 'default'))->toBeFalse()
        ->and($event->isDue(app()))->toBeFalse();

    app(ManagedUpdateGate::class)->release($this->windowOperation);
    expect($shouldRun->invoke($worker, $options, 'sync', 'default'))->toBeTrue()
        ->and($event->isDue(app()))->toBeTrue();
});

test('corrupt managed state holds traffic and refuses status without exposing raw content', function (): void {
    file_put_contents(storage_path('framework/managed-upgrade.json'), 'customer@example.test /private/credential');

    $this->postJson('/api/mail/inbound')->assertStatus(503)->assertDontSee('customer@example.test');
    expect(app()->isDownForMaintenance())->toBeTrue();

    [$exit, $receipt] = upgradeWindowReceipt($this->windowOperation);
    expect($exit)->toBe(1)
        ->and($receipt)->toBe(['schema' => 1, 'status' => 'failed', 'reason' => 'managed_update_state_invalid']);
});

test('the window CLI refuses another owner, invalid arguments, and custom ledger custody', function (): void {
    app(ManagedUpdateGate::class)->enter($this->windowOperation);
    $other = 'c6e0bb1a-83e8-4fa9-bf5c-81278228a6e2';

    foreach (['enter', 'status', 'release'] as $action) {
        [$exit, $receipt] = upgradeWindowReceipt($other, $action);
        expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_update_busy');
    }

    [$exit, $receipt] = upgradeWindowReceipt('../../private', 'enter');
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_update_request_invalid');
    [$exit, $receipt] = upgradeWindowReceipt($this->windowOperation, 'apply');
    expect($exit)->toBe(1)->and($receipt['reason'])->toBe('managed_update_request_invalid');

    config()->set('wayfindr.erasure.ledger_path', '/private/custom-erasure-ledger');
    [$exit, $receipt] = upgradeWindowReceipt($this->windowOperation);
    expect($exit)->toBe(0)->and($receipt['ledger_supported'])->toBeFalse();
    expect(json_encode($receipt))->not->toContain('/private', 'custom-erasure-ledger');
});

test('window source identity never forwards arbitrary configured text', function (string $version): void {
    config()->set('wayfindr.release.version', $version);
    config()->set('wayfindr.release.commit', '/private/secret');

    [$exit, $receipt] = upgradeWindowReceipt($this->windowOperation);
    expect($exit)->toBe(0)
        ->and($receipt['source'])->toBe(['version' => null, 'commit' => null, 'profile' => 'image']);
})->with(['customer@example.test', '1.1.1+private-token', '1.1.1-private-token']);

test('the window receipt canonicalizes the official published Git tag identity', function (): void {
    config()->set('wayfindr.release.version', 'v1.1.1');

    [$exit, $receipt] = upgradeWindowReceipt($this->windowOperation);
    expect($exit)->toBe(0)
        ->and($receipt['source'])->toBe(['version' => '1.1.1', 'commit' => str_repeat('a', 40), 'profile' => 'image']);
});
