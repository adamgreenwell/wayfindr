<?php

use App\Models\Account;
use App\Models\AgentPushSubscription;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings\OperatorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

uses(RefreshDatabase::class);

test('guest is redirected from the agent profile to login', function (): void {
    $this->get('/dashboard/profile')
        ->assertRedirect('/login');
});

test('agent profile routes require an account', function (): void {
    $agent = User::factory()->create([
        'account_id' => null,
        'name' => 'Detached Agent',
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertForbidden();

    $this->actingAs($agent)
        ->put('/dashboard/profile', [
            'name' => 'Updated Agent',
        ])
        ->assertForbidden();

    $this->actingAs($agent)
        ->put('/dashboard/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertForbidden();

    expect($agent->fresh()->name)->toBe('Detached Agent')
        ->and(Hash::check('old-password', $agent->fresh()->password))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'agent.password_updated')->exists())->toBeFalse();
});

test('agent can view their profile from the application shell', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'name' => 'Ada Agent',
        'email' => 'ada@example.test',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('/dashboard/profile', false);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Agent profile')
        ->assertSee('Ada Agent')
        ->assertSee('ada@example.test')
        ->assertSee('Change password')
        ->assertSee('/dashboard/profile/password', false);
});

test('every authenticated dashboard page clears a prior agents local push subscription', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-agent-push-logout-cleanup', false)
        ->assertSee('data-agent-push-ownership-guard', false);

    $source = file_get_contents(resource_path('views/components/agent-push-ownership-guard.blade.php'));

    expect($source)
        ->toContain("document.querySelector('[data-agent-push-subscription]')")
        ->not->toContain("document.querySelector('[data-agent-push-preferences]')")
        ->toContain("payload.status === 'owned'")
        ->toContain('subscription.unsubscribe()')
        ->toContain('unsubscribeUnowned(subscription, 2)')
        ->toContain('unsubscribeUnowned(subscription, attemptsRemaining - 1)')
        ->toContain('subscriptionStatus(subscription.endpoint, 2)')
        ->toContain("var pushLifecycleLock = 'wayfindr:push-lifecycle'")
        ->toContain('navigator.locks.request(')
        ->toContain('pushLifecycleLock,')
        ->toContain("{ mode: 'exclusive' }")
        ->toContain('subscriptionStatus(endpoint, attemptsRemaining - 1)')
        ->toContain('if (! response.ok)')
        ->toContain('return unsubscribeUnowned(subscription, 2).catch')
        ->not->toContain('localStorage')
        ->not->toContain('expiresAt')
        ->not->toContain('destroyEndpoint')
        ->not->toContain("method: 'DELETE'");

    $logoutSource = file_get_contents(resource_path('views/components/agent-push-logout-cleanup.blade.php'));

    expect($logoutSource)
        ->toContain("document.querySelector('form.wf-signout')")
        ->toContain("navigator.serviceWorker.getRegistration('/wayfindr-sw.js')")
        ->toContain("endpoint.name = 'push_subscription_endpoint'")
        ->toContain("var endpoint = form.querySelector('input[name=\"push_subscription_endpoint\"]')")
        ->toContain('endpoint.value = subscription.endpoint')
        ->toContain("form.dataset.pushEndpointCapturePending = 'true'")
        ->toContain("var pushLifecycleLock = 'wayfindr:push-lifecycle'")
        ->toContain('navigator.locks.request(')
        ->toContain('pushLifecycleLock,')
        ->toContain('captureAndRequestLogout')
        ->toContain('return fetch(form.action, {')
        ->toContain('body: new FormData(form)')
        ->toContain('window.location.assign(response.url)')
        ->toContain('captureEndpoint()')
        ->toContain('then(requestLogout)')
        ->not->toContain('Promise.race')
        ->toContain('HTMLFormElement.prototype.submit.call(form)');
});

test('agent profile password form includes a hidden username for browser tooling', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'email' => 'ada@example.test',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('name="username"', false)
        ->assertSee('value="ada@example.test"', false)
        ->assertSee('autocomplete="username"', false)
        ->assertSee('hidden', false);
});

test('agent can update their alert preference mode', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => 'all'],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Alert preferences')
        ->assertSee('/dashboard/profile/alerts', false)
        ->assertSee('All site alerts I can support')
        ->assertSee('Only conversations and tickets assigned to me')
        ->assertSee('Quiet mode')
        ->assertSee('Email alerts')
        ->assertSee('Play a sound for new dashboard alerts');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'assigned',
            'email_alerts' => '1',
            'sound_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'profile.flash.alerts_updated');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'assigned',
        'email' => true,
        'sound' => true,
    ]);
});

