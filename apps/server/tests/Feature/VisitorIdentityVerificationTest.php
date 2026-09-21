<?php

use App\Models\Site;
use App\Models\Visitor;
use App\Support\Visitors\VisitorIdentityVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * `visitors.external_id` arrives from the host page through an endpoint that
 * authenticates nobody, and the product displays it as the visitor's name --
 * `VisitorLabel` ranks it above `anonymous_id`. So an agent reads a claim as a
 * fact, and anyone who can reach the endpoint can make the claim.
 *
 * A site that turns verification on gives its host a secret; the host HMACs the
 * identifier on its own server and passes the result. These tests are about
 * what a caller WITHOUT that secret can no longer do.
 */
function verifyingSite(array $attributes = []): Site
{
    $generated = VisitorIdentityVerification::generateSecret();

    return Site::factory()->create(array_merge([
        'identity_secret' => $generated['plain'],
        'identity_secret_last_four' => $generated['last_four'],
        'identity_verification' => VisitorIdentityVerification::REQUIRED,
    ], $attributes));
}

function identityHashFor(Site $site, string $externalId): string
{
    // Computed the way a host would, from the secret they hold.
    return hash_hmac('sha256', $externalId, $site->identity_secret);
}

function bootstrapAs(Site $site, string $anonymousId, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/widget/bootstrap', array_merge([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $anonymousId,
    ], $extra));
}

