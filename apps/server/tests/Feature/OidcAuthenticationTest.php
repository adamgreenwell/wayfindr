<?php

declare(strict_types=1);

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Http\Controllers\Auth\OidcSessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CustomRole;
use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\OidcRoleMapping;
use App\Models\Site;
use App\Models\User;
use App\Support\AgentRealtimeSessions;
use App\Support\Auth\Oidc\OidcClient;
use App\Support\Auth\Oidc\OidcHttpClientFactory;
use App\Support\Auth\Oidc\OidcUser;
use App\Support\Auth\TwoFactorAuthentication;
use App\Support\Webhooks\OutboundWebhookDestination;
use Firebase\JWT\JWT;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Psr\Http\Message\RequestInterface;

uses(RefreshDatabase::class);

final class FeatureOidcClient implements OidcClient
{
    public ?OidcUser $nextUser = null;

    public bool $redirected = false;

    public ?RuntimeException $failure = null;

    public function redirect(Request $request, OidcConnection $connection): RedirectResponse
    {
        if ($this->failure) {
            throw $this->failure;
        }

        $this->redirected = true;
        $request->session()->put([
            'state' => 'opaque-state',
            'code_verifier' => 'opaque-verifier',
            'openidconnect_nonce' => 'opaque-nonce',
        ]);

        return new RedirectResponse('https://id.example.com/authorize');
    }

    public function user(Request $request, OidcConnection $connection): OidcUser
    {
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->nextUser ?? throw new RuntimeException('No fake OIDC user was configured.');
    }
}

/** @return array{account: Account, owner: User, admin: User, connection: OidcConnection, client: FeatureOidcClient} */
function oidcWorld(): array
{
    $account = Account::factory()->create(['slug' => 'acme-support']);
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $connection = OidcConnection::factory()->for($account)->create();
    $client = new FeatureOidcClient;
    app()->instance(OidcClient::class, $client);

    return compact('account', 'owner', 'admin', 'connection', 'client');
}

function startOidcAttempt(OidcConnection $connection): void
{
    test()->post(route('oidc.redirect'), [
        'account_slug' => $connection->account()->value('slug'),
    ])->assertRedirect('https://id.example.com/authorize');
}

/** @return array{secret: string, code: string} */
function giveOidcAgentTwoFactor(User $user): array
{
    $secret = app(TwoFactorAuthentication::class)->generateSecret();
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => [Hash::make('RECOVERYCODE')],
        'two_factor_confirmed_at' => now(),
        'two_factor_last_used_timestep' => 0,
    ])->save();

    return [
        'secret' => $secret,
        'code' => app(Google2FA::class)->getCurrentOtp($secret),
    ];
}

function oidcBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

test('an account owner configures one encrypted OIDC connection', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );

    $this->actingAs($owner)->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Acme Identity',
        'issuer_url' => 'https://id.example.com/',
        'client_id' => 'wayfindr-client',
        'client_secret' => 'very-secret-value',
        'is_enabled' => '1',
    ])->assertRedirect(route('dashboard.account.security.show'));

    $connection = OidcConnection::query()->firstOrFail();
    expect($connection->account_id)->toBe($account->id)
        ->and($connection->issuer_url)->toBe('https://id.example.com/')
        ->and($connection->client_secret)->toBe('very-secret-value')
        ->and($connection->is_enabled)->toBeTrue()
        ->and($connection->toArray())->not->toHaveKey('client_secret')
        ->and(DB::table('oidc_connections')->value('client_secret'))->not->toBe('very-secret-value')
        ->and(AuditEvent::query()->where('action', 'account.oidc_connection_updated')->value('metadata'))
        ->toBe([
            'enabled' => true,
            'name' => 'Acme Identity',
            'identity_links_cleared' => 0,
            'role_mappings_cleared' => 0,
        ]);

    $this->actingAs($owner)
        ->get(route('dashboard.account.security.show'))
        ->assertOk()
        ->assertSee(route('oidc.callback', ['connectionPublicId' => $connection->public_id]))
        ->assertDontSee('very-secret-value');
});

test('updating OIDC preserves a blank secret and rotates pending configuration', function (): void {
    $world = oidcWorld();
    $originalSecret = $world['connection']->client_secret;
    $originalVersion = $world['connection']->configuration_version;
    $linked = User::factory()->for($world['account'])->create();
    OidcIdentity::factory()->for($world['connection'], 'connection')->for($linked)->create();
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create();
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['1.1.1.1']),
    );

    $this->actingAs($world['owner'])->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Renamed SSO',
        'issuer_url' => 'https://login.example.com',
        'client_id' => 'new-client',
        'client_secret' => '',
    ])->assertRedirect(route('dashboard.account.security.show'));

    $connection = $world['connection']->fresh();
    expect($connection->client_secret)->toBe($originalSecret)
        ->and($connection->configuration_version)->not->toBe($originalVersion)
        ->and($connection->is_enabled)->toBeFalse()
        ->and($connection->role_claim)->toBeNull()
        ->and($connection->jit_provisioning_enabled)->toBeFalse()
        ->and(OidcIdentity::query()->count())->toBe(0)
        ->and(OidcRoleMapping::query()->count())->toBe(0)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->metadata['identity_links_cleared'])->toBe(1)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->metadata['role_mappings_cleared'])->toBe(1);
});

