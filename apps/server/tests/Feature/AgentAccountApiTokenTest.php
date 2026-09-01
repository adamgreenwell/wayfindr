<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * The plaintext token, decrypted the way the page does.
 *
 * The session carries ciphertext on purpose: the default driver is `database`,
 * so flashing the plaintext would write a usable bearer credential into the
 * `sessions` table, recoverable from a database export.
 */
function issuedPlaintext(?string $flashed): string
{
    return Crypt::decryptString((string) $flashed);
}

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

    $plain = issuedPlaintext($response->getSession()->get('issued_api_token'));

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

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token'));

    $row = DB::table('api_tokens')->first();

    expect($row->token_hash)->toBe(hash('sha256', $plain))
        ->and(json_encode((array) $row))->not->toContain($plain);
});

test('a token issued with no abilities can authenticate and read nothing', function (): void {
    // Deny by default has to be a safe accident, not a broken one.
    $w = tokenAdmin();

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Empty'])
        ->getSession()->get('issued_api_token'));

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

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token'));

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

test('an agent cannot see, issue or revoke tokens', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $token = ApiToken::factory()->create(['account_id' => $account->id]);

    // Reading the list is admin-only too, not just changing it: the list names
    // the sites each token reaches, and an agent with restricted site access
    // would learn the names of sites that 404 for them everywhere else.
    $this->actingAs($agent)
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertForbidden();

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

test('a restricted token whose only site is purged reaches nothing', function (): void {
    // The escalation this closes: the pivot cascades when a site is purged, so
    // inferring "restricted" from "has pivot rows" hands a one-site token the
    // whole account the moment that site is deleted -- triggered by an
    // unrelated admin action, with nothing in the token's history to show it.
    $w = tokenAdmin();
    $second = Site::factory()->for($w['account'])->create(['name' => 'Second']);

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), [
            'name' => 'One site only',
            'abilities' => ['read'],
            'site_ids' => [$w['site']->id],
        ])
        ->getSession()->get('issued_api_token'));

    expect($this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->json('data.site_ids'))
        ->toBe([$w['site']->id]);

    $w['site']->delete();

    // Not the account's remaining sites. Nothing.
    expect($this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->json('data.site_ids'))
        ->toBe([])
        ->and($second->exists())->toBeTrue();
});

test('issuing and revoking a token are both audited', function (): void {
    // ADR 0018 says issuance and revocation are audited like any other account
    // event. The first version of this promised it and did not do it.
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Audited', 'abilities' => ['read']]);

    $token = ApiToken::query()->firstOrFail();

    $this->actingAs($w['admin'])->delete(route('dashboard.account.api-tokens.destroy', $token));

    $events = AuditEvent::query()->whereIn('action', ['api_token.created', 'api_token.revoked'])->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$w['admin']->id])
        ->and($events->pluck('subject_id')->unique()->all())->toBe([$token->id]);
});

test('the audit record of a token is not a copy of it', function (): void {
    // The audit log is exportable.
    $w = tokenAdmin();

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Audited', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token'));

    $event = AuditEvent::query()->where('action', 'api_token.created')->firstOrFail();
    $token = ApiToken::query()->firstOrFail();

    expect(json_encode($event->metadata))->not->toContain($plain)
        ->and(json_encode($event->metadata))->not->toContain($token->token_hash);
});

test('the audit log names the API token actions rather than headline-casing them', function (): void {
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Named', 'abilities' => ['read']]);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('API token issued')
        ->assertDontSee('Api Token Created');
});

test('an admin cannot issue a token that reaches further than they can', function (): void {
    // The escalation: site access restricts an admin too, and issuing a token
    // is not the "explicit elevated view" rbac-waypoints reserves. Without this
    // an admin who cannot see every site issues an unrestricted token and reads
    // through the API exactly what the dashboard hides from them.
    $account = Account::factory()->create();
    $w = tokenAdmin($account);
    $hidden = Site::factory()->for($account)->create(['name' => 'Hidden']);

    $somebodyElse = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $hidden->supportAgents()->syncWithoutDetaching($somebodyElse->id);

    // No site_ids at all -- the "account-wide" path.
    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Wide', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token'));

    $reach = $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->json('data.site_ids');

    expect($reach)->toBe([$w['site']->id])
        ->and($reach)->not->toContain($hidden->id)
        ->and(ApiToken::query()->firstOrFail()->restricts_sites)->toBeTrue();
});

