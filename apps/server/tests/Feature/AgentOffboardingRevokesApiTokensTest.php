<?php

use App\Actions\UpdateAgentAccess;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Offboarding an agent has to reach the credentials they issued.
 *
 * Deactivation already tears down an agent's realtime sessions, but an API
 * token has no session and nobody at one end: before this, it kept
 * authenticating until an administrator revoked it by hand, so a departing
 * agent's programmatic access outlived their access to the dashboard.
 *
 * Helpers are named after this file on purpose. Pest helpers are global, and
 * two files defining one name is a fatal that takes the whole suite down
 * before a test runs.
 */
function offboardingAccount(): Account
{
    return Account::factory()->create();
}

/** @return array{plain: string, token: ApiToken} */
function offboardingTokenIssuedBy(User $issuer, string $name = 'Integration'): array
{
    $generated = ApiToken::generate();

    $token = ApiToken::query()->create([
        'account_id' => $issuer->account_id,
        'created_by_id' => $issuer->id,
        'name' => $name,
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ]);

    return ['plain' => $generated['plain'], 'token' => $token];
}

function offboardingDeactivate(User $actor, User $target): void
{
    app(UpdateAgentAccess::class)->deactivate($actor, $target);
}

test('a token stops authenticating when the agent who issued it is deactivated', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['plain' => $plain] = offboardingTokenIssuedBy($issuer);

    // The credential works while its issuer does. Asserted rather than
    // assumed: a test that only checks the refusal afterwards passes just as
    // well against a token that never worked at all.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertOk();

    offboardingDeactivate($owner, $issuer);

    // End to end through the middleware, not just the model flag. `isUsable()`
    // returning false is the mechanism; being refused an actual request is the
    // outcome, and only the second one is what an attacker meets.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
});

test('deactivating one agent leaves another agent tokens working', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $leaver = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $stayer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['plain' => $leaverPlain] = offboardingTokenIssuedBy($leaver, 'Leaver integration');
    ['plain' => $stayerPlain] = offboardingTokenIssuedBy($stayer, 'Stayer integration');

    offboardingDeactivate($owner, $leaver);

    // The blast radius is the point of this test. Revoking every token on the
    // account when one admin leaves would take an install's integrations down,
    // and would pass a test that only looked at the leaver's own credential.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$leaverPlain])->assertStatus(401);
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$stayerPlain])->assertOk();
});

test('a token issued by nobody is left alone when an agent is deactivated', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $generated = ApiToken::generate();
    ApiToken::query()->create([
        'account_id' => $account->id,
        // Null when the issuer's row is gone, which the schema allows.
        'created_by_id' => null,
        'name' => 'Orphaned integration',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ]);

    offboardingDeactivate($owner, $issuer);

    // A token with no issuer has no issuer who left. Sweeping these in would
    // disable an account's integrations because an unrelated agent departed.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$generated['plain']])->assertOk();
});

test('reactivating an agent does not bring their tokens back', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['plain' => $plain, 'token' => $token] = offboardingTokenIssuedBy($issuer);

    offboardingDeactivate($owner, $issuer);
    app(UpdateAgentAccess::class)->reactivate($owner, $issuer);

    expect($issuer->fresh()->isDeactivated())->toBeFalse();

    // Revocation is a one-way door, the same one the dashboard's own revoke
    // control goes through. A credential that came back to life with the
    // person would be a standing secret nobody decided to re-issue -- and it
    // may have been copied while it was live.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
    expect($token->fresh()->isRevoked())->toBeTrue();
});

test('an already revoked token keeps the moment it was actually revoked', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['token' => $token] = offboardingTokenIssuedBy($issuer);

    $revokedAt = now()->subDays(3)->startOfSecond();
    $token->forceFill(['revoked_at' => $revokedAt])->save();

    offboardingDeactivate($owner, $issuer);

    // Re-stamping would move the record of when the credential actually
    // stopped working, which is the one question the timestamp exists to
    // answer.
    expect($token->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
});

test('the audit trail says a token was revoked with its issuer, not by hand', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $issuer = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    ['token' => $token] = offboardingTokenIssuedBy($issuer, 'Billing sync');

    offboardingDeactivate($owner, $issuer);

    $event = AuditEvent::query()
        ->where('subject_type', $token->getMorphClass())
        ->where('subject_id', $token->id)
        ->sole();

    // A distinct action rather than `api_token.revoked` with a reason buried in
    // metadata: audit metadata renders nowhere today, so a distinction that
    // lives only there is one nobody investigating can see.
    expect($event->action)->toBe('api_token.revoked_with_issuer')
        ->and($event->actor_id)->toBe($owner->id)
        ->and($event->metadata['issuer_id'])->toBe($issuer->id)
        ->and($event->metadata['issuer_name'])->toBe('Ada Admin')
        ->and($event->metadata['name'])->toBe('Billing sync');

    // Never the credential itself. The audit log is exportable.
    expect(json_encode($event->metadata))->not->toContain($token->token_hash);
});