test('renaming a provider or rotating only its secret preserves identity bindings', function (): void {
    $world = oidcWorld();
    $linked = User::factory()->for($world['account'])->create();
    $identity = OidcIdentity::factory()->for($world['connection'], 'connection')->for($linked)->create();
    $mapping = OidcRoleMapping::factory()->for($world['connection'], 'connection')->create();
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['1.1.1.1']),
    );

    $this->actingAs($world['admin'])->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'A clearer label',
        'issuer_url' => $world['connection']->issuer_url,
        'client_id' => $world['connection']->client_id,
        'client_secret' => 'rotated-secret',
        'is_enabled' => '1',
    ])->assertRedirect(route('dashboard.account.security.show'));

    expect($world['connection']->fresh()->client_secret)->toBe('rotated-secret')
        ->and(OidcIdentity::query()->whereKey($identity->id)->exists())->toBeTrue()
        ->and(OidcRoleMapping::query()->whereKey($mapping->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->metadata['identity_links_cleared'])->toBe(0)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->metadata['role_mappings_cleared'])->toBe(0);
});

test('only an owner can configure deny-by-default OIDC role mappings', function (): void {
    $world = oidcWorld();
    $owner = $world['owner'];
    $customRole = CustomRole::factory()->for($world['account'])->create(['name' => 'Support lead']);

    $this->actingAs($world['admin'])
        ->get(route('dashboard.account.security.show'))
        ->assertOk()
        ->assertDontSee('Just-in-time provisioning');
    $this->actingAs($world['admin'])
        ->post(route('dashboard.account.security.oidc.role-mappings.store'), [
            'claim_value' => 'support',
            'role_target' => 'built_in:agent',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->post(route('dashboard.account.security.oidc.role-mappings.store'), [
            'claim_value' => 'support-leads',
            'role_target' => 'custom:'.$customRole->id,
        ])
        ->assertRedirect(route('dashboard.account.security.show'))
        ->assertSessionHas('status', 'oidc.flash.mapping_created');

    $mapping = OidcRoleMapping::query()->sole();
    $versionAfterMapping = $world['connection']->fresh()->configuration_version;
    expect($mapping->claim_value)->toBe('support-leads')
        ->and($mapping->custom_role_id)->toBe($customRole->id)
        ->and($mapping->built_in_role)->toBeNull();

    $this->actingAs($owner)
        ->put(route('dashboard.account.security.oidc.provisioning.update'), [
            'role_claim' => 'groups',
            'jit_provisioning_enabled' => '1',
        ])
        ->assertRedirect(route('dashboard.account.security.show'))
        ->assertSessionHas('status', 'oidc.flash.provisioning_updated');

    $connection = $world['connection']->fresh();
    expect($connection->role_claim)->toBe('groups')
        ->and($connection->jit_provisioning_enabled)->toBeTrue()
        ->and($connection->configuration_version)->not->toBe($versionAfterMapping)
        ->and(AuditEvent::query()->where('action', 'account.oidc_role_mapping_created')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.oidc_provisioning_updated')->count())->toBe(1);

    $this->actingAs($owner)
        ->get(route('dashboard.account.security.show'))
        ->assertOk()
        ->assertSee('Just-in-time provisioning')
        ->assertSee('support-leads')
        ->assertSee('Support lead');

    $this->actingAs($owner)
        ->delete(route('dashboard.account.roles.destroy', $customRole))
        ->assertSessionHasErrors('role');
    expect(CustomRole::query()->whereKey($customRole->id)->exists())->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('dashboard.account.security.oidc.role-mappings.destroy', $mapping))
        ->assertRedirect(route('dashboard.account.security.show'))
        ->assertSessionHas('status', 'oidc.flash.mapping_deleted');
    expect(OidcRoleMapping::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'account.oidc_role_mapping_deleted')->count())->toBe(1);
});

test('OIDC provisioning rejects owner targets duplicates and enabling without mappings', function (): void {
    $world = oidcWorld();
    $owner = $world['owner'];

    $this->actingAs($owner)
        ->put(route('dashboard.account.security.oidc.provisioning.update'), [
            'role_claim' => 'groups',
            'jit_provisioning_enabled' => '1',
        ])
        ->assertSessionHasErrors('jit_provisioning_enabled');

    $this->actingAs($owner)
        ->post(route('dashboard.account.security.oidc.role-mappings.store'), [
            'claim_value' => 'owners',
            'role_target' => 'built_in:owner',
        ])
        ->assertSessionHasErrors('role_target');

    $this->actingAs($owner)
        ->post(route('dashboard.account.security.oidc.role-mappings.store'), [
            'claim_value' => 'support',
            'role_target' => 'built_in:agent',
        ])
        ->assertSessionDoesntHaveErrors();

    $this->actingAs($owner)
        ->post(route('dashboard.account.security.oidc.role-mappings.store'), [
            'claim_value' => 'support',
            'role_target' => 'built_in:admin',
        ])
        ->assertSessionHasErrors('claim_value');

    expect(OidcRoleMapping::query()->count())->toBe(1)
        ->and(OidcRoleMapping::query()->sole()->built_in_role)->toBe(AccountRole::Agent);
});

test('OIDC settings require account administration and a public HTTPS issuer', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Nope',
        'issuer_url' => 'https://id.example.com',
        'client_id' => 'client',
        'client_secret' => 'secret',
    ])->assertForbidden();

    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['127.0.0.1']),
    );

    $this->actingAs($owner)
        ->from(route('dashboard.account.security.show'))
        ->put(route('dashboard.account.security.oidc.update'), [
            'name' => 'Internal',
            'issuer_url' => 'https://identity.internal',
            'client_id' => 'client',
            'client_secret' => 'must-not-be-flashed',
            'is_enabled' => '1',
        ])
        ->assertRedirect(route('dashboard.account.security.show'))
        ->assertSessionHasErrors('issuer_url')
        ->assertSessionMissing('_old_input.client_secret');

    expect(OidcConnection::query()->count())->toBe(0);
});

