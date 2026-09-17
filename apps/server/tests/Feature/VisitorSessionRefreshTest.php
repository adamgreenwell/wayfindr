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

test('reopening the panel does not restart the session clock', function (): void {
    // Bootstrap re-mints on every panel open. Without continuity a visitor
    // could hold a session open indefinitely by closing and reopening the
    // widget, which is exactly what an absolute cap exists to prevent.
    [$site, $visitor] = refreshSessionFixture();

    Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00'));

    try {
        $first = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');

        Carbon::setTestNow(Carbon::parse('2026-09-17 11:00:00'));

        $reopened = $this->postJson('/api/widget/bootstrap', [
            'site_public_key' => 'site_public_refresh',
            'anonymous_id' => 'anon-refresh',
            'page_url' => 'https://docs.example.test/install',
            'visitor_token' => $first,
        ])->assertSuccessful()->json('data.visitor.token');

        $payload = decodeVisitorSessionPayload($reopened);

        expect(Carbon::parse($payload['issued_at'])->format('H:i'))->toBe('11:00')
            ->and(Carbon::parse($payload['session_started_at'])->format('H:i'))->toBe('09:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('bootstrapping without a token starts a genuinely new session', function (): void {
    // The tolerant half. Bootstrap must keep working for a first-time visitor
    // who has nothing to present, and for them the session begins now.
    refreshSessionFixture();

    Carbon::setTestNow(Carbon::parse('2026-09-17 12:00:00'));

    try {
        $token = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');
        $payload = decodeVisitorSessionPayload($token);

        expect(Carbon::parse($payload['session_started_at'])->format('H:i'))->toBe('12:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('another visitor token cannot lend its session start', function (): void {
    // Tolerant is not the same as credulous: a token that does not name this
    // visitor is ignored rather than honoured, so nobody can inherit somebody
    // else's clock -- in either direction.
    [$site] = refreshSessionFixture();
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-someone-else']);

    Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00'));

    try {
        $theirs = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-someone-else');

        Carbon::setTestNow(Carbon::parse('2026-09-17 14:00:00'));

        $mine = $this->postJson('/api/widget/bootstrap', [
            'site_public_key' => 'site_public_refresh',
            'anonymous_id' => 'anon-refresh',
            'page_url' => 'https://docs.example.test/install',
            'visitor_token' => $theirs,
        ])->assertSuccessful()->json('data.visitor.token');

        $payload = decodeVisitorSessionPayload($mine);

        expect(Carbon::parse($payload['session_started_at'])->format('H:i'))->toBe('14:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('a junk token on bootstrap is ignored rather than refused', function (): void {
    // Bootstrap is reachable with no token at all and must stay that way.
    refreshSessionFixture();

    $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => 'anon-refresh',
        'page_url' => 'https://docs.example.test/install',
        'visitor_token' => 'not-a-real-token',
    ])->assertSuccessful();
});

test('one visitor cannot spend another visitor behind the same address', function (): void {
    // The same shape presence already learned. Keyed only by site and source
    // IP, thirty refreshes a minute is divided between everyone behind an
    // office, a school or a carrier NAT -- and the failure is silent, because
    // `refreshSession()` reduces a 429 to the same `false` a declined refresh
    // gives. Once a lifetime is enforced, a visitor whose neighbours spent the
    // budget simply stops being able to renew.
    config()->set('wayfindr.widget_rate_limits.session_refresh_per_minute', 2);
    config()->set('wayfindr.widget_rate_limits.session_refresh_per_ip_per_minute', 1000);

    [$site] = refreshSessionFixture('anon-noisy');
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-quiet']);

    $noisy = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-noisy');
    $quiet = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-quiet');

    $refresh = fn (string $anonymousId, string $token) => $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => $anonymousId,
        'visitor_token' => $token,
    ]);

    $refresh('anon-noisy', $noisy)->assertOk();
    $refresh('anon-noisy', $noisy)->assertOk();
    $refresh('anon-noisy', $noisy)->assertStatus(429);

    // The quiet visitor, same address, untouched.
    $refresh('anon-quiet', $quiet)->assertOk();
});

test('the per-address ceiling still bounds a client rotating identities', function (): void {
    // Rekeying to the visitor must not remove the abuse cap: otherwise a
    // forged client mints a fresh anonymous id per request and the per-visitor
    // budget never binds.
    config()->set('wayfindr.widget_rate_limits.session_refresh_per_minute', 1000);
    config()->set('wayfindr.widget_rate_limits.session_refresh_per_ip_per_minute', 2);

    [$site] = refreshSessionFixture('anon-rot-1');
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-rot-2']);
    Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-rot-3']);

    $tokens = [];
    foreach (['anon-rot-1', 'anon-rot-2', 'anon-rot-3'] as $id) {
        $tokens[$id] = refreshSessionBootstrapToken($this, 'site_public_refresh', $id);
    }

    $refresh = fn (string $anonymousId) => $this->postJson('/api/widget/session', [
        'site_public_key' => 'site_public_refresh',
        'anonymous_id' => $anonymousId,
        'visitor_token' => $tokens[$anonymousId],
    ]);

    $refresh('anon-rot-1')->assertOk();
    $refresh('anon-rot-2')->assertOk();
    $refresh('anon-rot-3')->assertStatus(429);
});

test('a reused browser identity does not inherit the deleted visitor session', function (): void {
    // Deleting a visitor frees their browser identity, and the same string can
    // later name a different person. Matching on the anonymous id alone would
    // hand that genuinely new session a start from a row that no longer
    // exists -- and once an absolute cap measures it, expire them early for
    // somebody else's time.
    [$site, $original] = refreshSessionFixture();

    Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00'));

    try {
        $stale = refreshSessionBootstrapToken($this, 'site_public_refresh', 'anon-refresh');

        // The original visitor goes away and the identity is reused.
        $original->delete();
        Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-refresh']);

        Carbon::setTestNow(Carbon::parse('2026-09-17 16:00:00'));

        $fresh = $this->postJson('/api/widget/bootstrap', [
            'site_public_key' => 'site_public_refresh',
            'anonymous_id' => 'anon-refresh',
            'page_url' => 'https://docs.example.test/install',
            'visitor_token' => $stale,
        ])->assertSuccessful()->json('data.visitor.token');

        $payload = decodeVisitorSessionPayload($fresh);

        // Their session began when they arrived, not when somebody else did.
        expect(Carbon::parse($payload['session_started_at'])->format('H:i'))->toBe('16:00');
    } finally {
        Carbon::setTestNow();
    }
});
