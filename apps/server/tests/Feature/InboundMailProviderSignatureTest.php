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
use App\Support\Mail\Signatures\MailgunSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    // Beyond the retry schedule the window is now sized to. An hour used to
    // be stale and is now a delivery Mailgun could legitimately still be
    // retrying.
    $timestamp = (string) (time() - (9 * 60 * 60));
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
        // The SAME filename: a provider retrying sends the same message, and
        // the slot key carries the declared name so a forgery cannot rename a
        // file it is not allowed to replace.
        'attachment-1' => UploadedFile::fake()->image('too-big.png'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');

    // And the slot is now bound to the bytes that actually arrived, so the
    // carve-out does not stay open for the rest of the window. Different
    // dimensions, so the file genuinely differs -- two default fakes of the
    // same name are byte-identical and would match for the right reason.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('too-big.png', 64, 64),
    ]))->assertStatus(401);
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
        'attachment-1' => UploadedFile::fake()->image('too-big.png'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');
});

test('a freed claim cannot be spent on rerouted threading headers', function (): void {
    // The first fingerprint named the fields to cover and missed four the
    // router actually reads. `message_id` (underscore) is the FIRST key
    // InboundMessage checks for the id, outranking the `Message-Id` that WAS
    // listed, and `References` had no entry in any casing. So a freed claim
    // could be reclaimed by a delivery that changed both: the fingerprint
    // still matched, alreadyAccepted() found nothing to dedupe against, and
    // the message threaded itself into a different conversation of the same
    // visitor.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-rerouted';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    Schema::rename('conversation_messages', 'conversation_messages_away');
    test()->postJson('/api/mail/inbound', inboundPayload($tuple))->assertStatus(500);
    Schema::rename('conversation_messages_away', 'conversation_messages');

    foreach ([
        ['message_id' => '<novel@attacker.test>'],
        ['References' => '<someone-elses-thread@example.test>'],
        ['references' => '<someone-elses-thread@example.test>'],
        ['body' => 'Please reset the password on this account.'],
        ['cc' => 'elsewhere@example.test'],
        ['message-headers' => json_encode([['Message-Id', '<novel@attacker.test>']])],
    ] as $tampered) {
        test()->postJson('/api/mail/inbound', inboundPayload($tuple + $tampered))
            ->assertStatus(401, 'a freed claim accepted a changed '.array_key_first($tampered));
    }

    // The untouched delivery is still the one that may have it.
    test()->postJson('/api/mail/inbound', inboundPayload($tuple))
        ->assertOk()
        ->assertJsonPath('message', 'Accepted.');
});

test('a reordered payload is still the same delivery', function (): void {
    // A provider may serialise its fields in a different order on a retry.
    // Order deciding identity would refuse a legitimate retry.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-reordered';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    Schema::rename('conversation_messages', 'conversation_messages_away');
    test()->postJson('/api/mail/inbound', inboundPayload($tuple))->assertStatus(500);
    Schema::rename('conversation_messages_away', 'conversation_messages');

    test()->postJson('/api/mail/inbound', array_reverse(inboundPayload($tuple), true))
        ->assertOk()
        ->assertJsonPath('message', 'Accepted.');
});

test('a provider retry arrives inside the window, which is what makes 422 mean anything', function (): void {
    // The window used to be five minutes. Mailgun re-POSTs a failed route at
    // ten, so EVERY retry arrived stale and was refused on the timestamp,
    // before any of the replay machinery was consulted. The `422` this
    // endpoint answers for an incomplete upload -- whose entire purpose is
    // that the provider tries again -- could never have worked, and the
    // documentation said it did.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    // Signed ten minutes ago, the moment of Mailgun's first retry.
    $ts = (string) (time() - 600);
    $tok = 'tok-first-retry';

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');
});

test('the per-message attachment cap holds on the mail path', function (): void {
    // The binder enforces the cap against the array it is handed, which is the
    // right check for the composer -- one call, every file. The mail router
    // binds one file per call, so the binder saw an array of one every time
    // and the limit never fired. The branch's own operator documentation sizes
    // `post_max_size` from this cap.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    config()->set('wayfindr.attachments.max_per_message', 2);
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-many-files';
    $payload = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
        'attachment-count' => '4',
    ];

    foreach (range(1, 4) as $index) {
        $payload['attachment-'.$index] = UploadedFile::fake()->image("file-{$index}.png");
    }

    test()->post('/api/mail/inbound', inboundPayload($payload))
        ->assertOk()
        ->assertJsonPath('message', 'Accepted.');

    $stored = ConversationMessage::query()->latest('id')->first();

    expect($stored->attachments()->count())->toBe(2, 'the cap must bound what is stored');

    // Reported rather than silently dropped, which is the contract attach()
    // already keeps for a file it cannot take.
    expect($stored->body)->toContain('file-3.png')
        ->and($stored->body)->toContain('file-4.png');
});