test('only an owner can establish or replace OIDC authority', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );

    $this->actingAs($admin)->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Untrusted authority',
        'issuer_url' => 'https://attacker.example.com',
        'client_id' => 'attacker-client',
        'client_secret' => 'attacker-secret',
        'is_enabled' => '1',
    ])->assertForbidden();

    expect(OidcConnection::query()->count())->toBe(0);

    $connection = OidcConnection::factory()->for($account)->create([
        'issuer_url' => 'https://id.example.com',
        'client_id' => 'trusted-client',
    ]);

    $this->actingAs($admin)->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Replacement authority',
        'issuer_url' => 'https://replacement.example.com',
        'client_id' => 'replacement-client',
        'client_secret' => 'replacement-secret',
        'is_enabled' => '1',
    ])->assertForbidden();

    $connection->refresh();
    expect($connection->issuer_url)->toBe('https://id.example.com')
        ->and($connection->client_id)->toBe('trusted-client');

    $this->actingAs($admin)
        ->get(route('dashboard.account.security.show'))
        ->assertOk()
        ->assertSee('Only an account owner can replace the issuer or client ID.');
});

test('an unreachable OIDC provider can be disabled but not enabled', function (): void {
    $world = oidcWorld();
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => []),
    );

    $settings = [
        'name' => $world['connection']->name,
        'issuer_url' => $world['connection']->issuer_url,
        'client_id' => $world['connection']->client_id,
        'client_secret' => '',
    ];

    $this->actingAs($world['admin'])
        ->put(route('dashboard.account.security.oidc.update'), $settings)
        ->assertRedirect(route('dashboard.account.security.show'))
        ->assertSessionHasNoErrors();

    expect($world['connection']->fresh()->is_enabled)->toBeFalse();

    $this->actingAs($world['admin'])
        ->from(route('dashboard.account.security.show'))
        ->put(route('dashboard.account.security.oidc.update'), [...$settings, 'is_enabled' => '1'])
        ->assertRedirect(route('dashboard.account.security.show'))
        ->assertSessionHasErrors('issuer_url');

    expect($world['connection']->fresh()->is_enabled)->toBeFalse();
});

test('the OIDC HTTP client rejects private endpoints and pins every public request', function (): void {
    $blocked = new OidcHttpClientFactory(new OutboundWebhookDestination(fn (): array => ['10.0.0.5']));
    expect(fn () => $blocked->assertAllowed('https://id.example.com'))->toThrow(InvalidArgumentException::class);

    $mock = new MockHandler([new PsrResponse(200, [], '{}')]);
    $allowed = new OidcHttpClientFactory(new OutboundWebhookDestination(fn (): array => ['8.8.8.8']));
    $allowed->make($mock)->get('https://id.example.com/.well-known/openid-configuration');
    $options = $mock->getLastOptions();

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['proxy'])->toBe('')
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['id.example.com:443:8.8.8.8'])
        ->and($options['curl'][CURLOPT_PROTOCOLS])->toBe(CURLPROTO_HTTPS);
});

