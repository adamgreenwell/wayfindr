<?php

use App\Http\Middleware\RefuseServingDuringManagedUpdate;
use App\Support\Updates\ManagedUpdateGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    $this->originalOriginStorage = app()->storagePath();
    $this->originStorage = sys_get_temp_dir().'/wayfindr-origin-'.bin2hex(random_bytes(8));
    mkdir($this->originStorage.'/framework', 0700, true);
    app()->useStoragePath($this->originStorage);
    config()->set('wayfindr.updates.helper_enabled', true);
    config()->set('wayfindr.release.version', 'v1.1.2');
    config()->set('wayfindr.release.commit', str_repeat('a', 40));
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    $this->originOperation = '80117cf8-0a4d-4709-a7d3-2635a24739cd';
    $this->originChallenge = str_repeat('b', 64);
});

afterEach(function (): void {
    app()->useStoragePath($this->originalOriginStorage);
    File::deleteDirectory($this->originStorage);
});

test('origin proof binds the release and held operation without passing intake', function (): void {
    $gate = app(ManagedUpdateGate::class);
    $gate->enter($this->originOperation);
    $request = Request::create('/up', 'GET', server: ['HTTP_X_WAYFINDR_UPDATE_CHALLENGE' => $this->originChallenge]);
    $called = false;
    $response = (new RefuseServingDuringManagedUpdate($gate))->handle($request, function () use (&$called): Response {
        $called = true;

        return new Response('writer');
    });
    $payload = json_encode([1, $this->originChallenge, $this->originOperation, '1.1.2', str_repeat('a', 40)], JSON_UNESCAPED_SLASHES);
    $derived = hash_hmac('sha256', 'wayfindr-managed-origin-v1', str_repeat('k', 32), true);
    expect($response->getStatusCode())->toBe(503)
        ->and($called)->toBeFalse()
        ->and($response->headers->get('X-Wayfindr-Update-Proof'))->toBe(hash_hmac('sha256', $payload, $derived))
        ->and($response->headers->get('X-Wayfindr-Update-Proof'))->not->toContain('base64:')
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
});

test('released origin proof has a distinct ownership reading and fresh challenge', function (): void {
    $gate = app(ManagedUpdateGate::class);
    $request = Request::create('/up', 'GET', server: ['HTTP_X_WAYFINDR_UPDATE_CHALLENGE' => $this->originChallenge]);
    $middleware = new RefuseServingDuringManagedUpdate($gate);
    $response = $middleware->handle($request, fn () => new Response('healthy', 200));
    $payload = json_encode([1, $this->originChallenge, null, '1.1.2', str_repeat('a', 40)], JSON_UNESCAPED_SLASHES);
    $derived = hash_hmac('sha256', 'wayfindr-managed-origin-v1', str_repeat('k', 32), true);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('X-Wayfindr-Update-Proof'))->toBe(hash_hmac('sha256', $payload, $derived));
    $request->headers->set('X-Wayfindr-Update-Challenge', str_repeat('c', 64));
    $fresh = $middleware->handle($request, fn () => new Response('healthy', 200));
    expect($fresh->headers->get('X-Wayfindr-Update-Proof'))->not->toBe($response->headers->get('X-Wayfindr-Update-Proof'));
});

test('origin probe is constrained to enrolled GET health requests', function (string $method, string $path, mixed $challenge, bool $enabled): void {
    config()->set('wayfindr.updates.helper_enabled', $enabled);
    $gate = app(ManagedUpdateGate::class);
    $gate->enter($this->originOperation);
    $request = Request::create($path, $method);
    if ($challenge !== null) {
        $request->headers->set('X-Wayfindr-Update-Challenge', $challenge);
    }
    $response = (new RefuseServingDuringManagedUpdate($gate))->handle($request, fn () => new Response('writer'));
    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->has('X-Wayfindr-Update-Proof'))->toBeFalse();
})->with([
    ['POST', '/up', str_repeat('b', 64), true],
    ['GET', '/api/mail/inbound', str_repeat('b', 64), true],
    ['GET', '/up', 'malformed', true],
    ['GET', '/up', null, true],
    ['GET', '/up', str_repeat('b', 64), false],
]);

test('invalid key identity or ownership state cannot generate an origin proof', function (string $fault): void {
    $gate = app(ManagedUpdateGate::class);
    $gate->enter($this->originOperation);
    match ($fault) {
        'key' => config()->set('app.key', 'base64:invalid'),
        'version' => config()->set('wayfindr.release.version', 'unknown'),
        'commit' => config()->set('wayfindr.release.commit', 'unknown'),
        'marker' => file_put_contents($gate->markerPath(), '{corrupt'),
    };
    $request = Request::create('/up', 'GET', server: ['HTTP_X_WAYFINDR_UPDATE_CHALLENGE' => $this->originChallenge]);
    $response = (new RefuseServingDuringManagedUpdate($gate))->handle($request, fn () => new Response('writer'));
    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->has('X-Wayfindr-Update-Proof'))->toBeFalse();
})->with(['key', 'version', 'commit', 'marker']);