test('a token is pinned to a list even when its issuer sees everything', function (): void {
    // Seeing every site that exists today is not account-wide authority, and
    // there is no such thing here: visibility is per site, so a site created
    // later and assigned to its creator is invisible to everybody else. An
    // unrestricted token would read it anyway, outliving the ceiling it was
    // issued under.
    $w = tokenAdmin();

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Wide', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token'));

    $token = ApiToken::query()->firstOrFail();

    expect($token->restricts_sites)->toBeTrue()
        ->and($token->sites->pluck('id')->all())->toBe([$w['site']->id]);

    // A site created afterwards does not join it.
    $later = Site::factory()->for($w['account'])->create(['name' => 'Opened later']);

    expect($this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->json('data.site_ids'))
        ->toBe([$w['site']->id])
        ->and($later->exists())->toBeTrue();
});

test('asking for sites you cannot see grants nothing, not everything', function (): void {
    // The edge that turns a narrowing request into a widening one: every
    // submitted id is filtered out, the list comes back empty, and an empty
    // list read as "unrestricted" hands back the whole account.
    $account = Account::factory()->create();
    $w = tokenAdmin($account);
    $hidden = Site::factory()->for($account)->create(['name' => 'Hidden']);

    $somebodyElse = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $hidden->supportAgents()->syncWithoutDetaching($somebodyElse->id);

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), [
            'name' => 'Only the hidden one',
            'abilities' => ['read'],
            'site_ids' => [$hidden->id],
        ])
        ->getSession()->get('issued_api_token'));

    expect($this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->json('data.site_ids'))
        ->toBe([]);
});

test('a restricted token whose sites are purged says so, rather than claiming the account', function (): void {
    // The flag says restricted and the relationship is empty, which for an
    // UNRESTRICTED token means "every site". Reading the relationship alone
    // told the admin the exact opposite of the credential's real reach.
    $w = tokenAdmin();

    $token = ApiToken::factory()->create([
        'account_id' => $w['account']->id,
        'name' => 'Was one site',
        'restricts_sites' => true,
    ]);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('every site it was limited to has been purged')
        ->assertDontSee('Every site on this account');

    expect($token->fresh()->restricts_sites)->toBeTrue();
});

test('an expired token is not counted as active', function (): void {
    // The same row is labelled Expired in the table and refused at
    // authentication, so counting it as active contradicts the page itself.
    $w = tokenAdmin();

    ApiToken::factory()->expired()->create(['account_id' => $w['account']->id, 'name' => 'Old']);
    ApiToken::factory()->create(['account_id' => $w['account']->id, 'name' => 'Live']);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('1 active')
        ->assertDontSee('2 active');
});

test('the token list does not name sites the viewing admin cannot support', function (): void {
    // Site access restricts an admin too -- the issuance path now says so
    // explicitly -- so the admin-only gate does not by itself close the leak.
    // A token can legitimately reach sites its viewer cannot, and naming them
    // here would publish exactly what site access hides.
    $account = Account::factory()->create();
    $w = tokenAdmin($account);
    $hidden = Site::factory()->for($account)->create(['name' => 'Zzarquon Holdings']);

    $somebodyElse = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $hidden->supportAgents()->syncWithoutDetaching($somebodyElse->id);

    $token = ApiToken::factory()->create([
        'account_id' => $account->id,
        'name' => 'Reaches both',
        'restricts_sites' => true,
    ]);
    $token->sites()->attach([$w['site']->id, $hidden->id]);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('Docs')
        ->assertSee('sites you do not support')
        ->assertDontSee('Zzarquon Holdings');
});

test('an archived site does not make a full-access admin look site-limited', function (): void {
    // `visibleToAgent()` filters to servable sites, so one archived site on the
    // account made every admin appear restricted -- pinning their tokens to
    // servable sites and costing them the archived history ADR 0018 keeps
    // readable on purpose.
    $account = Account::factory()->create();
    $w = tokenAdmin($account);
    $archived = Site::factory()->for($account)->create(['archived_at' => now()]);

    $plain = issuedPlaintext($this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Wide', 'abilities' => ['read']])
        ->getSession()->get('issued_api_token'));

    $token = ApiToken::query()->firstOrFail();

    // Pinned, and the archived site is IN the pinned list -- archiving takes a
    // site out of service, and a read surface is not service.
    expect($token->restricts_sites)->toBeTrue()
        ->and($this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->json('data.site_ids'))
        ->toContain($archived->id);
});

