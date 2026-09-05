<?php

use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\AgentWebPushConfig;
use App\Support\Settings\OperatorSettings;
use App\Support\Webhooks\OutboundWebhookDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use NotificationChannels\WebPush\PushSubscription;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );

    $settings = app(OperatorSettings::class);
    $settings->set('webpush.public_key', 'current-vapid-public-key');
    $settings->applyOverrides();
});

function agentPushPayload(string $endpoint = 'https://push.example.test/subscription/one', string $seed = 'a'): array
{
    $publicKey = "\x04".str_repeat($seed, 64);

    return [
        'endpoint' => $endpoint,
        'application_server_key' => (string) config('webpush.vapid.public_key'),
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
        ->and($subscription->auth_token)->toBe($payload['keys']['auth'])
        ->and(AgentPushSubscription::query()->sole()->vapid_public_key_hash)
        ->toBe(AgentPushSubscription::currentVapidPublicKeyHash());

    $refreshed = agentPushPayload(seed: 'b');

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), $refreshed)
        ->assertOk();

    expect(PushSubscription::query()->count())->toBe(1)
        ->and($subscription->fresh()->public_key)->toBe($refreshed['keys']['p256dh']);
});

test('a stale profile cannot recreate a subscription after VAPID rotation', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $stalePayload = agentPushPayload();

    // Simulate a committed rotation without its cache-version bump reaching
    // this request yet. The store path must refresh under the shared lock.
    OperatorSetting::query()
        ->where('key', 'webpush.public_key')
        ->update(['value' => 'rotated-vapid-public-key']);

    expect(config('webpush.vapid.public_key'))->toBe('current-vapid-public-key');

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), $stalePayload)
        ->assertConflict()
        ->assertExactJson([
            'message' => __('profile.alerts.push_configuration_changed'),
        ]);

    expect(PushSubscription::query()->count())->toBe(0);

    $source = file_get_contents(app_path('Http/Controllers/AgentPushSubscriptionController.php'));

    expect($source)
        ->toContain("->where('key', 'webpush.public_key')")
        ->toContain('->sharedLock()')
        ->toContain('$settings->refreshFromDatabase()')
        ->toContain("\$validated['application_server_key']");
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

test('subscription status refreshes VAPID settings before deleting a generation', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $payload = agentPushPayload();
    $subscription = $agent->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);
    $rotatedKey = 'rotated-vapid-public-key';

    // Model this old process checking an endpoint another process enrolled
    // after the operator committed a rotation.
    OperatorSetting::query()
        ->where('key', 'webpush.public_key')
        ->update(['value' => $rotatedKey]);
    AgentPushSubscription::withoutGlobalScopes()
        ->whereKey($subscription->id)
        ->update(['vapid_public_key_hash' => hash('sha256', $rotatedKey)]);

    expect(config('webpush.vapid.public_key'))->toBe('current-vapid-public-key');

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.status'), [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertExactJson(['status' => 'owned']);

    expect(config('webpush.vapid.public_key'))->toBe($rotatedKey)
        ->and(AgentPushSubscription::withoutGlobalScopes()->sole()->endpoint)
        ->toBe($payload['endpoint']);

    $source = file_get_contents(app_path('Http/Controllers/AgentPushSubscriptionController.php'));
    $status = str($source)->between('public function status(', 'public function store(')->toString();

    expect($status)
        ->toContain("->where('key', 'webpush.public_key')")
        ->toContain('->sharedLock()')
        ->toContain('$settings->refreshFromDatabase()')
        ->and(strpos($status, '$settings->refreshFromDatabase()'))
        ->toBeLessThan(strpos($status, '$subscription->usesCurrentVapidGeneration()'));
});

test('an environment-backed process cannot purge another rolling-deploy generation', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $payload = agentPushPayload();
    $subscription = $agent->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);
    $otherProcessKey = 'other-process-environment-vapid-key';

    OperatorSetting::query()->where('key', 'webpush.public_key')->delete();
    AgentPushSubscription::withoutGlobalScopes()
        ->whereKey($subscription->id)
        ->update(['vapid_public_key_hash' => hash('sha256', $otherProcessKey)]);

    $environmentSettings = new OperatorSettings;
    $environmentSettings->captureBaseline();
    app()->instance(OperatorSettings::class, $environmentSettings);

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.status'), [
            'endpoint' => $payload['endpoint'],
        ])
        ->assertExactJson([
            'status' => 'owned',
            'generation' => 'transitional',
        ]);

    expect(AgentPushSubscription::canPurgeOtherVapidGenerations())->toBeFalse()
        ->and(AgentPushSubscription::purgeStaleFor($agent))->toBe(0)
        ->and(AgentPushSubscription::withoutGlobalScopes()->sole()->endpoint)
        ->toBe($payload['endpoint']);
});

