<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\Visitors\VisitorIdentityVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/**
 * Turning identity verification on has to be reachable, and issuing a new
 * secret has to be hard to do by accident: from the moment it commits, every
 * page still signing with the old one identifies nobody.
 */
function identitySettingsOwner(?Site &$site = null): User
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();

    return User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
}

test('turning verification on issues a secret and shows it once', function (): void {
    $owner = identitySettingsOwner($site);

    $response = $this->actingAs($owner)
        ->from(route('dashboard.sites.show', $site))
        ->put(route('dashboard.sites.identity-verification.update', $site), [
            'identity_verification' => VisitorIdentityVerification::REQUIRED,
        ])->assertRedirect(route('dashboard.sites.show', $site));

    $site->refresh();

    expect($site->identity_verification)->toBe(VisitorIdentityVerification::REQUIRED)
        // A site asking for verification with no secret refuses every
        // identifier, so turning it on must not be able to leave it that way.
        ->and($site->identity_secret)->toBeString()
        ->and($site->identity_secret)->toStartWith(VisitorIdentityVerification::SECRET_PREFIX);

    $secret = $site->identity_secret;

    // Shown once, on the page this redirected to.
    $this->actingAs($owner)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee($secret);

    // And never again.
    $this->actingAs($owner)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertDontSee($secret);
});

test('the secret is encrypted in the session rather than flashed in clear', function (): void {
    $owner = identitySettingsOwner($site);

    $this->actingAs($owner)
        ->put(route('dashboard.sites.identity-verification.update', $site), [
            'identity_verification' => VisitorIdentityVerification::REQUIRED,
        ]);

    $flashed = session('issued_identity_secret');
    $secret = $site->fresh()->identity_secret;

    // The default session driver is `database`, so a plaintext flash writes a
    // live signing secret into the `sessions` table, recoverable from a dump.
    expect($flashed)->toBeString()
        ->and($flashed)->not->toContain($secret)
        ->and(Crypt::decryptString($flashed))->toBe($secret);
});

test('turning verification on twice does not replace the secret', function (): void {
    $owner = identitySettingsOwner($site);

    $this->actingAs($owner)->put(route('dashboard.sites.identity-verification.update', $site), [
        'identity_verification' => VisitorIdentityVerification::REQUIRED,
    ]);

    $first = $site->fresh()->identity_secret;

    $this->actingAs($owner)->put(route('dashboard.sites.identity-verification.update', $site), [
        'identity_verification' => VisitorIdentityVerification::OFF,
    ]);
    $this->actingAs($owner)->put(route('dashboard.sites.identity-verification.update', $site), [
        'identity_verification' => VisitorIdentityVerification::REQUIRED,
    ]);

    // Rotation is its own action. A dropdown that silently reissued would break
    // every page mid-flight as a side effect of toggling a setting.
    expect($site->fresh()->identity_secret)->toBe($first);
});

test('rotating issues a different secret and stops the old one verifying', function (): void {
    $owner = identitySettingsOwner($site);

    $this->actingAs($owner)->put(route('dashboard.sites.identity-verification.update', $site), [
        'identity_verification' => VisitorIdentityVerification::REQUIRED,
    ]);

    $site->refresh();
    $old = $site->identity_secret;
    $oldHash = hash_hmac('sha256', 'customer-1', $old);

    $this->actingAs($owner)
        ->post(route('dashboard.sites.identity-secret.rotate', $site))
        ->assertRedirect(route('dashboard.sites.show', $site));

    $site->refresh();

    expect($site->identity_secret)->not->toBe($old);

    // Asserted through the verifier rather than by comparing strings: what
    // matters is that a signature made with the old secret no longer passes.
    $verification = app(VisitorIdentityVerification::class);

    expect($verification->verifies($site, 'customer-1', $oldHash))->toBeFalse()
        ->and($verification->verifies($site, 'customer-1', hash_hmac('sha256', 'customer-1', $site->identity_secret)))->toBeTrue();
});

test('an agent who cannot update the site cannot change verification', function (): void {
    $owner = identitySettingsOwner($site);
    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)
        ->put(route('dashboard.sites.identity-verification.update', $site), [
            'identity_verification' => VisitorIdentityVerification::REQUIRED,
        ])->assertForbidden();

    $this->actingAs($agent)
        ->post(route('dashboard.sites.identity-secret.rotate', $site))
        ->assertForbidden();

    expect($site->fresh()->identity_verification)->toBe(VisitorIdentityVerification::OFF);
});

test('another account cannot see or change this site verification', function (): void {
    $owner = identitySettingsOwner($site);
    $stranger = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Owner]);

    // 404 rather than 403: whether this site id exists is not a stranger's to
    // learn, which is the rule the rest of the site routes already follow.
    $this->actingAs($stranger)
        ->put(route('dashboard.sites.identity-verification.update', $site), [
            'identity_verification' => VisitorIdentityVerification::REQUIRED,
        ])->assertNotFound();

    $this->actingAs($stranger)
        ->post(route('dashboard.sites.identity-secret.rotate', $site))
        ->assertNotFound();
});

test('an unknown mode is refused', function (): void {
    $owner = identitySettingsOwner($site);

    $this->actingAs($owner)
        ->from(route('dashboard.sites.show', $site))
        ->put(route('dashboard.sites.identity-verification.update', $site), [
            'identity_verification' => 'report-only',
        ])->assertSessionHasErrors('identity_verification');

    expect($site->fresh()->identity_verification)->toBe(VisitorIdentityVerification::OFF);
});

test('the page never shows the whole secret outside its one reveal', function (): void {
    $owner = identitySettingsOwner($site);

    $generated = VisitorIdentityVerification::generateSecret();
    $site->forceFill([
        'identity_secret' => $generated['plain'],
        'identity_secret_last_four' => $generated['last_four'],
        'identity_verification' => VisitorIdentityVerification::REQUIRED,
    ])->save();

    $this->actingAs($owner)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        // The hint identifies the secret without being enough to sign with,
        // which is the same trade the API token list makes.
        ->assertSee(VisitorIdentityVerification::SECRET_PREFIX.'…'.$generated['last_four'])
        ->assertDontSee($generated['plain']);
});