test('a verified claim is recorded', function (): void {
    $site = verifyingSite();

    bootstrapAs($site, 'anon-host', [
        'external_id' => 'customer-123',
        'identity_hash' => identityHashFor($site, 'customer-123'),
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', true);

    expect(Visitor::query()->where('anonymous_id', 'anon-host')->first()->external_id)
        ->toBe('customer-123');
});

test('an unverified claim is not recorded', function (): void {
    $site = verifyingSite();

    bootstrapAs($site, 'anon-caller', ['external_id' => 'customer-123'])
        ->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', false);

    expect(Visitor::query()->where('anonymous_id', 'anon-caller')->first()->external_id)
        ->toBeNull();
});

test('a claim signed with the wrong secret is not recorded', function (): void {
    $site = verifyingSite();
    $someoneElse = verifyingSite();

    // A well-formed hash, computed with a secret this site does not hold. The
    // shape is right and the provenance is wrong, which is the only thing that
    // matters here.
    bootstrapAs($site, 'anon-wrong-key', [
        'external_id' => 'customer-123',
        'identity_hash' => identityHashFor($someoneElse, 'customer-123'),
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', false);

    expect(Visitor::query()->where('anonymous_id', 'anon-wrong-key')->first()->external_id)
        ->toBeNull();
});

test('a hash for a different identifier does not carry over to another', function (): void {
    $site = verifyingSite();

    // A host legitimately holds a hash for their OWN id. It must not be
    // reusable to claim somebody else's.
    bootstrapAs($site, 'anon-swap', [
        'external_id' => 'customer-victim',
        'identity_hash' => identityHashFor($site, 'customer-mine'),
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', false);

    expect(Visitor::query()->where('anonymous_id', 'anon-swap')->first()->external_id)
        ->toBeNull();
});

test('a site with verification required but no secret refuses every claim', function (): void {
    $site = verifyingSite(['identity_secret' => null, 'identity_secret_last_four' => null]);

    // Fails CLOSED. Treating a missing secret as "verification off" would turn
    // a misconfiguration into a silent downgrade to the behaviour this exists
    // to replace.
    bootstrapAs($site, 'anon-nosecret', [
        'external_id' => 'customer-123',
        'identity_hash' => str_repeat('a', 64),
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', false);

    expect(Visitor::query()->where('anonymous_id', 'anon-nosecret')->first()->external_id)
        ->toBeNull();
});

test('probing cannot tell a taken identifier from a free one', function (): void {
    $site = verifyingSite();

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-real-customer',
        'external_id' => 'customer-taken',
    ]);

    // THE ORACLE. Before verification these two answers differed -- `false`
    // meant somebody already held the id and `true` meant it was free AND the
    // caller had just claimed it -- so an unauthenticated caller could
    // enumerate a site's customer ids with only the public site key.
    $taken = bootstrapAs($site, 'anon-probe-a', ['external_id' => 'customer-taken'])
        ->json('data.visitor.identified');

    $free = bootstrapAs($site, 'anon-probe-b', ['external_id' => 'customer-free'])
        ->json('data.visitor.identified');

    expect($taken)->toBe($free)->toBeFalse();
});

test('probing does not claim an identifier the real customer then needs', function (): void {
    $site = verifyingSite();

    // The caller gets there first, without the secret.
    bootstrapAs($site, 'anon-attacker', ['external_id' => 'customer-123'])->assertSuccessful();

    // The real customer's browser arrives afterwards, with a hash their host
    // computed. Before verification the identifier was already gone and they
    // were left permanently anonymous.
    bootstrapAs($site, 'anon-real', [
        'external_id' => 'customer-123',
        'identity_hash' => identityHashFor($site, 'customer-123'),
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', true);

    expect(Visitor::query()->where('anonymous_id', 'anon-real')->first()->external_id)
        ->toBe('customer-123')
        ->and(Visitor::query()->where('anonymous_id', 'anon-attacker')->first()->external_id)
        ->toBeNull();
});

test('a site with verification off is unchanged', function (): void {
    $site = Site::factory()->create(['identity_verification' => VisitorIdentityVerification::OFF]);

    // Backwards compatibility is the whole reason this is a per-site mode:
    // every existing install has hosts that send no hash at all.
    bootstrapAs($site, 'anon-legacy', ['external_id' => 'customer-123'])
        ->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', true);

    expect(Visitor::query()->where('anonymous_id', 'anon-legacy')->first()->external_id)
        ->toBe('customer-123');
});

test('the conversation endpoint verifies on the same terms as bootstrap', function (): void {
    $site = verifyingSite();

    // The guard used to be duplicated in two controllers. Patching one would
    // leave bootstrap accepting an identifier the very next request rejected,
    // so this asserts the second path independently rather than trusting the
    // shared class by inspection.
    $token = bootstrapAs($site, 'anon-convo')->json('data.visitor.token');

    test()->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-convo',
        'visitor_token' => $token,
        'subject' => 'Need help',
        'external_id' => 'customer-123',
    ])->assertCreated();

    expect(Visitor::query()->where('anonymous_id', 'anon-convo')->first()->external_id)
        ->toBeNull();
});

test('an unverified claim never looks up who holds the identifier', function (): void {
    $site = verifyingSite();

    Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-holder',
        'external_id' => 'customer-taken',
    ]);

    $lookups = 0;
    DB::listen(function ($query) use (&$lookups): void {
        if (str_contains($query->sql, 'from "visitors"') && str_contains($query->sql, '"external_id"')) {
            $lookups++;
        }
    });

    bootstrapAs($site, 'anon-unverified', ['external_id' => 'customer-taken'])->assertSuccessful();

    // Counted rather than read off the response, deliberately. The response is
    // already constant for an unverified caller -- that is what closes the
    // oracle -- so no assertion about the body can see whether the lookup ran.
    // What is left once the answer is constant is a read whose duration depends
    // on whether a row was found, performed for somebody who proved nothing.
    expect($lookups)->toBe(0);
});

test('a verified claim does look up who holds the identifier', function (): void {
    $site = verifyingSite();

    $lookups = 0;
    DB::listen(function ($query) use (&$lookups): void {
        if (str_contains($query->sql, 'from "visitors"') && str_contains($query->sql, '"external_id"')) {
            $lookups++;
        }
    });

    bootstrapAs($site, 'anon-verified', [
        'external_id' => 'customer-123',
        'identity_hash' => identityHashFor($site, 'customer-123'),
    ])->assertSuccessful();

    // The control for the test above: without this, deleting the exclusivity
    // check entirely would satisfy "no lookup" and look like a pass.
    expect($lookups)->toBeGreaterThan(0);
});

test('the formula the README gives hosts is the formula the server checks', function (): void {
    $site = verifyingSite();

    // Written out longhand rather than calling the helper, because this is
    // asserting that what a host copies out of packages/widget-js/README.md
    // verifies here. Documented snippets are interface: if this drifts, every
    // host who followed the docs silently stops identifying anybody.
    $readmeFormula = hash_hmac('sha256', 'customer-4821', $site->identity_secret);

    expect(app(VisitorIdentityVerification::class)->verifies($site, 'customer-4821', $readmeFormula))
        ->toBeTrue();

    // And it reaches the endpoint, not just the class.
    bootstrapAs($site, 'anon-readme', [
        'external_id' => 'customer-4821',
        'identity_hash' => $readmeFormula,
    ])->assertSuccessful()
        ->assertJsonPath('data.visitor.identified', true);
});

test('the secret a site is issued is never served to a browser', function (): void {
    $site = verifyingSite();

    $response = bootstrapAs($site, 'anon-leak', [
        'external_id' => 'customer-123',
        'identity_hash' => identityHashFor($site, 'customer-123'),
    ])->assertSuccessful();

    // The whole scheme rests on the secret staying server-side. A bootstrap
    // response that carried it would hand it to every visitor, and the feature
    // would be worse than not having it -- anyone could then sign anything.
    $body = $response->getContent();

    expect($body)->not->toContain($site->identity_secret)
        ->and($body)->not->toContain(VisitorIdentityVerification::SECRET_PREFIX);
});