test('the production client completes a state nonce PKCE and signed ID token flow', function (): void {
    Cache::flush();
    $account = Account::factory()->create(['slug' => 'production-flow']);
    $connection = OidcConnection::factory()->for($account)->create([
        'issuer_url' => 'https://id.example.com',
        'client_id' => 'production-client',
        'client_secret' => 'production-secret',
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    OidcRoleMapping::factory()->for($connection, 'connection')->create([
        'claim_value' => 'wayfindr-agents',
        'built_in_role' => AccountRole::Agent,
    ]);
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($key)->not->toBeFalse();
    openssl_pkey_export($key, $privateKey);
    $details = openssl_pkey_get_details($key);
    expect($details)->toBeArray();
    $jwk = [
        'kty' => 'RSA',
        'use' => 'sig',
        'alg' => 'RS256',
        'kid' => 'test-key',
        'n' => oidcBase64Url($details['rsa']['n']),
        'e' => oidcBase64Url($details['rsa']['e']),
    ];
    $requests = [];
    $idToken = null;
    $handler = function (RequestInterface $request, array $options) use (&$requests, &$idToken, $jwk) {
        $requests[] = ['request' => $request, 'options' => $options];

        $body = match ($request->getUri()->getPath()) {
            '/.well-known/openid-configuration' => json_encode([
                'issuer' => 'https://id.example.com',
                'authorization_endpoint' => 'https://id.example.com/authorize',
                'token_endpoint' => 'https://id.example.com/token',
                'userinfo_endpoint' => 'https://id.example.com/userinfo',
                'jwks_uri' => 'https://id.example.com/jwks',
                'id_token_signing_alg_values_supported' => ['RS256'],
                'token_endpoint_auth_methods_supported' => ['client_secret_post'],
            ], JSON_THROW_ON_ERROR),
            '/token' => json_encode([
                'id_token' => $idToken,
                'token_type' => 'Bearer',
                'expires_in' => 300,
            ], JSON_THROW_ON_ERROR),
            '/jwks' => json_encode(['keys' => [$jwk]], JSON_THROW_ON_ERROR),
            default => throw new RuntimeException('Unexpected OIDC test request.'),
        };

        return Create::promiseFor(new PsrResponse(200, ['Content-Type' => 'application/json'], $body));
    };
    app()->instance(OidcHttpClientFactory::class, new OidcHttpClientFactory(
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
        $handler(...),
    ));

    $start = $this->post(route('oidc.redirect'), ['account_slug' => 'production-flow']);
    $start->assertRedirectContains('https://id.example.com/authorize?');
    $location = $start->headers->get('Location');
    expect($location)->toContain('code_challenge=')
        ->and($location)->toContain('nonce=')
        ->and($location)->toContain('state=');
    $state = session('state');
    $nonce = session('openidconnect_nonce');
    $idToken = JWT::encode([
        'iss' => 'https://id.example.com',
        'aud' => 'production-client',
        'sub' => 'production-subject',
        'email' => 'agent@example.com',
        'email_verified' => true,
        'name' => 'Production Agent',
        'groups' => ['wayfindr-agents'],
        'nonce' => $nonce,
        'iat' => now()->timestamp,
        'exp' => now()->addMinutes(5)->timestamp,
    ], $privateKey, 'RS256', 'test-key');

    $this->get(route('oidc.callback', [
        'connectionPublicId' => $connection->public_id,
        'state' => $state,
        'code' => 'authorization-code',
    ]))->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'agent@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Production Agent')
        ->and($user->account_role)->toBe(AccountRole::Agent)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->oidc_provisioned_at)->not->toBeNull();
    expect(OidcIdentity::query()->where([
        'oidc_connection_id' => $connection->id,
        'user_id' => $user->id,
        'subject' => 'production-subject',
    ])->whereNotNull('provisioned_at')->exists())->toBeTrue();

    $tokenRequest = collect($requests)->first(
        fn (array $entry): bool => $entry['request']->getUri()->getPath() === '/token'
    );
    parse_str((string) $tokenRequest['request']->getBody(), $tokenFields);
    expect($tokenFields['code'])->toBe('authorization-code')
        ->and($tokenFields['code_verifier'])->toBeString()->not->toBe('')
        ->and(collect($requests)->pluck('request')->map(
            fn (RequestInterface $request): string => $request->getUri()->getPath()
        )->all())->toBe(['/.well-known/openid-configuration', '/token', '/jwks']);
});

