<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function tokenAdmin(?Account $account = null): array
{
    $account ??= Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Docs']);

    return compact('account', 'admin', 'site');
}

test('an admin issues a token and is shown it exactly once', function (): void {
    $w = tokenAdmin();

    $response = $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), [
            'name' => 'Reporting sync',
            'abilities' => ['read'],
        ])
        ->assertRedirect(route('dashboard.account.api-tokens.index'));

    $plain = $response->getSession()->get('issued_api_token');

    expect($plain)->toStartWith('wfk_');

    // On the page it arrives at, once.
    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee($plain);

    // And never again -- the flash is gone and the model cannot reproduce it.
    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertDontSee($plain);
});

test('the token is stored as a hash and never as itself', function (): void {
    $w = tokenAdmin();

    $plain = $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token');

    $row = DB::table('api_tokens')->first();

    expect($row->token_hash)->toBe(hash('sha256', $plain))
        ->and(json_encode((array) $row))->not->toContain($plain);
});

test('a token issued with no abilities can authenticate and read nothing', function (): void {
    // Deny by default has to be a safe accident, not a broken one.
    $w = tokenAdmin();

    $plain = $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Empty'])
        ->getSession()->get('issued_api_token');

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(403);
});

test('a site restriction cannot name a site the issuing agent cannot see', function (): void {
    // Otherwise restricting a token becomes a way to reach a site the agent is
    // not assigned to.
    $account = Account::factory()->create();
    $w = tokenAdmin($account);
    $hidden = Site::factory()->for($account)->create(['name' => 'Hidden']);

    // Visibility is per-site: a site with NO assignments is visible to
    // everyone, so the hidden one has to be assigned to somebody else. My
    // first version of this test assigned only the visible site and asserted
    // the other was hidden -- which it never was.
    $somebodyElse = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $hidden->supportAgents()->syncWithoutDetaching($somebodyElse->id);

    $this->actingAs($w['admin'])->post(route('dashboard.account.api-tokens.store'), [
        'name' => 'Restricted',
        'abilities' => ['read'],
        'site_ids' => [$w['site']->id, $hidden->id],
    ]);

    $token = ApiToken::query()->firstOrFail();

    expect($token->sites->pluck('id')->all())->toBe([$w['site']->id]);
});

test('revoking keeps the row and stops the token', function (): void {
    $w = tokenAdmin();

    $plain = $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token');

    $token = ApiToken::query()->firstOrFail();

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertOk();

    $this->actingAs($w['admin'])
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->assertRedirect(route('dashboard.account.api-tokens.index'));

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);

    // The record of what existed and when it was last used survives.
    expect(ApiToken::query()->count())->toBe(1)
        ->and(ApiToken::query()->firstOrFail()->revoked_at)->not->toBeNull();
});

test('an agent cannot issue or revoke tokens', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $token = ApiToken::factory()->create(['account_id' => $account->id]);

    $this->actingAs($agent)
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Nope', 'abilities' => ['read']])
        ->assertForbidden();

    $this->actingAs($agent)
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->assertForbidden();

    expect(ApiToken::query()->count())->toBe(1)
        ->and($token->fresh()->revoked_at)->toBeNull();
});

test('an admin cannot revoke another account token, and is not told it exists', function (): void {
    $w = tokenAdmin();
    $theirs = ApiToken::factory()->create();

    $this->actingAs($w['admin'])
        ->delete(route('dashboard.account.api-tokens.destroy', $theirs))
        ->assertNotFound();

    expect($theirs->fresh()->revoked_at)->toBeNull();
});

test('the list shows enough to recognise a token without being enough to use it', function (): void {
    $w = tokenAdmin();
    $token = ApiToken::factory()->create([
        'account_id' => $w['account']->id,
        'name' => 'Reporting sync',
    ]);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('Reporting sync')
        ->assertSee($token->displayHint())
        ->assertSee('Never used')
        // The hash is not a display value either.
        ->assertDontSee($token->token_hash);
});

test('the account hub links to API tokens, so the page is reachable', function (): void {
    // A settings page nothing links to is a page nobody finds.
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.show'))
        ->assertOk()
        ->assertSee(route('dashboard.account.api-tokens.index'), false)
        ->assertSee('API tokens');
});
