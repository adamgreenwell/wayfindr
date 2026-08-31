<?php

// #799: the inbound endpoint accepted exactly one authentication scheme, and
// no mail provider emits it.
//
// It wanted `X-Wayfindr-Signature: sha256=<HMAC-SHA256 of the raw body>`.
// Mailgun signs a timestamp and token pair with its own key. Postmark computes
// no customer HMAC over anything. So an operator who set the secret, mapped the
// address and pointed a native webhook at `/api/mail/inbound` got 401 on every
// delivery, and the only way to use the channel was to run an unshipped
// re-signing proxy in front of it.
//
// The endpoint now verifies what the provider actually sends, chosen by config.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function inboundSite(string $address = 'support@northwind.test'): Site
{
    return Site::factory()->for(Account::factory())->create(['inbound_address' => $address]);
}

function inboundPayload(array $overrides = []): array
{
    return array_merge([
        'from' => 'Dana Scully <dana@example.test>',
        'to' => 'support@northwind.test',
        'subject' => 'Cannot sign in',
        'text' => 'The reset link does not arrive.',
    ], $overrides);
}

test('the wayfindr scheme still works and stays the default', function (): void {
    // Nobody upgrading has to change anything: an install already re-signing
    // through a proxy keeps working exactly as before.
    config()->set('wayfindr.mail.inbound_secret', 'shhh');
    inboundSite();

    $payload = inboundPayload();
    $body = json_encode($payload);

    test()->call(
        'POST',
        '/api/mail/inbound',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WAYFINDR_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'shhh'),
        ],
        $body
    )->assertOk()->assertJsonPath('message', 'Accepted.');
});

test('a mailgun delivery is accepted on mailgun terms', function (): void {
    // Mailgun signs `timestamp . token` with the signing key and sends all
    // three in the body. Nothing it sends is an HMAC over the body, which is
    // why the old check could never pass.
    config()->set('wayfindr.mail.inbound_secret', 'mailgun-signing-key');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $timestamp = (string) time();
    $token = 'a-token-from-mailgun';

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $timestamp,
        'token' => $token,
        'signature' => hash_hmac('sha256', $timestamp.$token, 'mailgun-signing-key'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');
});

test('a mailgun delivery signed with the wrong key is refused', function (): void {
    config()->set('wayfindr.mail.inbound_secret', 'mailgun-signing-key');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $timestamp = (string) time();

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $timestamp,
        'token' => 'a-token-from-mailgun',
        'signature' => hash_hmac('sha256', $timestamp.'a-token-from-mailgun', 'not-the-key'),
    ]))->assertStatus(401);
});

test('a mailgun delivery whose timestamp is old is refused', function (): void {
    // A valid signature is replayable for ever without this: the same body,
    // token and signature stay valid, so anyone who captures one delivery can
    // post it back indefinitely. Mailgun sends the timestamp precisely so the
    // receiver can bound that window.
    config()->set('wayfindr.mail.inbound_secret', 'mailgun-signing-key');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $timestamp = (string) (time() - 3600);
    $token = 'a-token-from-mailgun';

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $timestamp,
        'token' => $token,
        'signature' => hash_hmac('sha256', $timestamp.$token, 'mailgun-signing-key'),
    ]))->assertStatus(401);
});