test('OIDC start keeps protocol state in the session and unknown accounts fail generically', function (): void {
    $world = oidcWorld();

    $this->post(route('oidc.redirect'), ['account_slug' => 'missing-account'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('account_slug');

    startOidcAttempt($world['connection']);

    $pending = session(OidcSessionController::SESSION_KEY);
    expect($world['client']->redirected)->toBeTrue()
        ->and(session('state'))->toBe('opaque-state')
        ->and(session('code_verifier'))->toBe('opaque-verifier')
        ->and(session('openidconnect_nonce'))->toBe('opaque-nonce')
        ->and($pending['connection_public_id'])->toBe($world['connection']->public_id)
        ->and($pending['configuration_version'])->toBe($world['connection']->configuration_version);
});

test('a verified provider identity links only to an existing same-account user', function (): void {
    $world = oidcWorld();
    $world['connection']->update(['name' => 'Original SSO']);
    $user = User::factory()->for($world['account'])->create(['email' => 'Agent@Example.com']);
    $other = User::factory()->for(Account::factory())->create(['email' => 'other@example.com']);
    $world['client']->nextUser = new OidcUser('subject-123', 'agent@example.com', true);
    $userCount = User::query()->count();

    startOidcAttempt($world['connection']);
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe($userCount)
        ->and(OidcIdentity::query()->where([
            'oidc_connection_id' => $world['connection']->id,
            'user_id' => $user->id,
            'subject' => 'subject-123',
        ])->exists())->toBeTrue()
        ->and(OidcIdentity::query()->where('user_id', $other->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'agent.oidc_identity_linked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_signed_in')->count())->toBe(1);
    expect(AuditEvent::query()
        ->whereIn('action', ['agent.oidc_identity_linked', 'agent.oidc_signed_in'])
        ->orderBy('id')
        ->pluck('metadata')
        ->all())->toBe([
            ['oidc_provider_name' => 'Original SSO'],
            ['oidc_provider_name' => 'Original SSO'],
        ]);
    expect(session()->has(OidcSessionController::SESSION_KEY))->toBeFalse()
        ->and(session()->has('state'))->toBeFalse()
        ->and(session()->has('code_verifier'))->toBeFalse()
        ->and(session()->has('openidconnect_nonce'))->toBeFalse();

    $this->actingAs($world['admin'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('Single sign-on identity linked')
        ->assertSee('Signed in with single sign-on')
        ->assertSee('Single sign-on identity')
        ->assertSee('Original SSO');

    $this->actingAs($world['owner'])->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Replacement SSO',
        'issuer_url' => 'https://replacement.example.com',
        'client_id' => $world['connection']->client_id,
        'client_secret' => '',
    ])->assertRedirect(route('dashboard.account.security.show'));

    expect(OidcIdentity::query()->count())->toBe(0);

    $this->actingAs($world['admin'])
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Original SSO']))
        ->assertOk()
        ->assertSee('2 shown')
        ->assertSee('Original SSO')
        ->assertDontSee('Replacement SSO');

    $csv = $this->actingAs($world['admin'])
        ->get(route('dashboard.account.audit.export', ['audit_search' => 'Original SSO']))
        ->streamedContent();

    expect(substr_count($csv, 'Single sign-on identity (Original SSO)'))->toBe(2);
});

test('JIT provisioning creates a verified user in exactly one mapped custom role', function (): void {
    $world = oidcWorld();
    $role = CustomRole::factory()->for($world['account'])->create([
        'name' => 'Conversation specialist',
        'name_key' => 'conversation specialist',
    ]);
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'conversation-team',
        'built_in_role' => null,
        'custom_role_id' => $role->id,
    ]);
    $world['client']->nextUser = new OidcUser(
        'jit-subject',
        'new.agent@example.com',
        true,
        'New Agent',
        ['conversation-team'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    $user = User::query()->where('email', 'new.agent@example.com')->sole();
    $identity = OidcIdentity::query()->where('user_id', $user->id)->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->account_id)->toBe($world['account']->id)
        ->and($user->name)->toBe('New Agent')
        ->and($user->account_role)->toBe(AccountRole::Agent)
        ->and($user->custom_role_id)->toBe($role->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->oidc_provisioned_at)->not->toBeNull()
        ->and($identity->provisioned_at)->not->toBeNull()
        ->and(DB::table('site_user')->where('user_id', $user->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_provisioned')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_identity_linked')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_signed_in')->count())->toBe(1);
});

test('a locally created OIDC user keeps their local role when JIT mappings are enabled', function (): void {
    $world = oidcWorld();
    $local = User::factory()->for($world['account'])->create([
        'email' => 'local-role@example.com',
        'account_role' => AccountRole::Agent,
    ]);
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'administrators',
        'built_in_role' => AccountRole::Admin,
    ]);
    $world['client']->nextUser = new OidcUser(
        'local-subject',
        'local-role@example.com',
        true,
        'Local Agent',
        ['administrators'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    expect($local->fresh()->account_role)->toBe(AccountRole::Agent)
        ->and($local->fresh()->oidc_provisioned_at)->toBeNull()
        ->and(OidcIdentity::query()->where('user_id', $local->id)->sole()->provisioned_at)->toBeNull()
        ->and(AuditEvent::query()->where('action', 'agent.oidc_provisioned')->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_role_mapped')->count())->toBe(0);
});

test('a JIT-managed identity follows a later unambiguous role mapping even after new provisioning is disabled', function (): void {
    $world = oidcWorld();
    $firstRole = CustomRole::factory()->for($world['account'])->create([
        'name' => 'First role',
        'name_key' => 'first role',
    ]);
    $secondRole = CustomRole::factory()->for($world['account'])->create([
        'name' => 'Second role',
        'name_key' => 'second role',
    ]);
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'first-team',
        'built_in_role' => null,
        'custom_role_id' => $firstRole->id,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'second-team',
        'built_in_role' => null,
        'custom_role_id' => $secondRole->id,
    ]);
    $realtime = Mockery::mock(AgentRealtimeSessions::class);
    $realtime->shouldReceive('requestMany')->once()->with(Mockery::type('array'));
    $realtime->shouldReceive('disconnectMany')->once()->with(Mockery::type('array'));
    app()->instance(AgentRealtimeSessions::class, $realtime);
    $world['client']->nextUser = new OidcUser(
        'managed-subject',
        'managed@example.com',
        true,
        'Managed Agent',
        ['first-team'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));
    $user = User::query()->where('email', 'managed@example.com')->sole();
    expect($user->custom_role_id)->toBe($firstRole->id);

    $this->post(route('logout'));
    $world['connection']->update(['jit_provisioning_enabled' => false]);
    $world['client']->nextUser = new OidcUser(
        'managed-subject',
        'changed@example.com',
        true,
        'Managed Agent',
        ['second-team'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    $user = $user->fresh();
    expect($user->custom_role_id)->toBe($secondRole->id)
        ->and($user->email)->toBe('managed@example.com')
        ->and(AuditEvent::query()->where('action', 'agent.oidc_role_mapped')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_role_mapped')->value('metadata'))
        ->toMatchArray([
            'old_role' => 'custom:'.$firstRole->id,
            'role' => 'custom:'.$secondRole->id,
            'role_name' => 'Second role',
        ]);
});

test('JIT provenance survives a cleared provider-subject binding', function (): void {
    $world = oidcWorld();
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'support',
        'built_in_role' => AccountRole::Agent,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'administrators',
        'built_in_role' => AccountRole::Admin,
    ]);
    $world['client']->nextUser = new OidcUser(
        'first-subject',
        'persistent-jit@example.com',
        true,
        'Persistent Agent',
        ['support'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));
    $user = User::query()->where('email', 'persistent-jit@example.com')->sole();
    $this->post(route('logout'));
    OidcIdentity::query()->where('user_id', $user->id)->delete();

    $world['client']->nextUser = new OidcUser(
        'replacement-subject',
        'persistent-jit@example.com',
        true,
        'Persistent Agent',
        ['administrators'],
    );
    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    expect(User::query()->where('email', 'persistent-jit@example.com')->count())->toBe(1)
        ->and($user->fresh()->account_role)->toBe(AccountRole::Admin)
        ->and($user->fresh()->oidc_provisioned_at)->not->toBeNull()
        ->and(OidcIdentity::query()->where('user_id', $user->id)->sole()->provisioned_at)->not->toBeNull()
        ->and(OidcIdentity::query()->where('user_id', $user->id)->sole()->subject)->toBe('replacement-subject');
});

test('OIDC role sync cannot strand an explicitly assigned site without a manager', function (): void {
    $world = oidcWorld();
    $managerRole = CustomRole::factory()->for($world['account'])->create([
        'name' => 'Site manager',
        'name_key' => 'site manager',
        'permissions' => [AccountPermission::ManageSiteAccess->value],
    ]);
    $user = User::factory()->for($world['account'])->create([
        'email' => 'site.manager@example.com',
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $managerRole->id,
    ]);
    $identity = OidcIdentity::factory()
        ->for($world['connection'], 'connection')
        ->for($user)
        ->create(['subject' => 'site-manager-subject', 'provisioned_at' => now()]);
    $site = Site::factory()->for($world['account'])->create();
    $site->supportAgents()->attach($user->id);
    $world['connection']->update(['role_claim' => 'groups']);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'ordinary-agents',
        'built_in_role' => AccountRole::Agent,
    ]);
    $world['client']->nextUser = new OidcUser(
        $identity->subject,
        $user->email,
        true,
        $user->name,
        ['ordinary-agents'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('account_slug');

    $this->assertGuest();
    expect($user->fresh()->custom_role_id)->toBe($managerRole->id)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_role_mapped')->count())->toBe(0);
});

test('multiple exact claim values remain unambiguous when they map to one role', function (): void {
    $world = oidcWorld();
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);

    foreach (['support', 'everyone'] as $claimValue) {
        OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
            'claim_value' => $claimValue,
            'built_in_role' => AccountRole::Agent,
        ]);
    }

    $world['client']->nextUser = new OidcUser(
        'multi-match-subject',
        'multi.match@example.com',
        true,
        'Multi Match',
        ['support', 'everyone'],
    );

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    expect(User::query()->where('email', 'multi.match@example.com')->sole()->account_role)
        ->toBe(AccountRole::Agent);
});