test('agent can schedule quiet hours in their profile timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-06 02:30:00', 'UTC'));
    $agent = User::factory()->for(Account::factory())->create([
        'timezone' => 'America/New_York',
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'quiet_hours' => [
                'enabled' => true,
                'start' => '22:00',
                'end' => '07:00',
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('name="quiet_hours_enabled"', false)
        ->assertSee('name="quiet_hours_start"', false)
        ->assertSee('value="22:00"', false)
        ->assertSee('name="quiet_hours_end"', false)
        ->assertSee('value="07:00"', false)
        ->assertSee('Sounds, Web Push, and email pause during this window in America/New_York.')
        ->assertSee('Active now')
        ->assertSee('Quiet hours are active, so email alerts will resume when the quiet window ends.');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ASSIGNED,
            'email_alerts' => '1',
            'quiet_hours_enabled' => '1',
            'quiet_hours_start' => '23:15',
            'quiet_hours_end' => '06:45',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasNoErrors();

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => User::ALERT_MODE_ASSIGNED,
        'quiet_hours' => [
            'enabled' => true,
            'start' => '23:15',
            'end' => '06:45',
        ],
    ]);

    // Older or partial callers that know nothing about quiet hours must not
    // erase the schedule while changing an unrelated alert preference.
    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
        ])
        ->assertRedirect('/dashboard/profile');

    expect(data_get($agent->fresh()->alert_preferences, 'quiet_hours'))->toBe([
        'enabled' => true,
        'start' => '23:15',
        'end' => '06:45',
    ]);
});

test('enabled quiet hours require distinct valid bounds', function (array $payload, array $errors): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'quiet_hours_enabled' => '1',
            ...$payload,
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors($errors);
})->with([
    'missing start' => [['quiet_hours_end' => '07:00'], ['quiet_hours_start']],
    'missing end' => [['quiet_hours_start' => '22:00'], ['quiet_hours_end']],
    'invalid clock' => [['quiet_hours_start' => '25:00', 'quiet_hours_end' => '07:00'], ['quiet_hours_start']],
    'equal bounds' => [['quiet_hours_start' => '22:00', 'quiet_hours_end' => '22:00'], ['quiet_hours_end']],
]);

test('agent can turn the optional dashboard alert sound off', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'sound' => true,
        ],
    ]);

    expect($agent->alertSoundEnabled())->toBeTrue();

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => User::ALERT_MODE_ALL,
        'email' => true,
        'sound' => false,
    ]);
});

test('an agent can save their closed-dashboard alert preference', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'push' => false,
        ],
    ]);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => User::ALERT_MODE_ALL,
        'push' => true,
    ]);
});

test('preference saves preserve and can explicitly remove a transitional environment subscription', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'push' => true,
        ],
    ]);
    $subscription = $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test/subscriptions/transitional-profile',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aes128gcm',
    ]);
    AgentPushSubscription::withoutGlobalScopes()
        ->whereKey($subscription->id)
        ->update(['vapid_public_key_hash' => hash('sha256', 'another-environment-generation')]);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and(AgentPushSubscription::withoutGlobalScopes()->count())->toBe(1);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_subscription_endpoint' => $subscription->endpoint,
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeFalse()
        ->and(AgentPushSubscription::withoutGlobalScopes()->count())->toBe(0);
});

test('the profile exposes only a ready public VAPID key to the agent browser', function (): void {
    $keys = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'mailto:alerts@example.test',
        'public_key' => $keys['publicKey'],
        'private_key' => $keys['privateKey'],
        'pem_file' => null,
    ]);
    $agent = User::factory()->for(Account::factory())->create();

    $response = $this->actingAs($agent)->get('/dashboard/profile');

    $response
        ->assertOk()
        ->assertSee('Notify this browser after I close the dashboard')
        ->assertSee('data-agent-push-subscription', false)
        ->assertSee($keys['publicKey'])
        ->assertDontSee($keys['privateKey']);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $checkbox = (new DOMXPath($document))->query('//input[@id="push_alerts"]')->item(0);

    expect($checkbox)->toBeInstanceOf(DOMElement::class)
        ->and($checkbox->getAttribute('name'))->toBe('push_alerts')
        ->and($checkbox->hasAttribute('disabled'))->toBeTrue();
});

