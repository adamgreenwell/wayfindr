<?php

// An attachment URL is the one widget request that cannot carry a credential in
// a header: the file link opens in a new tab and the image preview is fetched by
// `<img src>`. The widget sets that URL as an `href` and a `src`, so whatever is
// in it lands in the DOM of the customer's own page, readable by any script
// running there. It used to be the visitor's session credentials.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentDownloadLink;
use App\Support\Attachments\AttachmentUploadService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function signedLinkFixture(): array
{
    Storage::fake('attachments');

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id])->load(['site', 'visitor']);

    $attachment = app(AttachmentUploadService::class)
        ->store($conversation, UploadedFile::fake()->create('brief.pdf', 12), $visitor);

    return compact('account', 'site', 'visitor', 'conversation', 'attachment');
}

test('a signed download link carries no visitor credential', function (): void {
    $f = signedLinkFixture();

    $url = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    expect($url)->toBeString();

    foreach (['visitor_token', 'anonymous_id'] as $credential) {
        expect(str_contains($url, $credential))
            ->toBeFalse("The signed link still carries `{$credential}`. This URL is written into the host page's DOM as an href and an img src, where any script on the customer's page can read it. Got: {$url}");
    }
});

test('a signed link streams the file', function (): void {
    $f = signedLinkFixture();

    $this->get(AttachmentDownloadLink::for($f['conversation'], $f['attachment']))
        ->assertOk();
});

test('a tampered signed link is refused', function (): void {
    $f = signedLinkFixture();
    $url = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    // Point it at a different attachment, keeping the signature.
    $other = app(AttachmentUploadService::class)
        ->store($f['conversation'], UploadedFile::fake()->create('other.pdf', 12), $f['visitor']);

    $tampered = str_replace(
        '/attachments/'.$f['attachment']->id.'/file',
        '/attachments/'.$other->id.'/file',
        $url,
    );

    $this->get($tampered)->assertForbidden();
});

test('an expired signed link is refused', function (): void {
    $f = signedLinkFixture();
    $url = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    // Quantising the MINT time rather than the expiry makes the configured TTL
    // a ceiling: a link lives at most that long, and at least that minus one
    // window. Travelling past the TTL is therefore always past the link.
    $this->travel((int) config('wayfindr.attachments.link_ttl_minutes') + 1)->minutes();

    $this->get($url)->assertForbidden();
});

test('a signed link stops working when the conversation moves to another visitor', function (): void {
    $f = signedLinkFixture();
    $url = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    $this->get($url)->assertOk();

    // What an identity merge does: the conversation ends up on another row.
    $other = Visitor::factory()->for($f['site'])->create();
    $f['conversation']->forceFill(['visitor_id' => $other->id])->save();

    $this->get($url)
        ->assertNotFound();
});

test('the link is stable within its window, so a poll does not re-render the transcript', function (): void {
    // The widget skips re-rendering when the message signature is unchanged. A
    // link that differed on every mint would either churn the whole transcript
    // every few seconds or go stale in the DOM unnoticed.
    $f = signedLinkFixture();

    // Anchored mid-window on purpose. Starting from wall-clock time, a first
    // mint in the last seconds of a window would legitimately land the second
    // in the next one -- the test would fail for a real reason that is not the
    // property under test, occasionally, which is the worst kind of red.
    $window = max(1, intdiv((int) config('wayfindr.attachments.link_ttl_minutes') * 60, 2));
    $this->travelTo(CarbonImmutable::createFromTimestamp(
        (int) (floor(now()->getTimestamp() / $window) * $window) + intdiv($window, 2)
    ));

    $first = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);
    $this->travel(30)->seconds();
    $second = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    expect($second)->toBe($first, 'Two mints moments apart produced different URLs, so every poll would change the payload the widget hashes to decide whether to re-render.');
});

test('the download link never reaches an agent or the realtime broadcast', function (): void {
    // `toPayload()` feeds the agent controller and ConversationMessageCreated.
    // This link is a visitor-scoped capability and belongs in neither.
    $f = signedLinkFixture();

    $payload = $f['attachment']->toPayload();

    expect(array_key_exists('download_url', $payload))
        ->toBeFalse('A visitor-scoped download capability was added to toPayload(), which is also sent to agents and broadcast over the websocket.');

    $keys = array_keys($payload);
    sort($keys);

    expect($keys)->toBe(['filename', 'id', 'is_image', 'mime_type', 'size_bytes', 'status']);
});

test('a signed link is relative, so an untrusted proxy cannot invalidate it', function (): void {
    // An absolute signature covers scheme and host. `temporarySignedRoute` takes
    // those from APP_URL while validation recomputes them from the request, so on
    // an install behind a TLS-terminating proxy that did not set TRUSTED_PROXIES
    // every download would 403 -- in production only, never in CI.
    $f = signedLinkFixture();

    expect(AttachmentDownloadLink::for($f['conversation'], $f['attachment']))
        ->toStartWith('/', 'The link is absolute, so its signature covers the scheme and host and will not survive an untrusted reverse proxy.');
});

test('a signed link never outlives the configured lifetime', function (): void {
    // Quantisation must not buy extra time. Minted just after a window boundary
    // -- the worst case -- the link must still be dead at the TTL.
    $f = signedLinkFixture();

    $ttl = (int) config('wayfindr.attachments.link_ttl_minutes');
    $window = max(1, intdiv($ttl * 60, 2));

    // One second past a boundary is where rounding the EXPIRY upward would have
    // handed out nearly another full window.
    $this->travelTo(CarbonImmutable::createFromTimestamp(
        (int) (floor(now()->getTimestamp() / $window) * $window) + $window + 1
    ));

    $url = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    $this->travel($ttl)->minutes();

    $this->get($url)->assertForbidden();
});

test('even the smallest lifetime leaves a rotation overlap', function (): void {
    // A window equal to the whole lifetime would let a link minted near the end
    // of one expire almost immediately, and the widget only polls every few
    // seconds -- a click in between would 403 with nothing to explain it.
    config(['wayfindr.attachments.link_ttl_minutes' => 1]);
    $f = signedLinkFixture();

    // Worst case: one second before the window rolls.
    $window = max(1, intdiv(1 * 60, 2));
    $this->travelTo(CarbonImmutable::createFromTimestamp(
        (int) (floor(now()->getTimestamp() / $window) * $window) + $window - 1
    ));

    $url = AttachmentDownloadLink::for($f['conversation'], $f['attachment']);

    // Still good a poll interval later.
    $this->travel(10)->seconds();

    $this->get($url)->assertOk();
});