test('a postmark delivery is accepted on the credentials postmark can actually send', function (): void {
    // Postmark computes no HMAC for the customer at all. What it does offer is
    // HTTP Basic credentials on the webhook URL, so that is what is checked --
    // naming the scheme honestly rather than pretending there is a signature.
    config()->set('wayfindr.mail.inbound_secret', 'postmark-webhook-password');
    config()->set('wayfindr.mail.inbound_provider', 'postmark');
    inboundSite();

    test()->withHeaders([
        'Authorization' => 'Basic '.base64_encode('wayfindr:postmark-webhook-password'),
    ])->postJson('/api/mail/inbound', inboundPayload([
        'From' => 'Dana Scully <dana@example.test>',
        'To' => 'support@northwind.test',
        'Subject' => 'Cannot sign in',
        'TextBody' => 'The reset link does not arrive.',
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');
});

test('a postmark delivery with the wrong password is refused', function (): void {
    config()->set('wayfindr.mail.inbound_secret', 'postmark-webhook-password');
    config()->set('wayfindr.mail.inbound_provider', 'postmark');
    inboundSite();

    test()->withHeaders([
        'Authorization' => 'Basic '.base64_encode('wayfindr:wrong'),
    ])->postJson('/api/mail/inbound', inboundPayload())->assertStatus(401);
});

test('an unsigned delivery is refused whatever the provider', function (): void {
    foreach (['wayfindr', 'mailgun', 'postmark'] as $provider) {
        config()->set('wayfindr.mail.inbound_secret', 'shhh');
        config()->set('wayfindr.mail.inbound_provider', $provider);

        test()->postJson('/api/mail/inbound', inboundPayload())
            ->assertStatus(401, "an unsigned delivery was accepted for provider {$provider}");
    }
});

test('the channel stays off when no secret is set, whatever the provider', function (): void {
    // The endpoint must not stand open just because a provider is named.
    config()->set('wayfindr.mail.inbound_secret', '');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');

    test()->postJson('/api/mail/inbound', inboundPayload())->assertStatus(404);
});

test('an unknown provider refuses everything rather than falling open', function (): void {
    // A typo in the config is not permission to accept unverified mail.
    config()->set('wayfindr.mail.inbound_secret', 'shhh');
    config()->set('wayfindr.mail.inbound_provider', 'mailgnu');
    inboundSite();

    $body = json_encode(inboundPayload());

    test()->call('POST', '/api/mail/inbound', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WAYFINDR_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'shhh'),
    ], $body)->assertStatus(401);
});

test('a mailgun tuple cannot be replayed with a different message', function (): void {
    // Mailgun's HMAC covers `timestamp . token` and NOT the body, so a valid
    // tuple authenticates any payload at all for as long as it stays fresh.
    // Anybody who obtains one delivery -- a log line, a proxy, a retry they can
    // see -- can post arbitrary mail as anyone for the rest of that window.
    //
    // The age bound alone does not close it. The token is single-use, and
    // claiming it has to be atomic, or two concurrent replays both win.
    config()->set('wayfindr.mail.inbound_secret', 'mailgun-signing-key');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $timestamp = (string) time();
    $token = 'single-use-token';
    $signature = hash_hmac('sha256', $timestamp.$token, 'mailgun-signing-key');
    $tuple = ['timestamp' => $timestamp, 'token' => $token, 'signature' => $signature];

    test()->postJson('/api/mail/inbound', inboundPayload($tuple))
        ->assertOk()->assertJsonPath('message', 'Accepted.');

    // The same tuple, a different sender and a different body.
    test()->postJson('/api/mail/inbound', inboundPayload($tuple + [
        'from' => 'Attacker <mallory@example.test>',
        'text' => 'Please reset the password for the admin account.',
    ]))->assertStatus(401);
});

test('a mailgun route delivers its attachments rather than dropping them', function (): void {
    // A Mailgun ROUTE posts attachments as multipart files -- `attachment-1`,
    // `attachment-2` -- not as the base64 array `InboundMessage` reads. Left
    // unadapted the endpoint answers `Accepted.`, which also stops the provider
    // retrying, while the file is silently gone. Losing a customer's screenshot
    // and reporting success is worse than refusing the delivery.
    config()->set('wayfindr.mail.inbound_secret', 'mailgun-signing-key');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $timestamp = (string) time();
    $token = 'attachment-token';

    test()->post('/api/mail/inbound', inboundPayload([
        'timestamp' => $timestamp,
        'token' => $token,
        'signature' => hash_hmac('sha256', $timestamp.$token, 'mailgun-signing-key'),
        'attachment-count' => '1',
        // A REAL png. The pipeline sniffs the type from the bytes rather than
        // trusting the header, so a zero-filled fake is rejected on its own
        // merits and the test would pass or fail for the wrong reason.
        'attachment-1' => UploadedFile::fake()->image('screenshot.png'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');

    $message = ConversationMessage::query()->latest('id')->first();

    expect($message)->not->toBeNull('no message was created, so this proves nothing');
    expect($message->attachments()->count())->toBe(1, 'the attached file was accepted and then discarded');
});

test('a mailgun reply joins its conversation instead of starting a new one', function (): void {
    // Mailgun does not put threading headers at the top level. They are inside
    // `message-headers`, a JSON array of [name, value] pairs -- so
    // `threadCandidates()` saw nothing and every customer reply opened a NEW
    // conversation, which is the one thing the email channel exists to avoid.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    $site = inboundSite();

    $deliver = function (array $extra) use (&$deliver) {
        $ts = (string) time();
        $tok = 'tok-'.bin2hex(random_bytes(6));

        return test()->postJson('/api/mail/inbound', inboundPayload(array_merge([
            'timestamp' => $ts,
            'token' => $tok,
            'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
        ], $extra)));
    };

    $deliver([
        'message-headers' => json_encode([['Message-Id', '<first@example.test>']]),
    ])->assertOk()->assertJsonPath('message', 'Accepted.');

    $first = Conversation::query()->latest('id')->first();

    $deliver([
        'text' => 'Still not working.',
        'message-headers' => json_encode([
            ['Message-Id', '<second@example.test>'],
            ['In-Reply-To', '<first@example.test>'],
        ]),
    ])->assertOk()->assertJsonPath('message', 'Accepted.');

    expect(Conversation::query()->count())
        ->toBe(1, 'the reply opened a second conversation instead of joining the first');
    expect($first->fresh()->messages()->count())->toBe(2);
});

test('a postmark reply joins its conversation too', function (): void {
    // Postmark carries them in a `Headers` array of {Name, Value}.
    config()->set('wayfindr.mail.inbound_secret', 'pw');
    config()->set('wayfindr.mail.inbound_provider', 'postmark');
    inboundSite();

    $auth = ['Authorization' => 'Basic '.base64_encode('wayfindr:pw')];

    test()->withHeaders($auth)->postJson('/api/mail/inbound', inboundPayload([
        'Headers' => [['Name' => 'Message-ID', 'Value' => '<pm-first@example.test>']],
    ]))->assertOk();

    test()->withHeaders($auth)->postJson('/api/mail/inbound', inboundPayload([
        'text' => 'Following up.',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<pm-second@example.test>'],
            ['Name' => 'In-Reply-To', 'Value' => '<pm-first@example.test>'],
        ],
    ]))->assertOk();

    expect(Conversation::query()->count())
        ->toBe(1, 'the reply opened a second conversation instead of joining the first');
});

test('a broken upload is refused rather than accepted and dropped', function (): void {
    // An attachment larger than PHP's own `upload_max_filesize` arrives as an
    // UploadedFile whose `isValid()` is false while the rest of the multipart
    // body parses fine. Skipping it and answering `Accepted.` stops Mailgun
    // retrying and loses the file -- the same failure this PR just fixed for
    // the field-name mismatch, on a different path.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-broken';

    $broken = new UploadedFile(
        tempnam(sys_get_temp_dir(), 'wf'),
        'too-big.png',
        'image/png',
        UPLOAD_ERR_INI_SIZE,
        true
    );

    test()->post('/api/mail/inbound', inboundPayload([
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
        'attachment-count' => '1',
        'attachment-1' => $broken,
    ]))->assertStatus(422);
});

test('the retry after a refused attachment is accepted, not refused as a replay', function (): void {
    // The two fixes in this PR collide unless the claim is given back. The
    // token is single-use so a captured tuple cannot forge a second message;
    // the 422 exists so a broken attachment is retried rather than dropped.
    // Together, without a release, the retry Mailgun sends carries the SAME
    // signature, finds the token already spent, and gets 401 -- and once the
    // 300-second window passes there is no way back. The 422 that exists to
    // save the attachment would be what loses the entire message.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-retried';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    $broken = new UploadedFile(
        tempnam(sys_get_temp_dir(), 'wf'),
        'too-big.png',
        'image/png',
        UPLOAD_ERR_INI_SIZE,
        true
    );

    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => $broken,
    ]))->assertStatus(422);

    // Mailgun retries the same signed delivery, this time uploaded intact.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('now-fine.png'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');

    // And the claim is spent again by the delivery that succeeded, so the
    // release did not reopen the forgery window it was closing.
    test()->postJson('/api/mail/inbound', inboundPayload($tuple))->assertStatus(401);
});

