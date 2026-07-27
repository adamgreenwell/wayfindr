<?php

// Operator attachment-storage GUI (ADR 0011 slice 2a): choose the local disk or
// an S3-compatible bucket and supply its connection, as DB-backed overrides,
// with a write/read/list/delete connection test. Access keys are write-only.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ConversationMessageAttachment;
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
            'acl' => 'private', // R2 rejects the default; the GUI must be able to set this
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
        ->and($settings->get('storage.s3_acl'))->toBe('private')
        ->and($settings->isSet('storage.s3_key'))->toBeTrue()
        ->and($settings->isSet('storage.s3_secret'))->toBeTrue();

    // Applying overrides lands a real boolean, the private ACL R2 needs, and the
    // custom endpoint for a compatible store.
    $settings->applyOverrides();
    expect(config('wayfindr.attachments.storage_disk'))->toBe('attachments-s3')
        ->and(config('filesystems.disks.attachments-s3.bucket'))->toBe('my-bucket')
        ->and(config('filesystems.disks.attachments-s3.endpoint'))->toBe('https://acct.r2.cloudflarestorage.com')
        ->and(config('filesystems.disks.attachments-s3.options.ACL'))->toBe('private')
        ->and(config('filesystems.disks.attachments-s3.use_path_style_endpoint'))->toBeTrue();
});

test('a blank endpoint applies as null so AWS regional resolution works', function (): void {
    $settings = storageSettings();
    // Pretend a stale R2 endpoint is in env; the operator switches to AWS and
    // clears the endpoint field.
    config()->set('filesystems.disks.attachments-s3.endpoint', 'https://stale.example.test');

    $this->actingAs(storageOperator())->post(route('operator.settings.storage.update'), [
        'disk' => 'attachments-s3',
        'bucket' => 'aws-bucket',
        'region' => 'us-east-1',
        'acl' => 'bucket-owner-full-control',
        'endpoint' => '', // AWS: leave blank
        's3_access_key' => 'k',
        's3_secret_key' => 's',
    ])->assertRedirect();

    $settings->applyOverrides();
    // Applied as null (not '' and not the stale env value) — the AWS SDK resolves
    // the regional endpoint itself.
    expect(config('filesystems.disks.attachments-s3.endpoint'))->toBeNull();
});

test('a public ACL is rejected', function (): void {
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'b',
            'region' => 'r',
            's3_access_key' => 'k',
            's3_secret_key' => 's',
            'acl' => 'public-read', // attachments must never be public
        ])
        ->assertSessionHasErrors('acl');
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

test('switching to local succeeds even with a malformed S3 endpoint prefilled', function (): void {
    $settings = storageSettings();
    $settings->set('storage.s3_endpoint', 'not-a-valid-url'); // a broken S3 config

    // The operator switches to local; the single form still submits the bad
    // (now inactive) endpoint — it must not block the recovery path.
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments',
            'endpoint' => 'not-a-valid-url',
        ])
        ->assertSessionHasNoErrors();

    expect($settings->get('storage.disk'))->toBe('attachments');
});

test('the storage test reports a disk-construction failure instead of 500ing', function (): void {
    // A custom attachments-* disk with an unsupported driver: it passes the
    // safe-disk check but Storage::disk() throws when building it.
    config()->set('wayfindr.attachments.storage_disk', 'attachments-broken');
    config()->set('filesystems.disks.attachments-broken', ['driver' => 'not-a-real-driver', 'root' => 'x']);

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.test'))
        ->assertRedirect(route('operator.settings.storage.edit'))
        ->assertSessionHas('error');
});

test('S3 can be configured without static credentials (default provider chain)', function (): void {
    $settings = storageSettings();

    // An EC2/ECS/IRSA role (or the environment/shared-config provider) needs no
    // static keys — the connection test surfaces a real auth failure if any.
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'role-bucket',
            'region' => 'us-east-1',
            'acl' => 'private',
            // no key/secret
        ])
        ->assertSessionHasNoErrors();

    expect($settings->get('storage.disk'))->toBe('attachments-s3')
        ->and($settings->get('storage.s3_bucket'))->toBe('role-bucket')
        ->and($settings->isSet('storage.s3_key'))->toBeFalse();
});