test('the audit action has a label in every shipped language', function (): void {
    // An action with no entry falls back to `account_audit.actions.other`,
    // which renders as an unhelpful generic line rather than an error -- so a
    // missing translation is invisible until somebody reads their own trail.
    foreach (['en', 'de', 'it'] as $locale) {
        expect(__('account_audit.actions.api_token_revoked_with_issuer', [], $locale))
            ->not->toBe('account_audit.actions.api_token_revoked_with_issuer')
            ->and(__('account_audit.actions.api_token_revoked_with_issuer', [], $locale))
            ->not->toBe(__('account_audit.actions.other', [], $locale));
    }
});

test('deactivating an agent who is already deactivated revokes nothing further', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    offboardingTokenIssuedBy($issuer);
    offboardingDeactivate($owner, $issuer);

    $eventCount = AuditEvent::query()->where('action', 'api_token.revoked_with_issuer')->count();

    offboardingDeactivate($owner, $issuer);

    // The action returns early for an already-deactivated target, so a second
    // call must not write a second revocation into the trail.
    expect(AuditEvent::query()->where('action', 'api_token.revoked_with_issuer')->count())
        ->toBe($eventCount);
});

/*
 * The sweep that clears agents deactivated before this shipped. Those rows
 * never went through the deactivation path, so nothing revoked their tokens --
 * and an API token has no session to expire and nobody to report it.
 */

test('the sweep revokes a token whose issuer was deactivated before this shipped', function (): void {
    $account = offboardingAccount();
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['plain' => $plain] = offboardingTokenIssuedBy($issuer);

    // Deactivated WITHOUT going through the action, which is exactly the state
    // every agent offboarded by an earlier release is already in.
    $issuer->forceFill(['deactivated_at' => now()->subMonths(6)])->save();

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertOk();

    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')->assertSuccessful();

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
});

test('the sweep leaves tokens issued by active agents alone', function (): void {
    $account = offboardingAccount();
    $active = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $gone = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['plain' => $activePlain] = offboardingTokenIssuedBy($active, 'Live integration');
    ['plain' => $gonePlain] = offboardingTokenIssuedBy($gone, 'Stale integration');

    $gone->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')->assertSuccessful();

    // This is the assertion that makes the sweep safe to run on every deploy
    // and nightly. A sweep that took the whole table would pass every other
    // test in this file.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$activePlain])->assertOk();
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$gonePlain])->assertStatus(401);
});

test('the sweep leaves a token with no issuer alone', function (): void {
    $account = offboardingAccount();
    $gone = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $gone->forceFill(['deactivated_at' => now()->subDay()])->save();

    $generated = ApiToken::generate();
    ApiToken::query()->create([
        'account_id' => $account->id,
        'created_by_id' => null,
        'name' => 'Orphaned integration',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_READ],
    ]);

    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')->assertSuccessful();

    // A null issuer is nobody's departure. Revoking these would disable an
    // account's integrations because an unrelated agent left.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$generated['plain']])->assertOk();
});

test('the sweep records a system action rather than blaming an administrator', function (): void {
    $account = offboardingAccount();
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['token' => $token] = offboardingTokenIssuedBy($issuer);
    $issuer->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')->assertSuccessful();

    $event = AuditEvent::query()
        ->where('subject_type', $token->getMorphClass())
        ->where('subject_id', $token->id)
        ->sole();

    // Nobody performed this. Attributing it to an administrator would put a
    // name against a decision they did not make.
    expect($event->action)->toBe('api_token.revoked_with_issuer')
        ->and($event->actor_id)->toBeNull()
        ->and($event->actor_type)->toBeNull()
        ->and($event->metadata['issuer_id'])->toBe($issuer->id);
});

test('running the sweep twice revokes once and keeps the first moment', function (): void {
    $account = offboardingAccount();
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['token' => $token] = offboardingTokenIssuedBy($issuer);
    $issuer->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')->assertSuccessful();

    $firstRevokedAt = $token->fresh()->revoked_at;

    $this->travel(5)->minutes();
    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')->assertSuccessful();

    // Idempotent, because it runs on every deploy and every night. A second
    // pass that re-stamped would rewrite when the credential stopped working,
    // and a second audit row would say it was revoked twice.
    expect($token->fresh()->revoked_at->equalTo($firstRevokedAt))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'api_token.revoked_with_issuer')->count())->toBe(1);
});

test('the sweep says what it did', function (): void {
    $account = offboardingAccount();
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    offboardingTokenIssuedBy($issuer);
    $issuer->forceFill(['deactivated_at' => now()->subDay()])->save();

    // Silence would be the wrong behaviour for something that disables live
    // credentials on a schedule.
    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')
        ->expectsOutputToContain('Revoked 1 API token issued by 1 deactivated agent.')
        ->assertSuccessful();

    $this->artisan('wayfindr:revoke-deactivated-issuer-api-tokens')
        ->expectsOutputToContain('No API tokens are held by deactivated issuers.')
        ->assertSuccessful();
});