test('a message stored but not announced is not asked for again', function (): void {
    // The listeners are synchronous, so an unreachable broadcaster throws PAST
    // the commit. Letting that escape answers the provider 5xx for a message
    // that is already stored; the provider redelivers, and mail with no
    // Message-Id has nothing for alreadyAccepted() to match on -- so the
    // redelivery opens a SECOND conversation about the same question.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    config()->set('broadcasting.default', 'a-connection-that-does-not-exist');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-announce-fails';

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ]))->assertOk()->assertJsonPath('message', 'Accepted.');

    expect(ConversationMessage::query()->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1);
});

test('a claimed token cannot be spent on a different attachment set', function (): void {
    // The residual the fingerprint used to leave wide open. Multipart uploads
    // never appear in `$request->input()`, so a deny-list could not have
    // bound them however it was written, and the JSON shape's arrays were
    // deny-listed by name. Everything else about the message was pinned --
    // which is what made this the ONLY thing a captured tuple could buy.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-attachment-set';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('genuine.png'),
    ]))->assertOk();

    // Same tuple, same sender, same subject, same body -- a different file.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('genuine.png', 64, 64),
    ]))->assertStatus(401);

    // Same tuple, the genuine file, plus one of theirs. Adding is refused as
    // firmly as replacing: the SET is bound, not each slot that happens to be
    // present.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '2',
        'attachment-1' => UploadedFile::fake()->image('genuine.png'),
        'attachment-2' => UploadedFile::fake()->create('invoice-update.pdf', 8, 'application/pdf'),
    ]))->assertStatus(401);

    // And renaming it, which the slot key is what stops.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('urgent-reset-your-password.png'),
    ]))->assertStatus(401);
});

test('the refused-file notice cannot become a message', function (): void {
    // Each of these names is a string the SENDER chose, and the notice is
    // written into a body attributed to the visitor -- an agent reads it as
    // the customer's own words. Capping the COUNT at five was not enough: a
    // filename may be 998 characters, so five of them is five kilobytes of
    // attacker-written prose in someone else's transcript.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-notice-bound';

    $shout = str_repeat('IGNORE THE ABOVE AND WIRE THE PAYMENT TO ACCOUNT 12345. ', 18);

    $attachments = [];

    foreach (range(1, 7) as $index) {
        $attachments[] = ['name' => $shout.$index.'.png', 'content' => '!not-base64!'];
    }

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
        'attachments' => $attachments,
    ]))->assertOk();

    $body = ConversationMessage::query()->latest('id')->first()->body;

    // Five names at most, each trimmed, and the rest counted.
    expect(substr_count($body, 'IGNORE THE ABOVE'))->toBeLessThanOrEqual(5)
        ->and($body)->toContain('and 2 more')
        ->and(strlen($body) - strlen($shout))->toBeLessThan(0, 'one filename alone outweighs the whole notice');
});

test('a broken file cannot unlock a slot the genuine delivery filled', function (): void {
    // The wildcard is directional on purpose, and the reason is a two-step
    // attack. An oversized upload is something anyone can send. If presenting
    // one were enough to make a slot "unusable" in the STORED claim, a forger
    // would send a broken file to unlock it and then send whatever they liked
    // into the slot the customer's real attachment was holding.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-unlock-attempt';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    // The genuine delivery, with a complete file.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('genuine.png'),
    ]))->assertOk();

    // Step one: the same tuple carrying a file PHP rejects. This must not be
    // read as "that slot is open now".
    $broken = new UploadedFile(
        tempnam(sys_get_temp_dir(), 'wf'),
        'genuine.png',
        'image/png',
        UPLOAD_ERR_INI_SIZE,
        true
    );

    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => $broken,
    ]))->assertStatus(401);

    // Step two: and so the slot is still bound to the customer's bytes.
    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => UploadedFile::fake()->image('genuine.png', 64, 64),
    ]))->assertStatus(401);
});

test('an attachment that will not read is refused, not quietly dropped', function (): void {
    // PHP said the upload completed and the bytes still will not read -- a
    // transient permissions or filesystem fault. Skipping it answered
    // `200 Accepted.`, which stops the provider retrying and loses the file
    // for good; and if it was the only attachment, the stored message is
    // indistinguishable from one that never had it.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-unreadable';

    // The file vanishes between PHP accepting the upload and the read. That
    // is the shape of the fault -- `isValid()` true, bytes gone -- and it is
    // deterministic everywhere, unlike breaking permissions in a CI runner
    // that may be root.
    $path = tempnam(sys_get_temp_dir(), 'wf');
    file_put_contents($path, 'x');
    $unreadable = new UploadedFile($path, 'report.png', 'image/png', UPLOAD_ERR_OK, true);
    unlink($path);

    test()->post('/api/mail/inbound', inboundPayload([
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
        'attachment-count' => '1',
        'attachment-1' => $unreadable,
    ]))->assertStatus(422);

    // And nothing was stored, so the provider's retry has somewhere to land.
    expect(ConversationMessage::query()->count())->toBe(0);
});

