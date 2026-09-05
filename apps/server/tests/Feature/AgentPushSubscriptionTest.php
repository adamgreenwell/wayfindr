<?php

use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\User;
use App\Support\Webhooks\OutboundWebhookDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\PushSubscription;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );
});

function agentPushPayload(string $endpoint = 'https://push.example.test/subscription/one', string $seed = 'a'): array
{
    $publicKey = "\x04".str_repeat($seed, 64);

    return [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '='),
            'auth' => rtrim(strtr(base64_encode(str_repeat($seed, 16)), '+/', '-_'), '='),
        ],
        'content_encoding' => 'aes128gcm',
    ];
}

test('push subscription routes require a signed-in account agent', function (): void {
    $this->postJson(route('dashboard.profile.push-subscription.store'), agentPushPayload())
        ->assertUnauthorized();

    $detached = User::factory()->create(['account_id' => null]);

    $this->actingAs($detached)
        ->postJson(route('dashboard.profile.push-subscription.store'), agentPushPayload())
        ->assertForbidden();
});

test('an agent can store and refresh only their own browser subscription', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $payload = agentPushPayload();

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), $payload)
        ->assertOk()
        ->assertExactJson(['stored' => true]);

    $subscription = PushSubscription::query()->sole();

    expect($subscription->endpoint)->toBe($payload['endpoint'])
        ->and($subscription->subscribable_id)->toBe($agent->id)
        ->and($subscription->subscribable_type)->toBe($agent->getMorphClass())
        ->and($subscription->public_key)->toBe($payload['keys']['p256dh'])
        ->and($subscription->auth_token)->toBe($payload['keys']['auth']);

    $refreshed = agentPushPayload(seed: 'b');

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), $refreshed)
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and($subscription->fresh()->public_key)->toBe($refreshed['keys']['p256dh']);
});

test('a browser endpoint is never reassigned from another signed-in profile', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create();
    $other = User::factory()->for($account)->create();
    $payload = agentPushPayload();
    $owner->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);

    $this->actingAs($other)
        ->postJson(route('dashboard.profile.push-subscription.store'), agentPushPayload(seed: 'b'))
        ->assertConflict();

    $subscription = PushSubscription::query()->sole();

    expect($subscription->subscribable_id)->toBe($owner->id)
        ->and($subscription->public_key)->toBe($payload['keys']['p256dh']);
});

test('subscription status distinguishes this agent from another profile without exposing keys', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create();
    $other = User::factory()->for($account)->create();
    $payload = agentPushPayload();
    $owner->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);

    $this->actingAs($owner)
        ->postJson(route('dashboard.profile.push-subscription.status'), [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertExactJson(['status' => 'owned']);

    $this->actingAs($other)
        ->postJson(route('dashboard.profile.push-subscription.status'), [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertExactJson(['status' => 'foreign']);

    $this->actingAs($other)
        ->postJson(route('dashboard.profile.push-subscription.status'), [
            'endpoint' => 'https://push.example.test/subscription/missing',
        ])
        ->assertExactJson(['status' => 'missing']);
});

test('an accountless platform operator can identify a prior agents browser subscription', function (): void {
    $owner = User::factory()->for(Account::factory())->create();
    $operator = User::factory()->create([
        'account_id' => null,
        'platform_role' => PlatformRole::Operator,
    ]);
    $payload = agentPushPayload();
    $owner->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);

    $this->actingAs($operator)
        ->postJson(route('dashboard.profile.push-subscription.status'), [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertExactJson(['status' => 'foreign']);
});

test('unsubscribing deletes only the current agents matching endpoint', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);
    $other = User::factory()->for($account)->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);
    $ownPayload = agentPushPayload('https://push.example.test/subscription/own');
    $otherPayload = agentPushPayload('https://push.example.test/subscription/other', 'b');

    foreach ([[$agent, $ownPayload], [$other, $otherPayload]] as [$owner, $payload]) {
        $owner->pushSubscriptions()->create([
            'endpoint' => $payload['endpoint'],
            'public_key' => $payload['keys']['p256dh'],
            'auth_token' => $payload['keys']['auth'],
            'content_encoding' => 'aes128gcm',
        ]);
    }

    $this->actingAs($agent)
        ->deleteJson(route('dashboard.profile.push-subscription.destroy'), [
            'endpoint' => $otherPayload['endpoint'],
        ])
        ->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(2);
    expect($agent->fresh()->alertPushEnabled())->toBeTrue();

    $this->actingAs($agent)
        ->deleteJson(route('dashboard.profile.push-subscription.destroy'), [
            'endpoint' => $ownPayload['endpoint'],
        ])
        ->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(PushSubscription::query()->sole()->subscribable_id)->toBe($other->id)
        ->and($agent->fresh()->alertPushEnabled())->toBeFalse()
        ->and($other->fresh()->alertPushEnabled())->toBeTrue();
});

test('subscription input requires HTTPS and correctly sized Web Push keys', function (array $overrides, array $errors): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->postJson(
            route('dashboard.profile.push-subscription.store'),
            array_replace_recursive(agentPushPayload(), $overrides),
        )
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'insecure endpoint' => [[
        'endpoint' => 'http://push.example.test/subscription/one',
    ], ['endpoint']],
    'short public key' => [[
        'keys' => ['p256dh' => 'BA'],
    ], ['keys.p256dh']],
    'short auth token' => [[
        'keys' => ['auth' => 'YQ'],
    ], ['keys.auth']],
]);

test('a subscription endpoint must resolve only to public addresses', function (): void {
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['127.0.0.1']),
    );
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), agentPushPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
});

test('an agent cannot accumulate more than ten browser subscriptions', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    foreach (range(1, 10) as $index) {
        $payload = agentPushPayload("https://push.example.test/subscription/{$index}");
        $agent->pushSubscriptions()->create([
            'endpoint' => $payload['endpoint'],
            'public_key' => $payload['keys']['p256dh'],
            'auth_token' => $payload['keys']['auth'],
            'content_encoding' => 'aes128gcm',
        ]);
    }

    $this->actingAs($agent)
        ->postJson(
            route('dashboard.profile.push-subscription.store'),
            agentPushPayload('https://push.example.test/subscription/eleven'),
        )
        ->assertUnprocessable();

    expect($agent->pushSubscriptions()->count())->toBe(10);
});
