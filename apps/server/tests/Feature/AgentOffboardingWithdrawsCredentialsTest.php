<?php

use App\Actions\UpdateAgentAccess;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\OutboundWebhookDelivery;
use App\Models\OutboundWebhookEndpoint;
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

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
});

test('the sweep leaves tokens issued by active agents alone', function (): void {
    $account = offboardingAccount();
    $active = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $gone = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['plain' => $activePlain] = offboardingTokenIssuedBy($active, 'Live integration');
    ['plain' => $gonePlain] = offboardingTokenIssuedBy($gone, 'Stale integration');

    $gone->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

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

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

    // A null issuer is nobody's departure. Revoking these would disable an
    // account's integrations because an unrelated agent left.
    $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$generated['plain']])->assertOk();
});

test('the sweep records a system action rather than blaming an administrator', function (): void {
    $account = offboardingAccount();
    $issuer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    ['token' => $token] = offboardingTokenIssuedBy($issuer);
    $issuer->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

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

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

    $firstRevokedAt = $token->fresh()->revoked_at;

    $this->travel(5)->minutes();
    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

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
    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')
        ->expectsOutputToContain('Revoked 1 API token and disabled 0 webhook endpoints created by 1 deactivated agent.')
        ->assertSuccessful();

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')
        ->expectsOutputToContain('No API tokens or webhook endpoints are held by deactivated agents.')
        ->assertSuccessful();
});

/*
 * The other half. An outbound webhook endpoint is not a credential the agent
 * carries -- its secret lives in the subscriber system -- so what outlives them
 * is the DESTINATION they chose, still receiving events nobody re-approved. It
 * has no expiry column either, so nothing ever closes it on its own.
 */

function offboardingEndpointCreatedBy(User $creator, string $name = 'Ops feed'): OutboundWebhookEndpoint
{
    return OutboundWebhookEndpoint::factory()->for($creator->account)->create([
        'created_by_id' => $creator->id,
        'name' => $name,
        'disabled_at' => null,
    ]);
}

test('a webhook endpoint stops delivering when the agent who created it is deactivated', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $creator = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $endpoint = offboardingEndpointCreatedBy($creator);

    expect($endpoint->isEnabled())->toBeTrue();

    offboardingDeactivate($owner, $creator);

    // `isEnabled()` is what the publisher and the delivery job both consult, so
    // this is the state that actually stops a POST leaving the install.
    expect($endpoint->fresh()->isEnabled())->toBeFalse();
});

test('deactivating one agent leaves another agent webhook endpoints delivering', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $leaver = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $stayer = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $leaverEndpoint = offboardingEndpointCreatedBy($leaver, 'Leaver feed');
    $stayerEndpoint = offboardingEndpointCreatedBy($stayer, 'Stayer feed');

    offboardingDeactivate($owner, $leaver);

    // Endpoints are account-owned. Disabling all of them because one admin left
    // would cut an install's integrations off, and would pass every other test
    // in this file.
    expect($leaverEndpoint->fresh()->isEnabled())->toBeFalse()
        ->and($stayerEndpoint->fresh()->isEnabled())->toBeTrue();
});

test('an endpoint already disabled keeps the moment it was actually disabled', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $creator = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $endpoint = offboardingEndpointCreatedBy($creator);
    $disabledAt = now()->subDays(2)->startOfSecond();
    $endpoint->forceFill(['disabled_at' => $disabledAt])->save();

    offboardingDeactivate($owner, $creator);

    expect($endpoint->fresh()->disabled_at->equalTo($disabledAt))->toBeTrue();
});