test('missing ambiguous and owner claim mappings fail closed without creating a user', function (
    array $claimValues,
    array $mappings,
): void {
    $world = oidcWorld();
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);

    foreach ($mappings as [$claimValue, $role]) {
        OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
            'claim_value' => $claimValue,
            'built_in_role' => $role,
        ]);
    }

    $world['client']->nextUser = new OidcUser(
        'rejected-subject',
        'rejected@example.com',
        true,
        'Rejected Agent',
        $claimValues,
    );
    $before = User::query()->count();

    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('account_slug');

    $this->assertGuest();
    expect(User::query()->count())->toBe($before)
        ->and(OidcIdentity::query()->count())->toBe(0);
})->with([
    'missing mapping' => [['unmapped'], [['support', AccountRole::Agent]]],
    'case-sensitive mismatch' => [['Support'], [['support', AccountRole::Agent]]],
    'conflicting mappings' => [
        ['support', 'administrators'],
        [['support', AccountRole::Agent], ['administrators', AccountRole::Admin]],
    ],
    'owner target persisted outside the UI' => [['owners'], [['owners', AccountRole::Owner]]],
]);

test('a JIT-managed identity with no current mapping is refused without losing local recovery', function (): void {
    $world = oidcWorld();
    $world['connection']->update([
        'role_claim' => 'groups',
        'jit_provisioning_enabled' => true,
    ]);
    OidcRoleMapping::factory()->for($world['connection'], 'connection')->create([
        'claim_value' => 'support',
        'built_in_role' => AccountRole::Agent,
    ]);
    $world['client']->nextUser = new OidcUser(
        'recoverable-subject',
        'recoverable@example.com',
        true,
        'Recoverable Agent',
        ['support'],
    );
    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));
    $user = User::query()->where('email', 'recoverable@example.com')->sole();
    $user->update(['password' => Hash::make('local-recovery-password')]);
    $this->post(route('logout'));

    $world['client']->nextUser = new OidcUser(
        'recoverable-subject',
        'recoverable@example.com',
        true,
        'Recoverable Agent',
        ['removed-group'],
    );
    startOidcAttempt($world['connection']->fresh());
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('account_slug');
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => 'recoverable@example.com',
        'password' => 'local-recovery-password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->deactivated_at)->toBeNull()
        ->and($user->fresh()->account_role)->toBe(AccountRole::Agent)
        ->and(AuditEvent::query()->where('action', 'agent.oidc_signed_in')->count())->toBe(1);
});

