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

    // Quantising the expiry means a link lives for at least the TTL and at most
    // the TTL plus one window (half the TTL). Travel past that ceiling, not past
    // the TTL, or the test lands inside a link's legitimate life.
    $ttl = (int) config('wayfindr.attachments.link_ttl_minutes');
    $this->travel($ttl + (int) floor($ttl / 2) + 5)->minutes();

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
    $window = max(1, (int) floor((int) config('wayfindr.attachments.link_ttl_minutes') / 2)) * 60;
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
