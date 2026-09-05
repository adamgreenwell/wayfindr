<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\OperatorSetting;
use App\Models\User;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;

uses(RefreshDatabase::class);

test('only platform operators can reach Web Push settings', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get(route('operator.settings.webpush.edit'))
        ->assertForbidden();
});

test('the Web Push form reports secret status without rendering the private key', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $settings = app(OperatorSettings::class);
    $settings->set('webpush.private_key', 'never-render-this-private-key');

    $this->actingAs($operator)
        ->get(route('operator.settings.webpush.edit'))
        ->assertOk()
        ->assertSee('Offer opt-in alerts when an agent closes the dashboard.')
        ->assertSee('a private key is configured')
        ->assertDontSee('never-render-this-private-key');
});

test('an operator can save a valid encrypted VAPID configuration without auditing secrets', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $keys = VAPID::createVapidKeys();

    $this->actingAs($operator)
        ->post(route('operator.settings.webpush.update'), [
            'subject' => 'mailto:alerts@example.test',
            'public_key' => $keys['publicKey'],
            'private_key' => $keys['privateKey'],
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'))
        ->assertSessionHas('status', 'operator.webpush.flash.saved');

    $settings = app(OperatorSettings::class);
    $privateRow = OperatorSetting::query()->where('key', 'webpush.private_key')->firstOrFail();
    $audit = AuditEvent::query()->where('action', 'operator_settings.webpush.updated')->sole();

    expect($settings->get('webpush.subject'))->toBe('mailto:alerts@example.test')
        ->and($settings->get('webpush.public_key'))->toBe($keys['publicKey'])
        ->and($privateRow->value)->not->toBe($keys['privateKey'])
        ->and(Crypt::decryptString($privateRow->value))->toBe($keys['privateKey'])
        ->and($audit->metadata)->toMatchArray([
            'status' => 'ready',
            'private_key_changed' => 'updated',
        ])
        ->and(json_encode($audit->metadata))->not->toContain($keys['privateKey'])
        ->and(json_encode($audit->metadata))->not->toContain($keys['publicKey']);
});

test('a public key cannot be replaced without its matching private key', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $current = VAPID::createVapidKeys();
    $replacement = VAPID::createVapidKeys();
    config()->set('webpush.vapid', [
        'subject' => 'mailto:alerts@example.test',
        'public_key' => $current['publicKey'],
        'private_key' => $current['privateKey'],
        'pem_file' => null,
    ]);

    $this->actingAs($operator)
        ->from(route('operator.settings.webpush.edit'))
        ->post(route('operator.settings.webpush.update'), [
            'subject' => 'mailto:alerts@example.test',
            'public_key' => $replacement['publicKey'],
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'))
        ->assertSessionHasErrors('private_key');

    expect(OperatorSetting::query()->where('key', 'webpush.public_key')->exists())->toBeFalse();
});

test('replacing the VAPID public key invalidates every old browser subscription', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $agent = User::factory()->for(Account::factory())->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_ALL, 'push' => true],
    ]);
    $current = VAPID::createVapidKeys();
    $replacement = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $current['publicKey'],
        'webpush.private_key' => $current['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();

    foreach (range(1, 3) as $index) {
        $agent->pushSubscriptions()->create([
            'endpoint' => "https://push.example.test/subscriptions/{$index}",
            'public_key' => 'browser-public-key',
            'auth_token' => 'browser-auth-token',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    $this->actingAs($operator)
        ->post(route('operator.settings.webpush.update'), [
            'subject' => 'mailto:alerts@example.test',
            'public_key' => $replacement['publicKey'],
            'private_key' => $replacement['privateKey'],
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'));

    expect(PushSubscription::query()->count())->toBe(0)
        ->and($agent->fresh()->alertPushEnabled())->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'operator_settings.webpush.updated')->sole()->metadata)
        ->toMatchArray([
            'status' => 'ready',
            'private_key_changed' => 'updated',
            'subscriptions_invalidated' => 3,
        ]);
});

test('saving the same VAPID public key preserves browser subscriptions', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $agent = User::factory()->for(Account::factory())->create();
    $keys = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $keys['publicKey'],
        'webpush.private_key' => $keys['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();
    $agent->pushSubscriptions()->create([
        'endpoint' => 'https://push.example.test/subscriptions/current',
        'public_key' => 'browser-public-key',
        'auth_token' => 'browser-auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    $this->actingAs($operator)
        ->post(route('operator.settings.webpush.update'), [
            'subject' => 'mailto:new-alerts@example.test',
            'public_key' => $keys['publicKey'],
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'));

    expect(PushSubscription::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'operator_settings.webpush.updated')->sole()->metadata)
        ->toMatchArray([
            'status' => 'ready',
            'private_key_changed' => 'unchanged',
            'subscriptions_invalidated' => 0,
        ]);
});

test('a stale operator save is revalidated against the latest committed VAPID pair', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $old = VAPID::createVapidKeys();
    $replacement = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);

    foreach ([
        'webpush.subject' => 'mailto:alerts@example.test',
        'webpush.public_key' => $old['publicKey'],
        'webpush.private_key' => $old['privateKey'],
    ] as $key => $value) {
        $settings->set($key, $value);
    }

    $settings->applyOverrides();

    // Another operator committed a complete rotation while this request still
    // holds the old config/cache snapshot and old form values.
    OperatorSetting::query()
        ->where('key', 'webpush.public_key')
        ->update(['value' => $replacement['publicKey']]);
    OperatorSetting::query()
        ->where('key', 'webpush.private_key')
        ->update(['value' => Crypt::encryptString($replacement['privateKey'])]);

    expect(config('webpush.vapid.public_key'))->toBe($old['publicKey']);

    $this->actingAs($operator)
        ->from(route('operator.settings.webpush.edit'))
        ->post(route('operator.settings.webpush.update'), [
            'subject' => 'mailto:new-alerts@example.test',
            'public_key' => $old['publicKey'],
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'))
        ->assertSessionHasErrors('private_key');

    expect(OperatorSetting::query()->where('key', 'webpush.public_key')->value('value'))
        ->toBe($replacement['publicKey'])
        ->and(Crypt::decryptString((string) OperatorSetting::query()
            ->where('key', 'webpush.private_key')
            ->value('value')))
        ->toBe($replacement['privateKey'])
        ->and(AuditEvent::query()->where('action', 'operator_settings.webpush.updated')->count())
        ->toBe(0);

    $source = file_get_contents(app_path('Http/Controllers/OperatorWebPushSettingsController.php'));

    expect($source)
        ->toContain("->where('key', 'webpush.public_key')")
        ->toContain('->lockForUpdate()')
        ->toContain('$settings->refreshFromDatabase()');
});

test('invalid VAPID input is rejected without flashing the private key', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);

    $this->actingAs($operator)
        ->from(route('operator.settings.webpush.edit'))
        ->post(route('operator.settings.webpush.update'), [
            'subject' => 'mailto:alerts@example.test',
            'public_key' => 'bad-public-key',
            'private_key' => 'private-key-that-must-not-enter-the-session',
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'))
        ->assertSessionHasErrors('public_key');

    expect(session()->getOldInput('private_key'))->toBeNull()
        ->and(OperatorSetting::query()->where('key', 'webpush.private_key')->exists())->toBeFalse();
});

test('an operator can clear the optional Web Push channel', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $keys = VAPID::createVapidKeys();
    $settings = app(OperatorSettings::class);
    $settings->set('webpush.subject', 'mailto:alerts@example.test');
    $settings->set('webpush.public_key', $keys['publicKey']);
    $settings->set('webpush.private_key', $keys['privateKey']);
    $settings->applyOverrides();

    $this->actingAs($operator)
        ->post(route('operator.settings.webpush.update'), [
            'clear_keys' => '1',
        ])
        ->assertRedirect(route('operator.settings.webpush.edit'));

    expect($settings->get('webpush.subject'))->toBe('')
        ->and($settings->get('webpush.public_key'))->toBe('')
        ->and($settings->get('webpush.private_key'))->toBe('')
        ->and(AuditEvent::query()->where('action', 'operator_settings.webpush.updated')->sole()->metadata)
        ->toMatchArray([
            'status' => 'unset',
            'private_key_changed' => 'cleared',
        ]);
});