test('an audit record names which token it concerns, and can be searched for it', function (): void {
    // With several credentials, "Account" as the subject cannot answer the only
    // question the record exists to answer.
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Reporting sync', 'abilities' => ['read']]);
    $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Billing export', 'abilities' => ['read']]);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('API token Reporting sync')
        ->assertSee('API token Billing export');

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'Billing export']))
        ->assertOk()
        ->assertSee('API token Billing export')
        ->assertDontSee('API token Reporting sync');
});

test('the plaintext token is never written to the session store', function (): void {
    // The default driver is `database` with SESSION_ENCRYPT=false, so flashing
    // the plaintext puts a usable bearer credential in the `sessions` table --
    // recoverable from a database export, and flatly contradicting the
    // hash-only guarantee this page makes in three places.
    $w = tokenAdmin();

    $response = $this->actingAs($w['admin'])
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']]);

    $plain = issuedPlaintext($response->getSession()->get('issued_api_token'));

    expect($plain)->toStartWith('wfk_')
        // What actually sits in the session is ciphertext.
        ->and($response->getSession()->get('issued_api_token'))->not->toContain($plain);

    // And nothing anywhere in the serialised session carries it.
    expect(json_encode($response->getSession()->all()))->not->toContain($plain);
});

test('a token that cannot be decrypted shows the page without its banner', function (): void {
    // A rotated app key or a hand-edited session should not turn the token list
    // into an error page.
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->withSession(['issued_api_token' => 'not-decryptable'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertDontSee('Copy this now');
});

test('a site-limited admin is told the token will be limited too', function (): void {
    // The ADR and the API guide describe the issuer ceiling; the form where the
    // decision is actually made promised account-wide reach, so an admin could
    // deploy an integration expecting everything and silently get a subset.
    $account = Account::factory()->create();
    $w = tokenAdmin($account);
    $hidden = Site::factory()->for($account)->create(['name' => 'Hidden']);

    $somebodyElse = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $hidden->supportAgents()->syncWithoutDetaching($somebodyElse->id);

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('reaches every site <strong>you support today</strong>', false)
        ->assertSee('A site created afterwards is not added to it');
});

test('the form never promises reach that outlives the issuer', function (): void {
    // One sentence for every admin now, because "tick none" means the same
    // thing for all of them: the sites you support today, and no more.
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->get(route('dashboard.account.api-tokens.index'))
        ->assertOk()
        ->assertSee('reaches every site <strong>you support today</strong>', false)
        ->assertDontSee('reaches every site on this account');
});

test('a non-admin cannot tell their account tokens from anyone else', function (): void {
    // Token ids are sequential, and an agent is deliberately barred from seeing
    // the list at all. Checking the account before the role gave them 403 for
    // their own account's tokens and 404 for other people's -- an enumeration
    // oracle for exactly the person the page is hidden from.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $ours = ApiToken::factory()->create(['account_id' => $account->id]);
    $theirs = ApiToken::factory()->create();

    $mine = $this->actingAs($agent)->delete(route('dashboard.account.api-tokens.destroy', $ours));
    $other = $this->actingAs($agent)->delete(route('dashboard.account.api-tokens.destroy', $theirs));

    expect($mine->status())->toBe(403)
        ->and($other->status())->toBe(403)
        ->and($ours->fresh()->revoked_at)->toBeNull()
        ->and($theirs->fresh()->revoked_at)->toBeNull();
});

test('an admin still gets 404 for another account token', function (): void {
    // The authority check moving first must not lose the cross-account
    // behaviour for somebody who is allowed to be here.
    $w = tokenAdmin();
    $theirs = ApiToken::factory()->create();

    $this->actingAs($w['admin'])
        ->delete(route('dashboard.account.api-tokens.destroy', $theirs))
        ->assertNotFound();
});

test('revoking twice keeps the moment it was actually disabled', function (): void {
    // A replayed DELETE would otherwise stamp the retry time over the real one
    // and write a second audit event, degrading the record the row is kept for.
    $w = tokenAdmin();
    $token = ApiToken::factory()->create(['account_id' => $w['account']->id]);

    $this->actingAs($w['admin'])->delete(route('dashboard.account.api-tokens.destroy', $token));

    $revokedAt = $token->fresh()->revoked_at;

    $this->travel(2)->hours();

    $this->actingAs($w['admin'])
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->assertRedirect(route('dashboard.account.api-tokens.index'));

    expect($token->fresh()->revoked_at->timestamp)->toBe($revokedAt->timestamp)
        ->and(AuditEvent::query()->where('action', 'api_token.revoked')->count())->toBe(1);
});

test('a non-admin cannot tell an existing token id from a made-up one', function (): void {
    // Model binding resolves before the controller runs, so type-hinting the
    // model gave a non-admin 404 for an id that does not exist and 403 for one
    // that does -- the same oracle the check order closes, one layer above
    // where reordering inside the controller can reach it.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);

    $real = ApiToken::factory()->create(['account_id' => $account->id]);

    $existing = $this->actingAs($agent)->delete(route('dashboard.account.api-tokens.destroy', $real));
    $invented = $this->actingAs($agent)->delete(route('dashboard.account.api-tokens.destroy', $real->id + 9_999));

    expect($existing->status())->toBe(403)
        ->and($invented->status())->toBe(403)
        ->and($real->fresh()->revoked_at)->toBeNull();
});

test('an admin cannot tell another account token from a made-up one either', function (): void {
    $w = tokenAdmin();
    $theirs = ApiToken::factory()->create();

    $other = $this->actingAs($w['admin'])->delete(route('dashboard.account.api-tokens.destroy', $theirs));
    $invented = $this->actingAs($w['admin'])->delete(route('dashboard.account.api-tokens.destroy', $theirs->id + 9_999));

    expect($other->status())->toBe(404)
        ->and($invented->status())->toBe(404);
});

test('a malformed token id is a 404, not a server error', function (): void {
    // The controller takes the id raw so model binding cannot answer before the
    // authority check, which left a non-numeric id reaching `whereKey()` as a
    // string. PostgreSQL raises comparing that to a bigint.
    $w = tokenAdmin();

    $this->actingAs($w['admin'])
        ->delete('/dashboard/account/api-tokens/not-a-number')
        ->assertNotFound();
});

test('the revoke route refuses a non-numeric id at routing', function (): void {
    // The behavioural test above cannot prove this: SQLite compares a string to
    // an integer key without complaint, so it returns 404 whether or not the
    // constraint exists, and removing the constraint leaves it green. Only
    // PostgreSQL -- what every documented install runs -- raises.
    //
    // So the constraint is asserted directly, the same way the ticket
    // transition lock is: the suite cannot show the failure, but it can show
    // the guard is still there.
    $route = Route::getRoutes()->getByName('dashboard.account.api-tokens.destroy');

    expect($route)->not->toBeNull()
        ->and($route->wheres)->toHaveKey('apiToken')
        ->and($route->wheres['apiToken'])->toBe('[0-9]+');
});

test('a numeric id too large to be a key is a 404, not a server error', function (): void {
    // The route constraint allows any run of digits, and PostgreSQL raises
    // casting a 30-digit value to a bigint. Numeric is not the same as usable,
    // and an id too large to exist is treated exactly like one that does not.
    $w = tokenAdmin();

    foreach (['999999999999999999999999999999', '9223372036854775808', str_repeat('9', 40)] as $tooBig) {
        $this->actingAs($w['admin'])
            ->delete('/dashboard/account/api-tokens/'.$tooBig)
            ->assertNotFound();
    }

    // The largest value that IS a key still reaches the ordinary lookup.
    $this->actingAs($w['admin'])
        ->delete('/dashboard/account/api-tokens/'.PHP_INT_MAX)
        ->assertNotFound();
});

test('a revocation decides from the locked row, not the one read before it', function (): void {
    // Two DELETEs in flight together both read an unrevoked row, both pass an
    // unlocked guard, and both write -- stamping the later time over the moment
    // the credential was actually disabled and recording the revocation twice.
    // This account's audit trail is a product feature rather than a debug log,
    // so a duplicate entry is a wrong answer to "who turned this off, and when".
    //
    // SQLite cannot run the two requests at once, so the interleaving is staged
    // instead: the moment the controller reads the token, another writer
    // revokes it underneath. A revocation that re-reads under its lock sees
    // that and stands down; one that trusts the model it loaded first does not.
    ['admin' => $admin, 'account' => $account] = tokenAdmin();

    $generated = ApiToken::generate();
    $token = ApiToken::query()->create([
        'account_id' => $account->id,
        'name' => 'Integration',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ]);

    $revokedAt = now()->subMinutes(5);
    $raced = false;

    DB::listen(function ($query) use ($token, $revokedAt, &$raced): void {
        if ($raced || ! str_contains($query->sql, 'api_tokens') || ! str_contains($query->sql, 'select')) {
            return;
        }

        // Once, and before the controller's own transaction opens.
        $raced = true;

        DB::table('api_tokens')->where('id', $token->id)->update(['revoked_at' => $revokedAt]);
    });

    $this->actingAs($admin)
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->assertRedirect(route('dashboard.account.api-tokens.index'))
        ->assertSessionHas('status', 'api_tokens.flash.already_revoked');

    // The moment it was actually disabled survives, rather than being stamped
    // over with the moment somebody asked a second time.
    expect($token->fresh()->revoked_at->timestamp)->toBe($revokedAt->timestamp);

    expect(AuditEvent::query()->where('action', 'api_token.revoked')->count())->toBe(0);
});

test('revoking twice in a row records it once', function (): void {
    ['admin' => $admin, 'account' => $account] = tokenAdmin();

    $generated = ApiToken::generate();
    $token = ApiToken::query()->create([
        'account_id' => $account->id,
        'name' => 'Integration',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ]);

    $this->actingAs($admin)->delete(route('dashboard.account.api-tokens.destroy', $token));
    $firstRevokedAt = $token->fresh()->revoked_at;

    $this->actingAs($admin)
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->assertSessionHas('status', 'api_tokens.flash.already_revoked');

    expect($token->fresh()->revoked_at->timestamp)->toBe($firstRevokedAt->timestamp)
        ->and(AuditEvent::query()->where('action', 'api_token.revoked')->count())->toBe(1);
});

test('every flashed status is a key, taken from the action that flashes it', function (): void {
    // The controller flashes a KEY rather than a sentence, because it
    // redirects: the request that renders the flash is a different request
    // from the one that chose it, and an agent's language is resolved per
    // request.
    //
    // Driven through the ACTIONS rather than over the catalogue. The first
    // version of this test iterated the four keys and asserted each resolved
    // in German -- which proves the catalogue has them and nothing about what
    // the controller does. Reverting `flash.revoked` to an English sentence
    // left it green.
    ['admin' => $admin, 'account' => $account, 'site' => $site] = tokenAdmin();

    $flashes = [];

    $flashes['created_limited'] = $this->actingAs($admin)
        ->post(route('dashboard.account.api-tokens.store'), ['name' => 'Sync', 'abilities' => ['read']])
        ->getSession()->get('status');

    $flashes['created'] = $this->actingAs($admin)
        ->post(route('dashboard.account.api-tokens.store'), [
            'name' => 'Scoped sync',
            'abilities' => ['read'],
            'site_ids' => [$site->id],
        ])
        ->getSession()->get('status');

    $token = ApiToken::query()->firstOrFail();

    $flashes['revoked'] = $this->actingAs($admin)
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->getSession()->get('status');

    $flashes['already_revoked'] = $this->actingAs($admin)
        ->delete(route('dashboard.account.api-tokens.destroy', $token))
        ->getSession()->get('status');

    foreach ($flashes as $action => $flashed) {
        expect($flashed)->toBeString()->not->toBe('', "the {$action} action flashed nothing");

        // A key, not a sentence -- `__()` on a sentence returns the sentence.
        expect(__($flashed, [], 'de'))
            ->not->toBe($flashed, "the {$action} action flashed '{$flashed}', which is not a catalogue key")
            ->and(__($flashed, [], 'de'))->not->toBe(__($flashed, [], 'en'));
    }

    // And all four are distinct, so a controller collapsing two of them into
    // one key cannot pass on the other's translation.
    expect(array_unique(array_values($flashes)))->toHaveCount(4);
});