test('the profile fails closed when browser subscriptions use another database connection', function (): void {
    $keys = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'mailto:alerts@example.test',
        'public_key' => $keys['publicKey'],
        'private_key' => $keys['privateKey'],
        'pem_file' => null,
    ]);
    config()->set('database.connections.webpush-isolated', [
        ...config('database.connections.sqlite'),
        'database' => ':memory:',
    ]);
    config()->set('webpush.database_connection', 'webpush-isolated');
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee(__('profile.alerts.push_storage_incompatible'))
        ->assertDontSee('<script data-agent-push-subscription>', false);
});

test('profile fallback refuses a cross-database endpoint deletion', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);
    $subscription = $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test/subscriptions/cross-database',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aes128gcm',
    ]);
    $primaryConnection = config('webpush.database_connection');

    config()->set('database.connections.webpush-isolated', [
        ...config('database.connections.sqlite'),
        'database' => ':memory:',
    ]);
    config()->set('webpush.database_connection', 'webpush-isolated');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_subscription_endpoint' => $subscription->endpoint,
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('push_alerts');

    config()->set('webpush.database_connection', $primaryConnection);

    expect($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and(AgentPushSubscription::withoutGlobalScopes()->sole()->endpoint)
        ->toBe($subscription->endpoint);
});

test('unrelated preference saves skip incompatible subscription storage', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => false,
            'push' => false,
            'sound' => false,
        ],
    ]);
    config()->set('database.connections.webpush-unavailable', [
        'driver' => 'sqlite',
        'database' => storage_path('framework/testing/webpush-unavailable/push.sqlite'),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('webpush.database_connection', 'webpush-unavailable');

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ASSIGNED,
            'email_alerts' => '1',
            'sound_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => User::ALERT_MODE_ASSIGNED,
        'email' => true,
        'push' => false,
        'sound' => true,
    ]);
});

test('profile cleanup refreshes VAPID settings under the rotation lock', function (): void {
    $oldKeys = VAPID::createVapidKeys();
    $newKeys = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $oldKeys['publicKey'],
        'webpush.private_key' => $oldKeys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();
    $agent = User::factory()->for(Account::factory())->create();

    foreach ([
        'webpush.public_key' => $newKeys['publicKey'],
        'webpush.private_key' => $newKeys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $subscription = $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test/subscriptions/fresh-profile-generation',
        'public_key' => VAPID::createVapidKeys()['publicKey'],
        'auth_token' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        'content_encoding' => 'aes128gcm',
    ]);
    AgentPushSubscription::withoutGlobalScopes()
        ->whereKey($subscription->id)
        ->update(['vapid_public_key_hash' => hash('sha256', $newKeys['publicKey'])]);

    expect(config('webpush.vapid.public_key'))->toBe($oldKeys['publicKey']);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee($newKeys['publicKey'])
        ->assertDontSee($oldKeys['publicKey']);

    expect(config('webpush.vapid.public_key'))->toBe($newKeys['publicKey'])
        ->and(AgentPushSubscription::withoutGlobalScopes()->sole()->endpoint)
        ->toBe('https://push.example.test/subscriptions/fresh-profile-generation');

    $source = file_get_contents(app_path('Http/Controllers/AgentProfileController.php'));

    expect($source)
        ->toContain("->where('key', 'webpush.public_key')")
        ->toContain('->sharedLock()')
        ->toContain('$settings->refreshFromDatabase()')
        ->and(strpos($source, '$settings->refreshFromDatabase()'))
        ->toBeLessThan(strpos($source, 'AgentPushSubscription::purgeStaleFor($agent)'));
});

test('the profile requests push permission directly from the submit gesture', function (): void {
    $source = file_get_contents(resource_path('views/components/agent-push-subscription.blade.php'));
    $submitHandler = Str::after($source, "form.addEventListener('submit'");
    $enablePush = Str::before(
        Str::after($source, 'function enablePush(permissionRequest) {'),
        'function disablePush()',
    );

    expect($source)
        ->toContain('function requestPushPermission()')
        ->and($submitHandler)->toContain('pushPermission = requestPushPermission()')
        ->and(strpos($submitHandler, 'pushPermission = requestPushPermission()'))
        ->toBeLessThan(strpos($submitHandler, 'browserStateReady.then'))
        ->and($enablePush)
        ->toContain('return permissionRequest.then(function (permission)')
        ->not->toContain('Notification.requestPermission()');
});

