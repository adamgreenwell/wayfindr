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
