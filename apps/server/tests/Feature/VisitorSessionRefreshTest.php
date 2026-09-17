<?php

use App\Models\Site;
use App\Models\Visitor;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/**
 * Bootstrap is the only way to obtain a visitor token, and it asks for nothing
 * the product keeps secret -- a site's public key is published by design and
 * the anonymous id is displayed in the dashboard. So there has been no way to
 * re-mint a token that proves more than bootstrap does, and therefore no way
 * to give tokens a lifetime without stranding every session that outlives one.
 *
 * These cover the path that closes that gap. They do NOT cover expiry: nothing
 * expires yet, deliberately, so that this lands without changing behaviour for
 * a single already-embedded widget.
 */
function refreshSessionFixture(string $anonymousId = 'anon-refresh'): array
{
    $site = Site::factory()->create(['public_key' => 'site_public_refresh']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => $anonymousId]);

    return [$site, $visitor];
}

/**
 * Named after this file rather than after the concept: Pest helpers are global
 * but only once their file is loaded, so borrowing one from another test file
 * works in a full run and breaks the moment anybody runs this file alone.
 */
function refreshSessionBootstrapToken($test, string $sitePublicKey, string $anonymousId): string
{
    return $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => $sitePublicKey,
        'anonymous_id' => $anonymousId,
        'page_url' => 'https://docs.example.test/install',
    ])
        ->assertSuccessful()
        ->json('data.visitor.token');
}

test('a visitor holding a valid token can trade it for a fresh one', function (): void {
    [$site, $visitor] = refreshSessionFixture();
    $original = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');

    // Move the clock so the new token cannot coincidentally equal the old one
    // for a reason other than the code being wrong.
    Carbon::setTestNow(now()->addMinutes(5));

    try {
        $refreshed = $this->postJson('/api/widget/session', [
            'site_public_key' => 'site_public_refresh',
            'anonymous_id' => 'anon-refresh',
            'visitor_token' => $original,
        ])
            ->assertOk()
            ->assertJsonPath('data.visitor.anonymous_id', 'anon-refresh')
            ->json('data.visitor.token');

        expect($refreshed)->toBeString()->not->toBe($original);

        $tokens = app(VisitorSessionToken::class);

        expect($tokens->issuedAt($refreshed)->greaterThan($tokens->issuedAt($original)))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('the refreshed token actually works as a credential', function (): void {
    // A token that verifies on the refresh endpoint but not on the endpoints a
    // widget uses would be worse than none -- it would fail only once the
    // session it replaced expired.
    [$site, $visitor] = refreshSessionFixture();
    $original = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');

    $refreshed = $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => 'anon-refresh',
        'visitor_token' => $original,
    ])->assertOk()->json('data.visitor.token');

    // Refresh again using only the refreshed token: proves it verifies through
    // the same path every conversation endpoint uses.
    $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => 'anon-refresh',
        'visitor_token' => $refreshed,
    ])->assertOk();
});

test('refresh requires a token, which is the whole point of it', function (): void {
    // Bootstrap mints from a site key and an anonymous id alone. If refresh
    // did the same it would prove nothing extra and be a second bootstrap.
    refreshSessionFixture();

    $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => 'anon-refresh',
    ])->assertStatus(401);
});

test('a token minted for another site cannot be refreshed here', function (): void {
    refreshSessionFixture();

    $other = Site::factory()->create(['public_key' => 'site_public_other']);
    Visitor::factory()->for($other)->create(['anonymous_id' => 'anon-refresh']);
    $otherToken = refreshSessionBootstrapToken($this, 'site_public_other', 'anon-refresh');

    $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => 'anon-refresh',
        'visitor_token' => $otherToken,
    ])->assertStatus(403);
});

test('a token cannot be refreshed into a different visitor', function (): void {
    refreshSessionFixture();
    Visitor::factory()->for(Site::query()->where('public_key', 'site_public_refresh')->sole())
        ->create(['anonymous_id' => 'anon-someone-else']);

    $mine = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');

    $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => 'anon-someone-else',
        'visitor_token' => $mine,
    ])->assertStatus(403);
});

test('refreshing carries the session start rather than restarting it', function (): void {
    // Rotation alone would let a token live forever by refreshing just before
    // each expiry. Carrying the original start is what lets a later absolute
    // cap measure the SESSION rather than the most recent mint.
    [$site, $visitor] = refreshSessionFixture();

    Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00'));

    try {
        $original = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');

        Carbon::setTestNow(Carbon::parse('2026-09-17 15:00:00'));

        $refreshed = $this->postJson('/api/widget/session', [
            'site_public_key' => 'site_public_refresh',
            'anonymous_id' => 'anon-refresh',
            'visitor_token' => $original,
        ])->assertOk()->json('data.visitor.token');

        $tokens = app(VisitorSessionToken::class);

        // Minted now...
        expect($tokens->issuedAt($refreshed)->format('H:i'))->toBe('15:00');

        // ...but the session still began at nine.
        $payload = decodeVisitorSessionPayload($refreshed);
        expect(Carbon::parse($payload['session_started_at'])->format('H:i'))->toBe('09:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('a token minted before session starts were recorded reports its own mint as the start', function (): void {
    // Tokens already in visitors' browsers carry no session_started_at. The
    // earliest moment such a token evidences is when it was issued, and saying
    // so beats inventing a start or treating the session as beginning now.
    [$site, $visitor] = refreshSessionFixture();
    $tokens = app(VisitorSessionToken::class);

    $legacy = ['site_id' => $site->id, 'visitor_id' => $visitor->id, 'anonymous_id' => 'anon-refresh', 'issued_at' => '2026-09-01T08:00:00.000000Z'];

    expect($tokens->sessionStartedAt($legacy)->format('Y-m-d H:i'))->toBe('2026-09-01 08:00');

    // And with neither field, it refuses to claim a start it cannot evidence.
    expect($tokens->sessionStartedAt([])->diffInSeconds(now()))->toBeLessThan(5);
});

function decodeVisitorSessionPayload(string $token): array
{
    return json_decode(Crypt::decryptString($token), true);
}
