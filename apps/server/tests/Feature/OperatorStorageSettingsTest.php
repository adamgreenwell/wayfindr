<?php

// Operator attachment-storage GUI (ADR 0011 slice 2a): choose the local disk or
// an S3-compatible bucket and supply its connection, as DB-backed overrides,
// with a write/read/list/delete connection test. Access keys are write-only.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function storageOperator(): User
{
    return User::factory()->for(Account::factory())->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
}

function storageSettings(): OperatorSettings
{
    return app(OperatorSettings::class);
}

test('a non-operator cannot reach the storage settings', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)->get(route('operator.settings.storage.edit'))->assertForbidden();
});

test('the operator sees the storage settings form', function (): void {
    $this->actingAs(storageOperator())
        ->get(route('operator.settings.storage.edit'))
        ->assertOk()
        ->assertSee('Attachment storage')
        ->assertSee('S3-compatible bucket')
        ->assertSee('Test the connection')
        ->assertSee('Back to operator console');
});

test('saving S3 storage settings stores them as live overrides, with a real boolean flag', function (): void {
    $settings = storageSettings();

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'my-bucket',
            'region' => 'auto',
            'endpoint' => 'https://acct.r2.cloudflarestorage.com',
            's3_access_key' => 'AKIA-test',
            's3_secret_key' => 's3cr3t',
            'use_path_style' => '1',
        ])
        ->assertRedirect(route('operator.settings.storage.edit'))
        ->assertSessionHas('status');

    expect($settings->get('storage.disk'))->toBe('attachments-s3')
        ->and($settings->get('storage.s3_bucket'))->toBe('my-bucket')
        ->and($settings->get('storage.s3_region'))->toBe('auto')
        ->and($settings->get('storage.s3_endpoint'))->toBe('https://acct.r2.cloudflarestorage.com')
        ->and($settings->isSet('storage.s3_key'))->toBeTrue()
        ->and($settings->isSet('storage.s3_secret'))->toBeTrue();

    // Applying overrides lands a real boolean, not the truthy string "1".
    $settings->applyOverrides();
    expect(config('wayfindr.attachments.storage_disk'))->toBe('attachments-s3')
        ->and(config('filesystems.disks.attachments-s3.bucket'))->toBe('my-bucket')
        ->and(config('filesystems.disks.attachments-s3.use_path_style_endpoint'))->toBeTrue();
});

test('the stored access keys are never echoed to the browser', function (): void {
    $settings = storageSettings();
    $settings->set('storage.s3_key', 'super-secret-key');
    $settings->set('storage.s3_secret', 'super-secret-secret');

    $this->actingAs(storageOperator())
        ->get(route('operator.settings.storage.edit'))
        ->assertOk()
        ->assertDontSee('super-secret-key')
        ->assertDontSee('super-secret-secret');
});

test('switching to the local disk does not blank env S3 credentials', function (): void {
    $settings = storageSettings();

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), ['disk' => 'attachments'])
        ->assertRedirect();

    // Only the disk choice is stored — no empty S3 overrides that would shadow env.
    expect($settings->get('storage.disk'))->toBe('attachments')
        ->and($settings->isSet('storage.s3_bucket'))->toBeFalse()
        ->and($settings->isSet('storage.s3_key'))->toBeFalse();
});

test('choosing S3 without credentials is rejected and saves nothing', function (): void {
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'b',
            'region' => 'r',
            // no key/secret provided and none stored
        ])
        ->assertSessionHasErrors('s3_access_key');

    expect(storageSettings()->isSet('storage.disk'))->toBeFalse();
});

test('S3 requires a bucket and region', function (): void {
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), ['disk' => 'attachments-s3'])
        ->assertSessionHasErrors(['bucket', 'region']);
});

test('a validation failure never flashes S3 credentials into the session', function (): void {
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'b',
            'region' => 'r',
            'endpoint' => 'not-a-valid-url', // fails validation
            's3_access_key' => 'should-not-be-flashed',
            's3_secret_key' => 'should-not-be-flashed-either',
        ])
        ->assertSessionHasErrors('endpoint');

    // Non-secret fields are flashed for redisplay; the credentials never are —
    // they must not land in the session store as plaintext old input.
    expect(session()->getOldInput('bucket'))->toBe('b')
        ->and(session()->getOldInput('s3_access_key'))->toBeNull()
        ->and(session()->getOldInput('s3_secret_key'))->toBeNull();
});

test('saving other settings preserves a custom disk configured in env', function (): void {
    $settings = storageSettings();
    $settings->set('storage.disk', 'attachments-custom'); // an env-defined dedicated disk

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), ['disk' => 'attachments-custom'])
        ->assertSessionHasNoErrors();

    expect($settings->get('storage.disk'))->toBe('attachments-custom');
});

test('saving storage settings records an instance-scoped audit without the secret', function (): void {
    $this->actingAs(storageOperator())->post(route('operator.settings.storage.update'), [
        'disk' => 'attachments-s3',
        'bucket' => 'audit-bucket',
        'region' => 'auto',
        's3_access_key' => 'key-not-logged',
        's3_secret_key' => 'secret-not-logged',
    ]);

    $event = AuditEvent::query()->where('action', 'operator_settings.storage.updated')->firstOrFail();

    expect($event->account_id)->toBeNull() // instance-wide, not a tenant event
        ->and($event->metadata['disk'])->toBe('attachments-s3')
        ->and($event->metadata['bucket'])->toBe('audit-bucket')
        ->and(json_encode($event->metadata))->not->toContain('secret-not-logged')
        ->and(json_encode($event->metadata))->not->toContain('key-not-logged');
});

test('a storage settings change shows in the operator activity feed', function (): void {
    $operator = storageOperator();

    $this->actingAs($operator)->post(route('operator.settings.storage.update'), ['disk' => 'attachments']);

    // Instance-scoped (account_id null), so it must surface where operators look.
    $this->actingAs($operator)
        ->get(route('operator.dashboard'))
        ->assertOk()
        ->assertSee('Storage settings updated');
});

test('the path-style checkbox always submits a value so unchecking survives errors', function (): void {
    // A hidden companion field means an unchecked box still submits "0", so a
    // validation error elsewhere cannot silently re-check the saved true value.
    $this->actingAs(storageOperator())
        ->get(route('operator.settings.storage.edit'))
        ->assertOk()
        ->assertSee('name="use_path_style" value="0"', false);
});

test('the storage test passes on a working disk', function (): void {
    Storage::fake('attachments'); // default disk; probe write/read/list/delete on it

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.test'))
        ->assertRedirect(route('operator.settings.storage.edit'))
        ->assertSessionHas('status');
});

test('arriving from onboarding keeps the back link and save on the checklist', function (): void {
    $operator = storageOperator();

    $this->actingAs($operator)
        ->get(route('operator.settings.storage.edit', ['from' => 'onboarding']))
        ->assertOk()
        ->assertSee('Back to setup checklist')
        ->assertSee(route('operator.onboarding'), false);

    $this->actingAs($operator)
        ->post(route('operator.settings.storage.update'), ['disk' => 'attachments', 'from' => 'onboarding'])
        ->assertRedirect(route('operator.settings.storage.edit', ['from' => 'onboarding']));
});