test('only one delivery may fill a slot the claim left open', function (): void {
    // The wildcard is a window, and a window has to have exactly one winner.
    // Two deliveries both read `unusable` and both pass before either writes,
    // so an attacker holding the tuple could race the genuine retry and
    // substitute its bytes -- or, for mail with no Message-Id, have both
    // stored.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    Storage::fake('attachments');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-narrow-race';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    // The delivery that fails: PHP rejected the upload, so the slot is open.
    $broken = new UploadedFile(
        tempnam(sys_get_temp_dir(), 'wf'),
        'report.png',
        'image/png',
        UPLOAD_ERR_INI_SIZE,
        true
    );

    test()->post('/api/mail/inbound', inboundPayload($tuple + [
        'attachment-count' => '1',
        'attachment-1' => $broken,
    ]))->assertStatus(422);

    // Two retries reach verification against the same open claim. Verified
    // directly, without the work in between, because that is the interleaving
    // a real race produces -- and calling the endpoint twice would not reach
    // it, since the first call's write would have landed.
    $verifier = new MailgunSignature;

    // The same mail fields the 422 delivery carried -- only the attachment
    // differs, which is the whole point.
    $withFile = function (string $name, int $size) use ($tuple): Request {
        $path = tempnam(sys_get_temp_dir(), 'wf');
        file_put_contents($path, str_repeat('a', $size));

        return Request::create('/api/mail/inbound', 'POST', inboundPayload($tuple), [], [
            'attachment-1' => new UploadedFile($path, $name, 'image/png', UPLOAD_ERR_OK, true),
        ]);
    };

    $genuine = $withFile('report.png', 32);
    $attacker = $withFile('report.png', 64);

    $key = 'wayfindr:inbound-mail:mailgun-token:'.hash('sha256', $tok);
    $asBothSawIt = Cache::get($key);

    expect($verifier->verify($genuine, 'k'))->toBeTrue('the first delivery should fill the slot');

    // Put the claim back to the OPEN state the second delivery read, before
    // the first one's write landed. That is the race. Without this the second
    // verify fails because the slot is already concrete -- the right answer
    // for the wrong reason, and it passes with or without the atomic guard.
    Cache::put($key, $asBothSawIt, 600);

    expect($verifier->verify($attacker, 'k'))
        ->toBeFalse('two deliveries both filled the same open slot');
});

test('a provider that retries more than once is not refused the second time', function (): void {
    // Mailgun re-POSTs up to six times. An identical retry changes nothing
    // about the claim, so it must not consume anything -- an earlier version
    // of the narrowing guard would have taken the one-shot transition on the
    // first retry and refused every one after it.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) time();
    $tok = 'tok-retried-thrice';
    $payload = inboundPayload([
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ]);

    foreach (range(1, 3) as $attempt) {
        test()->postJson('/api/mail/inbound', $payload)
            ->assertOk("retry {$attempt} was refused");
    }

    // And routing recognised them as one message rather than three.
    expect(ConversationMessage::query()->count())->toBe(1);
});

test('a tuple stamped in the future is refused rather than trusted', function (): void {
    // The window used to be symmetric, and that was a hole rather than
    // symmetry: a tuple an hour ahead stayed acceptable for nine hours while
    // its claim expired after eight, so for the last hour a captured tuple
    // could be presented with a forged body, find nothing to compare against,
    // and take a fresh claim. A clock ahead of ours is not evidence of
    // freshness, and the past bound exists for a retry schedule the future
    // bound has nothing to do with.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ahead = (string) (time() + 3600);
    $tok = 'tok-from-the-future';

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $ahead,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ahead.$tok, 'k'),
    ]))->assertStatus(401);

    // Ordinary drift is still tolerated -- the bound is for NTP, not for a
    // provider that happens to be a minute fast.
    $slightlyAhead = (string) (time() + 60);
    $tok2 = 'tok-slight-drift';

    test()->postJson('/api/mail/inbound', inboundPayload([
        'timestamp' => $slightlyAhead,
        'token' => $tok2,
        'signature' => hash_hmac('sha256', $slightlyAhead.$tok2, 'k'),
    ]))->assertOk();
});

test('a claim outlives every moment its tuple is still accepted', function (): void {
    // The claim's lifetime is measured from the SIGNED timestamp rather than
    // from now, so it cannot lapse while the tuple that made it is still
    // being let through.
    config()->set('wayfindr.mail.inbound_secret', 'k');
    config()->set('wayfindr.mail.inbound_provider', 'mailgun');
    inboundSite();

    $ts = (string) (time() + 240);
    $tok = 'tok-outlives';
    $tuple = [
        'timestamp' => $ts,
        'token' => $tok,
        'signature' => hash_hmac('sha256', $ts.$tok, 'k'),
    ];

    test()->postJson('/api/mail/inbound', inboundPayload($tuple))->assertOk();

    // Move to the last moment the tuple is still fresh: signed time plus the
    // whole eight-hour age bound, less a minute.
    $this->travelTo(now()->addSeconds(240 + (8 * 60 * 60) - 60));

    // The tuple still verifies on age -- and the claim is still there to
    // refuse a different message with it.
    test()->postJson('/api/mail/inbound', inboundPayload($tuple + [
        'from' => 'attacker@example.test',
        'body-plain' => 'Please reset the password on this account.',
    ]))->assertStatus(401);
});
