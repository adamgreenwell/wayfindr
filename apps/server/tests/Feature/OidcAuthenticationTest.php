<?php

declare(strict_types=1);

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Http\Controllers\Auth\OidcSessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\User;
use App\Support\Auth\Oidc\OidcClient;
use App\Support\Auth\Oidc\OidcHttpClientFactory;
use App\Support\Auth\Oidc\OidcUser;
use App\Support\Auth\TwoFactorAuthentication;
use App\Support\Webhooks\OutboundWebhookDestination;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

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

/** @return array{account: Account, admin: User, connection: OidcConnection, client: FeatureOidcClient} */
function oidcWorld(): array
{
    $account = Account::factory()->create(['slug' => 'acme-support']);
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $connection = OidcConnection::factory()->for($account)->create();
    $client = new FeatureOidcClient;
    app()->instance(OidcClient::class, $client);

    return compact('account', 'admin', 'connection', 'client');
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

test('an account administrator configures one encrypted OIDC connection', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['8.8.8.8']),
    );

    $this->actingAs($admin)->put(route('dashboard.account.security.oidc.update'), [
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
        ->toBe(['enabled' => true, 'name' => 'Acme Identity']);

    $this->actingAs($admin)
        ->get(route('dashboard.account.security.show'))
        ->assertOk()
        ->assertSee(route('oidc.callback', ['connectionPublicId' => $connection->public_id]))
        ->assertDontSee('very-secret-value');
});

test('updating OIDC preserves a blank secret and rotates pending configuration', function (): void {
    $world = oidcWorld();
    $originalSecret = $world['connection']->client_secret;
    $originalVersion = $world['connection']->configuration_version;
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['1.1.1.1']),
    );

    $this->actingAs($world['admin'])->put(route('dashboard.account.security.oidc.update'), [
        'name' => 'Renamed SSO',
        'issuer_url' => 'https://login.example.com',
        'client_id' => 'new-client',
        'client_secret' => '',
    ])->assertRedirect(route('dashboard.account.security.show'));

    $connection = $world['connection']->fresh();
    expect($connection->client_secret)->toBe($originalSecret)
        ->and($connection->configuration_version)->not->toBe($originalVersion)
        ->and($connection->is_enabled)->toBeFalse();
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

    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    app()->instance(
        OutboundWebhookDestination::class,
        new OutboundWebhookDestination(fn (): array => ['127.0.0.1']),
    );

    $this->actingAs($admin)
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
    expect(session()->has(OidcSessionController::SESSION_KEY))->toBeFalse()
        ->and(session()->has('state'))->toBeFalse()
        ->and(session()->has('code_verifier'))->toBeFalse()
        ->and(session()->has('openidconnect_nonce'))->toBeFalse();

    $this->actingAs($world['admin'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('Single sign-on identity linked')
        ->assertSee('Signed in with single sign-on')
        ->assertSee('Single sign-on identity');
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
