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

/*
 * New sites verify; existing ones do not. That asymmetry is the design, not an
 * oversight: an existing site may already have pages sending unsigned
 * identifiers, and turning verification on for them would stop identifying
 * every one of their customers, silently, on upgrade.
 */

test('a site created from the dashboard requires verification', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)
        ->post(route('dashboard.sites.store'), [
            'name' => 'New Site',
            'domain' => 'new.example.test',
        ])->assertRedirect();

    $site = Site::query()->where('name', 'New Site')->sole();

    expect($site->identity_verification)->toBe(VisitorIdentityVerification::REQUIRED)
        // No secret: one minted here and never shown would be dead, since the
        // plaintext exists for a single response and nobody is reading this
        // one. The site fails closed until an operator issues one.
        ->and($site->identity_secret)->toBeNull();
});

test('a new site ignores an unsigned identifier until a secret is issued', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)->post(route('dashboard.sites.store'), [
        'name' => 'Fresh Site',
        'domain' => 'fresh.example.test',
    ]);

    $site = Site::query()->where('name', 'Fresh Site')->sole();

    // The whole point of the default: out of the box, a caller cannot claim an
    // identifier on a site nobody has configured yet.
    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-fresh',
        'external_id' => 'customer-123',
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', false);
});

test('the settings page offers a first secret, not only a replacement', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);

    $this->actingAs($owner)->post(route('dashboard.sites.store'), [
        'name' => 'Needs Secret',
        'domain' => 'needs.example.test',
    ]);

    $site = Site::query()->where('name', 'Needs Secret')->sole();

    // Without this the form only appeared once a secret existed, which left a
    // brand-new site requiring verification with no way to get one.
    $this->actingAs($owner)->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee(__('site_settings.identity_verification.issue'))
        ->assertSee(__('site_settings.identity_verification.awaiting_secret'));

    $this->actingAs($owner)
        ->post(route('dashboard.sites.identity-secret.rotate', $site))
        ->assertRedirect();

    expect($site->fresh()->identity_secret)->toBeString();
});

test('an existing site keeps verification off', function (): void {
    // The upgrade case: a row that existed before this shipped takes the
    // column default, and nothing turns it on behind the operator's back.
    $site = Site::factory()->create();

    expect($site->fresh()->identity_verification)->toBe(VisitorIdentityVerification::OFF);

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-existing',
        'external_id' => 'customer-123',
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', true);
});

test('first run setup creates a verifying site', function (): void {
    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ])->assertRedirect();

    // A fresh install has no pages sending identifiers yet, so the secure
    // default costs nobody anything here either.
    expect(Site::query()->sole()->identity_verification)
        ->toBe(VisitorIdentityVerification::REQUIRED);
});

test('first run setup claiming an existing site leaves its mode alone', function (): void {
    $account = Account::factory()->create([
        'name' => 'Half Built Support',
        'slug' => 'half-built-support',
    ]);

    // A site that predates this release, which setup adopts rather than
    // creates. Flipping it on here would be the upgrade hazard the whole
    // existing/new asymmetry exists to avoid -- and the branch that does the
    // adopting is one line away from the branch that creates.
    $site = Site::factory()->for($account)->create([
        'name' => 'Half Built Docs',
        'domain' => 'half-built.example.test',
    ]);

    expect($site->identity_verification)->toBe(VisitorIdentityVerification::OFF);

    $this->post('/setup', [
        'account_name' => 'Acme Support',
        'agent_name' => 'Ada Agent',
        'agent_email' => 'ada@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'site_name' => 'Acme Docs',
        'site_domain' => 'docs.example.test',
    ])->assertRedirect();

    expect($site->fresh()->identity_verification)->toBe(VisitorIdentityVerification::OFF);
});