test('the token survives a failed write, and the retry is accepted', function (): void {
    // The narrow version of this fix released the claim on the ONE failure it
    // could see -- the unusable upload. A write that fails two frames deeper
    // (storage rejecting a file, the database going away) never reached that
    // line, so the token stayed spent and Mailgun's retry got 401 until the
    // freshness window closed on it. The rule has to be about the outcome, not
    // about the branch that produced it.
    //
    // Faulted for real rather than mocked: `InboundMailRouter` is final, and a
    // dropped table reproduces the reported shape -- a throw from inside the
    // routing transaction, well past the point the controller can see.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-failed-write';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    // Renamed rather than dropped, so putting it back restores the real
    // schema rather than a stub the migration would decline to re-fill.
    Schema::rename('conversation_messages', 'conversation_messages_away');

    test()->postJson('/api/mail/inbound', inboundPayload($tuple))->assertStatus(500);

    // Fixed, the way an operator would, and the provider retries the delivery
    // it never got an answer for.
    Schema::rename('conversation_messages_away', 'conversation_messages');

    test()->postJson('/api/mail/inbound', inboundPayload($tuple))
        ->assertOk()
        ->assertJsonPath('message', 'Accepted.');
});

test('a freed claim cannot be spent on a different message', function (): void {
    // Releasing on any failure is right for retries and wrong on its own. The
    // throw may land AFTER the message was committed -- a broadcast or listener
    // failing past the transaction -- and Mailgun's HMAC covers only the
    // timestamp and token. A claim simply forgotten would therefore hand
    // anybody holding that tuple a forgery window: same signature, different
    // sender and body, accepted.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-bound';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    Schema::rename('conversation_messages', 'conversation_messages_away');
    test()->postJson('/api/mail/inbound', inboundPayload($tuple))->assertStatus(500);
    Schema::rename('conversation_messages_away', 'conversation_messages');

    // The claim is back, but it belongs to that message. A different sender and
    // body on the same tuple is a forgery, not a retry.
    test()->postJson('/api/mail/inbound', inboundPayload($tuple + [
        'from' => 'attacker@example.test',
        'body-plain' => 'Please reset the password on this account.',
    ]))->assertStatus(401);

    // The real retry still works.
    test()->postJson('/api/mail/inbound', inboundPayload($tuple))
        ->assertOk()
        ->assertJsonPath('message', 'Accepted.');
});

test('a retry whose attachment changed is still the same message', function (): void {
    // The fingerprint covers the message, not the bytes. The retry this whole
    // mechanism exists for is usually one whose attachment CHANGED -- refused
    // because the upload did not complete, retried with the intact file -- so
    // fingerprinting the attachment would refuse the delivery it was built to
    // save.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-changed-file';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    $broken = new UploadedFile(
        tempnam(sys_get_temp_dir(), 'wf'),
        'too-big.png',
        'image/png',
        UPLOAD_ERR_INI_SIZE,
        true
    );

    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => $broken,
    ]))->assertStatus(422);

    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('now-fine.png'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');
});
