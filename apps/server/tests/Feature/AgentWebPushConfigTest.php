<?php

use App\Support\AgentWebPushConfig;
use Minishlink\WebPush\VAPID;

test('web push stays optional until all VAPID values are present', function (): void {
    config()->set('webpush.vapid', [
        'subject' => null,
        'public_key' => null,
        'private_key' => null,
        'pem_file' => null,
    ]);

    $webPush = app(AgentWebPushConfig::class);

    expect($webPush->assessment())->toMatchArray([
        'status' => 'unset',
        'has_subject' => false,
        'has_public_key' => false,
        'has_private_key' => false,
    ])
        ->and($webPush->isReady())->toBeFalse()
        ->and($webPush->publicKeyForBrowser())->toBeNull();

    config()->set('webpush.vapid.subject', 'mailto:alerts@example.test');

    expect($webPush->assessment()['status'])->toBe('incomplete');
});

test('web push rejects malformed subjects and key material', function (array $values): void {
    expect(app(AgentWebPushConfig::class)->assessValues(...$values)['status'])->toBe('invalid');
})->with([
    'plain email subject' => [[
        'alerts@example.test',
        rtrim(strtr(base64_encode("\x04".str_repeat('p', 64)), '+/', '-_'), '='),
        rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='),
    ]],
    'http URL subject' => [[
        'http://example.test',
        rtrim(strtr(base64_encode("\x04".str_repeat('p', 64)), '+/', '-_'), '='),
        rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='),
    ]],
    'short public key' => [[
        'mailto:alerts@example.test',
        'too-short',
        rtrim(strtr(base64_encode(str_repeat('s', 32)), '+/', '-_'), '='),
    ]],
]);

test('valid VAPID credentials expose only the public browser key', function (): void {
    $keys = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'https://support.example.test/web-push',
        'public_key' => $keys['publicKey'],
        'private_key' => $keys['privateKey'],
        'pem_file' => null,
    ]);

    $webPush = app(AgentWebPushConfig::class);

    expect($webPush->assessment())->toMatchArray([
        'status' => 'ready',
        'has_subject' => true,
        'has_public_key' => true,
        'has_private_key' => true,
    ])
        ->and($webPush->isReady())->toBeTrue()
        ->and($webPush->publicKeyForBrowser())->toBe($keys['publicKey'])
        ->and(json_encode($webPush->assessment()))->not->toContain($keys['privateKey']);
});

test('individually valid but mismatched VAPID keys are rejected', function (): void {
    $public = VAPID::createVapidKeys();
    $private = VAPID::createVapidKeys();

    expect(app(AgentWebPushConfig::class)->assessValues(
        'mailto:alerts@example.test',
        $public['publicKey'],
        $private['privateKey'],
    )['status'])->toBe('invalid');
});
