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
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