test('the trail records neither the signing secret nor the destination', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $creator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $endpoint = offboardingEndpointCreatedBy($creator, 'Billing feed');

    offboardingDeactivate($owner, $creator);

    $event = AuditEvent::query()
        ->where('subject_type', $endpoint->getMorphClass())
        ->where('subject_id', $endpoint->id)
        ->sole();

    expect($event->action)->toBe('outbound_webhook.disabled_with_creator')
        ->and($event->metadata['name'])->toBe('Billing feed')
        ->and($event->metadata['issuer_name'])->toBe('Ada Admin');

    // Neither the signing secret nor the destination. The secret is a
    // credential the subscriber holds; the destination is cast `encrypted` on
    // the model, so the product treats it as sensitive at rest -- and this
    // table is a plain array cast that an admin can export as CSV.
    expect(json_encode($event->metadata))->not->toContain($endpoint->secret)
        ->and(json_encode($event->metadata))->not->toContain($endpoint->url)
        ->and($event->metadata)->not->toHaveKey('url');
});

test('the sweep disables an endpoint whose creator was deactivated before this shipped', function (): void {
    $account = offboardingAccount();
    $creator = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $endpoint = offboardingEndpointCreatedBy($creator);
    $creator->forceFill(['deactivated_at' => now()->subMonths(3)])->save();

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

    expect($endpoint->fresh()->isEnabled())->toBeFalse();
});

test('the sweep reaches an agent who left only an endpoint behind', function (): void {
    $account = offboardingAccount();
    $creator = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    // No API token at all. The agent set is drawn from BOTH credential tables,
    // so an agent who issued no token must still be found by the endpoint half
    // -- taking the set from tokens alone would silently skip them.
    $endpoint = offboardingEndpointCreatedBy($creator);
    $creator->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')
        ->expectsOutputToContain('disabled 1 webhook endpoint')
        ->assertSuccessful();

    expect($endpoint->fresh()->isEnabled())->toBeFalse();
});

test('the sweep leaves endpoints created by active agents alone', function (): void {
    $account = offboardingAccount();
    $active = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $gone = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $activeEndpoint = offboardingEndpointCreatedBy($active, 'Live feed');
    $goneEndpoint = offboardingEndpointCreatedBy($gone, 'Stale feed');

    $gone->forceFill(['deactivated_at' => now()->subDay()])->save();

    $this->artisan('wayfindr:withdraw-deactivated-agent-credentials')->assertSuccessful();

    expect($activeEndpoint->fresh()->isEnabled())->toBeTrue()
        ->and($goneEndpoint->fresh()->isEnabled())->toBeFalse();
});

test('the webhook audit action has a label in every shipped language', function (): void {
    foreach (['en', 'de', 'it'] as $locale) {
        expect(__('account_audit.actions.outbound_webhook_disabled_with_creator', [], $locale))
            ->not->toBe('account_audit.actions.outbound_webhook_disabled_with_creator')
            ->and(__('account_audit.actions.outbound_webhook_disabled_with_creator', [], $locale))
            ->not->toBe(__('account_audit.actions.other', [], $locale));
    }
});

test('withdrawing an endpoint cancels the deliveries still waiting on it', function (): void {
    $account = offboardingAccount();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $creator = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);

    $endpoint = offboardingEndpointCreatedBy($creator);

    // Distinct sequences: (endpoint_id, sequence) is unique.
    $pending = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'sequence' => 1,
        'delivered_at' => null,
        'failed_at' => null,
        'cancelled_at' => null,
    ]);
    $delivered = OutboundWebhookDelivery::factory()->for($endpoint, 'endpoint')->create([
        'sequence' => 2,
        'delivered_at' => now()->subHour(),
        'failed_at' => null,
        'cancelled_at' => null,
    ]);

    offboardingDeactivate($owner, $creator);

    // The manual disable in AgentAccountOutboundWebhookController cancels
    // pending rows before stamping `disabled_at`, and the delivery job's own
    // comment states that as the contract: disable "locks the endpoint only
    // long enough to stop publishers, then cancels this row". Withdrawing an
    // endpoint on offboarding has to honour the same contract, or the queue
    // keeps waking up on a backoff for work that can never succeed and the
    // operator's delivery log shows them as still pending.
    expect($pending->fresh()->cancelled_at)->not->toBeNull();

    // A delivery that already went is history, not pending work.
    expect($delivered->fresh()->cancelled_at)->toBeNull();
});