test('the profile serializes opt in for the full request and rechecks a failed response', function (): void {
    $source = file_get_contents(resource_path('views/components/agent-push-subscription.blade.php'));
    $storeLifecycle = Str::before(
        Str::after($source, 'function storeEnabledSubscription(subscription) {'),
        'function requestPushPermission()',
    );

    expect($storeLifecycle)
        ->toContain('return navigator.locks.request(')
        ->toContain("{ mode: 'exclusive' }")
        ->toContain("navigator.serviceWorker.getRegistration('/wayfindr-sw.js')")
        ->toContain('current.endpoint !== subscription.endpoint')
        ->toContain('return subscriptionStatus(current.endpoint, 2)')
        ->toContain("if (payload.status === 'owned')")
        ->toContain("if (payload.status === 'foreign')")
        ->toContain('return storeSubscription(current).then(function (stored)')
        ->toContain("subscriptionOwnership = 'owned'")
        ->toContain('return subscriptionStatus(current.endpoint, 2)')
        ->toContain("if (afterFailure.status === 'owned')")
        ->toContain("if (afterFailure.status === 'foreign')")
        ->toContain('throw failure;')
        ->not->toContain('unsubscribe()')
        ->not->toContain("request(config.destroyEndpoint, 'DELETE'")
        ->not->toContain('setInterval')
        ->not->toContain('localStorage');

    expect($source)
        ->toContain("var pushLifecycleLock = 'wayfindr:push-lifecycle'")
        ->toContain("typeof navigator.locks.request !== 'function'")
        ->toContain('function storeEnabledSubscription(subscription)')
        ->toContain('return storeEnabledSubscription(subscription);')
        ->toContain('}).then(storeEnabledSubscription);');
});

test('the profile surfaces opt out failures until the endpoint is captured', function (): void {
    $source = file_get_contents(resource_path('views/components/agent-push-subscription.blade.php'));
    $submitHandler = Str::after($source, "form.addEventListener('submit'");

    expect($submitHandler)
        ->toContain(': disablePush().catch(function (failure)')
        ->toContain("form.querySelector('input[name=\"push_subscription_endpoint\"]')")
        ->toContain('throw failure;');
});

test('the profile cleans up an owned browser subscription after a VAPID key rotation', function (): void {
    $source = file_get_contents(resource_path('views/components/agent-push-subscription.blade.php'));
    $cleanup = Str::before(
        Str::after($source, 'function cleanStaleSubscription(subscription, removeStored, requireLocalRemoval) {'),
        'function initializeBrowserState()',
    );

    expect($cleanup)
        ->toContain('pendingRemoval(subscription.endpoint);')
        ->toContain("request(config.destroyEndpoint, 'DELETE'")
        ->toContain('subscription.unsubscribe()')
        ->toContain('if (! requireLocalRemoval)')
        ->toContain('if (unsubscribed === false)')
        ->toContain('throw new Error(config.ownedElsewhereCleanupFailedMessage)');

    expect($source)
        ->toContain('application_server_key: config.publicKey')
        ->toContain('failure.status = response.status')
        ->toContain('if (failure.status === 409)')
        ->toContain("payload.status === 'foreign'")
        ->toContain("payload.status === 'missing'")
        ->toContain("payload.generation === 'transitional'")
        ->toContain('initialBrowserEnabled = false')
        ->toContain('showError(config.reenrollMessage)')
        ->toContain('subscriptionStatus(subscription.endpoint, 2)')
        ->toContain('subscriptionStatus(endpoint, attemptsRemaining - 1)')
        ->toContain('showError(config.ownershipCheckFailedMessage)')
        ->toContain('cleanStaleSubscription(subscription, false, true)')
        ->toContain('config.ownedElsewhereCleanupFailedMessage')
        ->toContain('! usesCurrentApplicationServerKey(subscription)')
        ->toContain("cleanStaleSubscription(subscription, payload.status === 'owned')");
});

test("browser-specific push opt-out keeps the agent's other subscribed browsers active", function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'push' => true,
        ],
    ]);

    foreach (['one', 'two'] as $suffix) {
        $agent->pushSubscriptions()->create([
            'endpoint' => "https://push.example.test/subscriptions/{$suffix}",
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    // Saving unrelated alert preferences from a browser with no subscription
    // must not turn off delivery to the two browsers that are subscribed.
    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and($agent->pushSubscriptions()->count())->toBe(2);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_subscription_endpoint' => 'https://push.example.test/subscriptions/one',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and($agent->pushSubscriptions()->pluck('endpoint')->all())
        ->toBe(['https://push.example.test/subscriptions/two']);

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ALL,
            'push_subscription_endpoint' => 'https://push.example.test/subscriptions/two',
        ])
        ->assertRedirect('/dashboard/profile');

    expect($agent->fresh()->alertPushEnabled())->toBeFalse()
        ->and($agent->pushSubscriptions()->count())->toBe(0);
});

