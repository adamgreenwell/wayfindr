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