test('replacing only one S3 credential is rejected', function (): void {
    $settings = storageSettings();
    // A full pair is already stored.
    $settings->set('storage.s3_key', 'old-key');
    $settings->set('storage.s3_secret', 'old-secret');

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'b',
            'region' => 'r',
            'acl' => 'private',
            's3_access_key' => 'new-key-only', // secret omitted — a mismatched pair
        ])
        ->assertSessionHasErrors('s3_access_key');

    // The stored pair is left intact, not half-replaced.
    expect($settings->get('storage.s3_key'))->toBe('old-key')
        ->and($settings->get('storage.s3_secret'))->toBe('old-secret');
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

test('the role option clears both stored S3 credentials together', function (): void {
    $settings = storageSettings();
    $settings->set('storage.disk', 'attachments-s3');
    $settings->set('storage.s3_bucket', 'role-bucket');
    $settings->set('storage.s3_region', 'us-east-1');
    $settings->set('storage.s3_key', 'static-key');
    $settings->set('storage.s3_secret', 'static-secret');

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'role-bucket',
            'region' => 'us-east-1',
            'acl' => 'private',
            's3_no_keys' => '1', // migrate to an instance role
        ])
        ->assertSessionHasNoErrors();

    // Both cleared to an explicit empty override → the SDK provider chain is used.
    expect($settings->get('storage.s3_key'))->toBe('')
        ->and($settings->get('storage.s3_secret'))->toBe('');
});

test('clearing keys and entering new keys at once is rejected', function (): void {
    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'b',
            'region' => 'r',
            'acl' => 'private',
            's3_no_keys' => '1',
            's3_access_key' => 'new-key',
            's3_secret_key' => 'new-secret',
        ])
        ->assertSessionHasErrors('s3_access_key');
});

test('changing the S3 location is blocked while attachments already exist there', function (): void {
    $settings = storageSettings();
    $settings->set('storage.disk', 'attachments-s3');
    $settings->set('storage.s3_bucket', 'current-bucket');
    $settings->set('storage.s3_region', 'us-east-1');
    $settings->set('storage.s3_key', 'k');
    $settings->set('storage.s3_secret', 's');
    // A stored attachment lives on that disk.
    ConversationMessageAttachment::factory()->create(['storage_disk' => 'attachments-s3']);

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'a-different-bucket', // location change would strand existing files
            'region' => 'us-east-1',
            'acl' => 'private',
        ])
        ->assertSessionHasErrors('bucket');

    expect($settings->get('storage.s3_bucket'))->toBe('current-bucket'); // unchanged
});

test('changing the S3 location is blocked while S3 is the active disk, even with no attachments yet', function (): void {
    $settings = storageSettings();
    $settings->set('storage.disk', 'attachments-s3'); // S3 is the live upload target
    $settings->set('storage.s3_bucket', 'current-bucket');
    $settings->set('storage.s3_region', 'us-east-1');
    $settings->set('storage.s3_key', 'k');
    $settings->set('storage.s3_secret', 's');
    // No attachments recorded on the disk yet — but an upload could be in flight.

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'a-different-bucket',
            'region' => 'us-east-1',
            'acl' => 'private',
        ])
        ->assertSessionHasErrors('bucket');

    expect($settings->get('storage.s3_bucket'))->toBe('current-bucket');
});

test('rotating S3 credentials is allowed while attachments exist (same location)', function (): void {
    $settings = storageSettings();
    $settings->set('storage.disk', 'attachments-s3');
    $settings->set('storage.s3_bucket', 'current-bucket');
    $settings->set('storage.s3_region', 'us-east-1');
    $settings->set('storage.s3_key', 'old-key');
    $settings->set('storage.s3_secret', 'old-secret');
    ConversationMessageAttachment::factory()->create(['storage_disk' => 'attachments-s3']);

    $this->actingAs(storageOperator())
        ->post(route('operator.settings.storage.update'), [
            'disk' => 'attachments-s3',
            'bucket' => 'current-bucket', // same location
            'region' => 'us-east-1',
            'acl' => 'private',
            's3_access_key' => 'new-key',
            's3_secret_key' => 'new-secret',
        ])
        ->assertSessionHasNoErrors();

    expect($settings->get('storage.s3_key'))->toBe('new-key');
});

test('saving storage settings records an instance-scoped audit without the secret', function (): void {
    $this->actingAs(storageOperator())->post(route('operator.settings.storage.update'), [
        'disk' => 'attachments-s3',
        'bucket' => 'audit-bucket',
        'region' => 'auto',
        'acl' => 'private',
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