test('the profile preserves push preference while VAPID configuration is unavailable', function (): void {
    config()->set('webpush.vapid', [
        'subject' => null,
        'public_key' => null,
        'private_key' => null,
        'pem_file' => null,
    ]);
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);

    $response = $this->actingAs($agent)->get('/dashboard/profile');

    $response
        ->assertOk()
        ->assertSee('A platform operator must configure Web Push before browsers can subscribe.')
        ->assertSee('name="push_alerts" value="1"', false)
        ->assertSee('data-agent-push-ownership-guard', false);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.(string) $response->getContent());
    $xpath = new DOMXPath($document);
    $checkbox = $xpath->query('//input[@id="push_alerts"]')->item(0);

    expect($checkbox)->toBeInstanceOf(DOMElement::class)
        ->and($checkbox->hasAttribute('name'))->toBeFalse()
        ->and($checkbox->hasAttribute('disabled'))->toBeTrue()
        ->and($xpath->query('//script[@data-agent-push-subscription]')->length)->toBe(0)
        ->and($xpath->query('//script[@data-agent-push-ownership-guard]')->length)->toBe(1);
});

test('alert preference changes lock the account before the agent', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL exposes the row-lock clause used by this concurrency contract.');
    }

    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($agent)
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => User::ALERT_MODE_ASSIGNED,
            'email_alerts' => '1',
        ])
        ->assertRedirect('/dashboard/profile');

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();
    $accountLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "accounts"')
        && str_contains($query, 'for update'));
    $agentLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "users"')
        && str_contains($query, 'for update'));

    expect($accountLock)->toBeInt()
        ->and($agentLock)->toBeInt()
        ->and($accountLock)->toBeLessThan($agentLock);
});

test('agent profile explains calm alert preference controls', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('How alerts behave')
        ->assertSee('Dashboard alerts are the source of truth for support work that needs attention.')
        ->assertSee('Email alerts are optional delivery, not a separate queue.')
        ->assertSee('Quiet mode pauses new alerts without changing assignments, site access, or support responsibility.');
});

test('agent alert cadence defaults to immediate delivery', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => null,
    ]);

    expect($agent->alertCadence())->toBe('immediate');
});

test('agent can choose their email alert delivery cadence', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => true,
            'cadence' => 'immediate',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Email cadence')
        ->assertSee('Send email alerts as they happen')
        ->assertSee('Prefer digest delivery when available')
        ->assertSee('Digest delivery bundles eligible email alerts when the scheduler runs.')
        ->assertDontSee('Digest delivery is planned.');

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'all',
            'email_alerts' => '1',
            'alert_cadence' => 'digest',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'profile.flash.alerts_updated');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'email' => true,
        'cadence' => 'digest',
    ]);
});

test('agent profile shows the latest alert digest delivery status', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => true,
            'cadence' => 'digest',
            'digest_delivery' => [
                'status' => 'queued',
                'candidate_count' => 2,
                'message' => 'Queued digest email with 2 alerts.',
                'error' => 'SMTP cratered',
                'last_attempted_at' => now()->subMinutes(7)->toISOString(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Last digest')
        ->assertSee('Queued digest email')
        ->assertSee('Queued digest email with 2 alerts.')
        ->assertSee('7 minutes ago')
        ->assertDontSee('Last error')
        ->assertDontSee('SMTP cratered');
});

test('agent profile summarizes personal alert readiness', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'assigned',
            'email' => true,
            'cadence' => 'digest',
            'digest_delivery' => [
                'status' => 'queued',
                'candidate_count' => 3,
                'last_attempted_at' => now()->subMinutes(11)->toISOString(),
            ],
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Alert readiness')
        ->assertSee('Dashboard alerts')
        ->assertSee('Listening')
        ->assertSee('You will receive dashboard alerts for eligible support work.')
        ->assertSee('Alert scope')
        ->assertSee('Assigned to me')
        ->assertSee('Only conversations and tickets assigned to you create new alerts.')
        ->assertSee('Email delivery')
        ->assertSee('Ready')
        ->assertSee('Email alerts are enabled and outbound mail looks configured.')
        ->assertSee('Cadence')
        ->assertSee('Digest')
        ->assertSee('Digest delivery is preferred. Latest digest: Queued digest email 11 minutes ago.');
});