test('a linked subject remains authoritative when its provider email changes', function (): void {
    $world = oidcWorld();
    $linked = User::factory()->for($world['account'])->create(['email' => 'linked@example.com']);
    $bystander = User::factory()->for($world['account'])->create(['email' => 'changed@example.com']);
    OidcIdentity::factory()->for($world['connection'], 'connection')->for($linked)->create([
        'subject' => 'stable-subject',
    ]);
    $world['client']->nextUser = new OidcUser('stable-subject', 'changed@example.com', true);

    startOidcAttempt($world['connection']);
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($linked);
    expect(OidcIdentity::query()->where('user_id', $bystander->id)->exists())->toBeFalse();
});

test('unverified unknown cross-account and operator identities are refused without provisioning', function (
    OidcUser $providerUser,
    Closure $localUser,
): void {
    $world = oidcWorld();
    $localUser($world);
    $world['client']->nextUser = $providerUser;
    $before = User::query()->count();

    startOidcAttempt($world['connection']);
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('account_slug');

    $this->assertGuest();
    expect(User::query()->count())->toBe($before)
        ->and(OidcIdentity::query()->count())->toBe(0);
})->with([
    'unverified provider email' => [
        new OidcUser('unverified-sub', 'agent@example.com', false),
        fn (array $world) => User::factory()->for($world['account'])->create(['email' => 'agent@example.com']),
    ],
    'unknown email' => [
        new OidcUser('unknown-sub', 'missing@example.com', true),
        fn () => null,
    ],
    'user belongs to another account' => [
        new OidcUser('cross-account-sub', 'other@example.com', true),
        fn () => User::factory()->for(Account::factory())->create(['email' => 'other@example.com']),
    ],
    'platform operator' => [
        new OidcUser('operator-sub', 'operator@example.com', true),
        fn (array $world) => User::factory()->for($world['account'])->create([
            'email' => 'operator@example.com',
            'platform_role' => PlatformRole::Operator,
        ]),
    ],
    'deactivated user' => [
        new OidcUser('inactive-sub', 'inactive@example.com', true),
        fn (array $world) => User::factory()->for($world['account'])->create([
            'email' => 'inactive@example.com',
            'deactivated_at' => now(),
        ]),
    ],
    'locally unverified email' => [
        new OidcUser('local-unverified-sub', 'unverified@example.com', true),
        fn (array $world) => User::factory()->unverified()->for($world['account'])->create([
            'email' => 'unverified@example.com',
        ]),
    ],
    'ambiguous case-insensitive local email' => [
        new OidcUser('ambiguous-sub', 'duplicate@example.com', true),
        function (array $world): void {
            User::factory()->for($world['account'])->create(['email' => 'Duplicate@example.com']);
            User::factory()->for($world['account'])->create(['email' => 'duplicate@example.com']);
        },
    ],
]);