test('ownership checks remain available across rapid authenticated navigation', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $payload = agentPushPayload();
    $agent->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);

    foreach (range(1, 35) as $navigation) {
        $this->actingAs($agent)
            ->postJson(route('dashboard.profile.push-subscription.status'), [
                'endpoint' => $payload['endpoint'],
            ])
            ->assertOk()
            ->assertExactJson(['status' => 'owned']);
    }
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

test('browser endpoint deletion refuses a separate subscription database', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    config()->set('database.connections.webpush-isolated', [
        ...config('database.connections.sqlite'),
        'database' => ':memory:',
    ]);
    config()->set('webpush.database_connection', 'webpush-isolated');

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), agentPushPayload())
        ->assertConflict()
        ->assertJsonPath('message', __('profile.alerts.push_storage_incompatible'));

    $this->actingAs($agent)
        ->deleteJson(route('dashboard.profile.push-subscription.destroy'), [
            'endpoint' => 'https://push.example.test/subscriptions/cross-database',
        ])
        ->assertConflict()
        ->assertJsonPath('message', __('profile.alerts.push_storage_incompatible'));
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
    'missing VAPID generation' => [[
        'application_server_key' => null,
    ], ['application_server_key']],
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

test('an environment VAPID rotation isolates old subscriptions while enforcing the browser limit', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    foreach (range(1, 10) as $index) {
        $payload = agentPushPayload("https://push.example.test/subscription/old-{$index}");
        $agent->pushSubscriptions()->create([
            'endpoint' => $payload['endpoint'],
            'public_key' => $payload['keys']['p256dh'],
            'auth_token' => $payload['keys']['auth'],
            'content_encoding' => 'aes128gcm',
        ]);
    }

    $oldHash = AgentPushSubscription::currentVapidPublicKeyHash();
    OperatorSetting::query()->where('key', 'webpush.public_key')->delete();
    config()->set('webpush.vapid.public_key', 'rotated-environment-vapid-public-key');

    // Model a fresh process after environment rotation: its settings baseline
    // is the new environment, with no operator override pinning the old key.
    $freshSettings = new OperatorSettings;
    $freshSettings->captureBaseline();
    app()->instance(OperatorSettings::class, $freshSettings);

    $newPayload = agentPushPayload('https://push.example.test/subscription/new', 'b');

    $this->actingAs($agent)
        ->postJson(route('dashboard.profile.push-subscription.store'), $newPayload)
        ->assertOk()
        ->assertExactJson(['stored' => true]);

    $current = AgentPushSubscription::query()->sole();

    expect(PushSubscription::query()->count())->toBe(11)
        ->and($current->endpoint)->toBe($newPayload['endpoint'])
        ->and($current->vapid_public_key_hash)->not->toBe($oldHash)
        ->and($current->vapid_public_key_hash)->toBe(AgentPushSubscription::currentVapidPublicKeyHash())
        ->and(AgentPushSubscription::canPurgeOtherVapidGenerations())->toBeFalse();
});

test('a fallback VAPID value cannot purge subscriptions after the settings store fails', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $payload = agentPushPayload();
    $subscription = $agent->pushSubscriptions()->create([
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
        'content_encoding' => 'aes128gcm',
    ]);

    Cache::partialMock()
        ->shouldReceive('get')
        ->once()
        ->andThrow(new RuntimeException('The operator settings cache is unavailable.'));
    app(OperatorSettings::class)->applyOverrides();

    expect(app(OperatorSettings::class)->valuesAreAuthoritative())->toBeFalse()
        ->and(app(AgentWebPushConfig::class)->assessment()['status'])->toBe('unavailable')
        ->and(app(AgentWebPushConfig::class)->publicKeyForBrowser())->toBeNull()
        ->and($subscription->usesCurrentVapidGeneration())->toBeTrue()
        ->and(AgentPushSubscription::purgeStaleFor($agent))->toBe(0)
        ->and(AgentPushSubscription::withoutGlobalScopes()->count())->toBe(1);
});