test('agent profile explains quiet dashboard-only alert readiness', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'quiet',
            'email' => false,
            'cadence' => 'immediate',
        ],
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Alert readiness')
        ->assertSee('Paused')
        ->assertSee('Quiet mode suppresses new dashboard and email alerts.')
        ->assertSee('Email delivery')
        ->assertSee('Dashboard only')
        ->assertSee('Email alerts are off for your profile.')
        ->assertSee('Cadence')
        ->assertSee('Immediate')
        ->assertSee('New eligible alerts can notify immediately when email alerts are enabled.');
});

test('agent profile flags email alerts when mail delivery needs attention', function (): void {
    config([
        'mail.default' => 'log',
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Email delivery needs attention')
        ->assertSee('MAIL_MAILER is log.')
        ->assertSee('Configure smtp, ses, postmark, resend, or another real outbound mail transport before relying on email alerts.');
});

test('agent profile confirms email alerts when mail delivery is ready', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
    ]);

    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get('/dashboard/profile')
        ->assertOk()
        ->assertSee('Email delivery ready')
        ->assertSee('MAIL_MAILER is smtp.')
        ->assertSee('php artisan wayfindr:mail-test --to=you@example.com');
});

test('agent alert preference mode must be supported', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => 'all', 'cadence' => 'immediate'],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'party-horn',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('alert_mode');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'cadence' => 'immediate',
    ]);
});

test('agent alert cadence must be supported', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => 'all', 'cadence' => 'immediate'],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'all',
            'alert_cadence' => 'every-seven-seconds',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('alert_cadence');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'cadence' => 'immediate',
    ]);
});

test('agent can disable email alert delivery while keeping dashboard alerts', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => [
            'mode' => 'all',
            'email' => true,
            'cadence' => 'digest',
        ],
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/alerts', [
            'alert_mode' => 'all',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'profile.flash.alerts_updated');

    expect($agent->fresh()->alert_preferences)->toMatchArray([
        'mode' => 'all',
        'email' => false,
        'cadence' => 'digest',
    ]);
});

test('agent can update their display name', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'name' => 'Ada Agent',
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile', [
            'name' => 'Ada Lovelace',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'profile.flash.profile_updated');

    expect($agent->fresh()->name)->toBe('Ada Lovelace');
});

test('agent can change their password with the current password', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHas('status', 'profile.flash.password_updated');

    $auditEvent = AuditEvent::query()
        ->where('action', 'agent.password_updated')
        ->firstOrFail();

    expect(Hash::check('new-password', $agent->fresh()->password))->toBeTrue()
        ->and($auditEvent->account_id)->toBe($agent->account_id)
        ->and($auditEvent->actor->is($agent))->toBeTrue()
        ->and($auditEvent->subject->is($agent))->toBeTrue();
});

test('agent cannot change their password with the wrong current password', function (): void {
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('old-password'),
    ]);

    $this->actingAs($agent)
        ->from('/dashboard/profile')
        ->put('/dashboard/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertRedirect('/dashboard/profile')
        ->assertSessionHasErrors('current_password');

    expect(Hash::check('old-password', $agent->fresh()->password))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'agent.password_updated')->exists())->toBeFalse();
});

test('a confirmation reaches the page as a sentence, not as a catalogue key', function (): void {
    // The flash travels as a key so it can be translated in the request that
    // shows it (see docs/product/dashboard-language.md). That trade is only
    // sound if the view actually translates it -- if it ever stops, every
    // confirmation on this page silently becomes `profile.flash.something`,
    // and the tests above would still pass because the session is right.
    $agent = User::factory()->for(Account::factory())->create([
        'password' => Hash::make('old-password'),
    ]);

    $submissions = [
        ['/dashboard/profile', ['name' => 'Ada Lovelace'], 'Profile updated.'],
        ['/dashboard/profile/alerts', ['alert_mode' => User::ALERT_MODE_ALL], 'Alert preferences updated.'],
        ['/dashboard/profile/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ], 'Password updated.'],
    ];

    foreach ($submissions as [$route, $payload, $expected]) {
        $this->actingAs($agent->fresh())
            ->from('/dashboard/profile')
            ->followingRedirects()
            ->put($route, $payload)
            ->assertOk()
            ->assertSee($expected)
            ->assertDontSee('profile.flash');
    }
});