test('changing or disabling a connection invalidates an unfinished callback', function (bool $disable): void {
    $world = oidcWorld();
    User::factory()->for($world['account'])->create(['email' => 'agent@example.com']);
    $world['client']->nextUser = new OidcUser('subject', 'agent@example.com', true);
    startOidcAttempt($world['connection']);

    $world['connection']->forceFill($disable
        ? ['is_enabled' => false]
        : ['configuration_version' => (string) Str::uuid()]
    )->save();

    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('login'));
    $this->assertGuest();
    expect(OidcIdentity::query()->count())->toBe(0);
})->with([true, false]);

test('OIDC enters the normal TOTP challenge and connection changes invalidate it', function (): void {
    $world = oidcWorld();
    $user = User::factory()->for($world['account'])->create(['email' => 'agent@example.com']);
    $factor = giveOidcAgentTwoFactor($user);
    $world['client']->nextUser = new OidcUser('subject-with-totp', 'agent@example.com', true);

    startOidcAttempt($world['connection']);
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('two-factor.challenge'))
        ->assertSessionHas(TwoFactorChallengeController::SESSION_KEY, function (array $pending) use ($world): bool {
            return $pending['oidc_connection_id'] === $world['connection']->id
                && $pending['oidc_configuration_version'] === $world['connection']->configuration_version;
        });
    $this->assertGuest();
    expect(AuditEvent::query()->where('action', 'agent.oidc_signed_in')->count())->toBe(0)
        ->and(OidcIdentity::query()->firstOrFail()->last_signed_in_at)->toBeNull();

    $this->post(route('two-factor.challenge.store'), ['one_time_code' => $factor['code']])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    expect(AuditEvent::query()->where('action', 'agent.oidc_signed_in')->count())->toBe(1)
        ->and(OidcIdentity::query()->firstOrFail()->last_signed_in_at)->not->toBeNull();

    $this->post(route('logout'));
    $world['client']->nextUser = new OidcUser('subject-with-totp', 'agent@example.com', true);
    startOidcAttempt($world['connection']);
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('two-factor.challenge'));
    $world['connection']->update(['is_enabled' => false]);

    $this->post(route('two-factor.challenge.store'), ['one_time_code' => $factor['code']])
        ->assertRedirect(route('login'))
        ->assertSessionMissing(TwoFactorChallengeController::SESSION_KEY);
    $this->assertGuest();
});

test('an account TOTP requirement still restricts an unenrolled federated user', function (): void {
    $world = oidcWorld();
    $world['account']->update(['requires_two_factor' => true]);
    $user = User::factory()->for($world['account'])->create(['email' => 'agent@example.com']);
    $world['client']->nextUser = new OidcUser('subject-policy', 'agent@example.com', true);

    startOidcAttempt($world['connection']);
    $this->get(route('oidc.callback', ['connectionPublicId' => $world['connection']->public_id]))
        ->assertRedirect(route('dashboard.profile.show'));

    $this->assertAuthenticatedAs($user);
    $this->get(route('dashboard'))->assertRedirect(route('dashboard.profile.show'));
});

test('local password login remains available when OIDC is enabled', function (): void {
    $world = oidcWorld();
    $user = User::factory()->for($world['account'])->create([
        'email' => 'local@example.com',
        'password' => Hash::make('local-password'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'local@example.com',
        'password' => 'local-password',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});
