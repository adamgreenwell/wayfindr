<?php

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The first credential Wayfindr issues with nobody at the other end (ADR 0018),
 * reaching the most sensitive data in the product. Every test here is about
 * what it CANNOT do.
 */
function issueToken(array $attributes = []): array
{
    $generated = ApiToken::generate();
    $account = $attributes['account'] ?? Account::factory()->create();

    $token = ApiToken::query()->create(array_merge([
        'account_id' => $account->id,
        'name' => 'Integration',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ], collect($attributes)->except('account')->all()));

    return ['plain' => $generated['plain'], 'token' => $token, 'account' => $account];
}

function apiGet($test, string $plain, string $uri)
{
    return $test->getJson($uri, ['Authorization' => 'Bearer '.$plain]);
}

test('a request with no token is refused', function (): void {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

test('a token that was never issued is refused', function (): void {
    apiGet($this, 'wfk_'.str_repeat('a', 40), '/api/v1/me')->assertStatus(401);
});

test('a revoked token stops working immediately', function (): void {
    $issued = issueToken(['revoked_at' => now()]);

    apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(401);
});

test('an expired token stops working on its own', function (): void {
    $issued = issueToken(['expires_at' => now()->subMinute()]);

    apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(401);
});

test('a revoked token is refused in the same words as an unknown one', function (): void {
    // Telling a caller their token USED to work tells them something about the
    // account. A caller who mistyped learns nothing either way.
    $issued = issueToken(['revoked_at' => now()]);

    $revoked = apiGet($this, $issued['plain'], '/api/v1/me');
    $unknown = apiGet($this, 'wfk_'.str_repeat('b', 40), '/api/v1/me');

    expect($revoked->json('message'))->toBe($unknown->json('message'));
});

test('a token without the read ability cannot read', function (): void {
    // Deny by default: an ability that was not granted is not implied by any
    // other.
    $issued = issueToken(['abilities' => []]);

    apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(403);
});

test('the plaintext is never stored, and never returned again', function (): void {
    $issued = issueToken();

    expect($issued['token']->token_hash)->toBe(hash('sha256', $issued['plain']))
        ->and($issued['token']->getAttributes())->not->toHaveKey('token')
        // The stored row must not contain the secret in any column.
        ->and(json_encode($issued['token']->getAttributes()))->not->toContain($issued['plain']);
});

test('using a token records that it was used', function (): void {
    // The figure that tells an operator a live token from a forgotten one.
    $issued = issueToken();

    expect($issued['token']->last_used_at)->toBeNull();

    apiGet($this, $issued['plain'], '/api/v1/me')->assertOk();

    expect($issued['token']->fresh()->last_used_at)->not->toBeNull();
});

test('a refused request still records that the credential was used', function (): void {
    // A token being used unsuccessfully is exactly as interesting as one being
    // used well -- more so, if somebody is probing with it.
    $issued = issueToken(['abilities' => []]);

    apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(403);

    expect($issued['token']->fresh()->last_used_at)->not->toBeNull();
});

test('me reports the token reach, not the account reach', function (): void {
    $account = Account::factory()->create();
    $reachable = Site::factory()->for($account)->create();
    $hidden = Site::factory()->for($account)->create();

    $issued = issueToken(['account' => $account]);
    $issued['token']->forceFill(['restricts_sites' => true])->save();
    $issued['token']->sites()->attach($reachable->id);

    $body = apiGet($this, $issued['plain'], '/api/v1/me')->assertOk()->json('data');

    expect($body['site_ids'])->toBe([$reachable->id])
        ->and($body['site_ids'])->not->toContain($hidden->id)
        ->and($body['abilities'])->toBe(['read']);
});

test('an unrestricted token reaches its account sites, not nothing', function (): void {
    // The natural way to create a token is without picking sites. Reading "no
    // restriction" as "no access" would make that produce a credential that
    // returns empty lists forever.
    $account = Account::factory()->create();
    $sites = Site::factory()->count(2)->for($account)->create();

    $issued = issueToken(['account' => $account]);

    expect(apiGet($this, $issued['plain'], '/api/v1/me')->json('data.site_ids'))
        ->toBe($sites->pluck('id')->all());
});

test('probing with bad tokens is rate limited, not unbounded', function (): void {
    // The gap this closes: a request with a bad token was refused by auth and
    // never reached any throttle, so probing was unbounded -- every attempt
    // costing an indexed lookup with nothing to slow it down or make it
    // visible.
    config()->set('wayfindr.api_failed_auth_per_minute', 3);

    foreach (range(1, 3) as $i) {
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer wfk_'.str_repeat('c', 40)])
            ->assertStatus(401);
    }

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer wfk_'.str_repeat('c', 40)])
        ->assertStatus(429);
});

test('a valid token is limited by its own budget, not by the IP one', function (): void {
    // Two tokens on one host must not throttle each other, which is the whole
    // reason the per-token limit is keyed on the token. This failed until
    // AuthenticateApiToken was marked `AuthenticatesRequests`: without that it
    // is unprioritised, the route throttle sorts ahead of it, and the limiter
    // keys on a token that has not been resolved yet.
    config()->set('wayfindr.api_failed_auth_per_minute', 100);
    config()->set('wayfindr.api_rate_limit', 2);

    $first = issueToken();
    $second = issueToken();

    apiGet($this, $first['plain'], '/api/v1/me')->assertOk();
    apiGet($this, $first['plain'], '/api/v1/me')->assertOk();
    apiGet($this, $first['plain'], '/api/v1/me')->assertStatus(429);

    // The second token has spent nothing, despite sharing the IP.
    apiGet($this, $second['plain'], '/api/v1/me')->assertOk();
});

test('a genuine token with the wrong ability does not spend the failure budget', function (): void {
    // A misconfigured integration is not somebody hunting for a token that
    // works, and locking it out of the address it shares would punish the wrong
    // problem.
    config()->set('wayfindr.api_failed_auth_per_minute', 2);

    $issued = issueToken(['abilities' => []]);

    foreach (range(1, 5) as $i) {
        apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(403);
    }

    // The budget is untouched, so an unknown token still gets the ordinary
    // refusal rather than a 429.
    apiGet($this, 'wfk_'.str_repeat('d', 40), '/api/v1/me')->assertStatus(401);
});

test('a token with no abilities is rate limited, not unlimited', function (): void {
    // The hole between two deliberate decisions: this middleware is prioritised
    // ahead of the route throttle, so a 403 never reaches the per-token
    // limiter -- and a 403 does not spend the failed-auth budget either,
    // because the credential is genuine. A token with no abilities is a
    // supported state, so that left an authenticated path with no bound at all.
    config()->set('wayfindr.api_rate_limit', 3);
    config()->set('wayfindr.api_failed_auth_per_minute', 100);

    $issued = issueToken(['abilities' => []]);

    foreach (range(1, 3) as $i) {
        apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(403);
    }

    apiGet($this, $issued['plain'], '/api/v1/me')->assertStatus(429);
});

test('an ability refusal does not spend another token budget', function (): void {
    // Bounded per token, so one misconfigured integration cannot throttle
    // another that happens to share an account or an address.
    //
    // BOTH tokens have to lack the ability. My first version paired a broken
    // token with a working one, which never reaches this branch at all -- so it
    // passed against a single shared bucket and the mutation survived.
    config()->set('wayfindr.api_rate_limit', 2);
    config()->set('wayfindr.api_failed_auth_per_minute', 100);

    $exhausted = issueToken(['abilities' => []]);
    $other = issueToken(['abilities' => []]);

    foreach (range(1, 4) as $i) {
        apiGet($this, $exhausted['plain'], '/api/v1/me');
    }

    apiGet($this, $exhausted['plain'], '/api/v1/me')->assertStatus(429);

    // The second is still merely misconfigured, not throttled.
    apiGet($this, $other['plain'], '/api/v1/me')->assertStatus(403);
});
