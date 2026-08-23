<?php

use App\Enums\AccountRole;
use App\Events\ConversationMessageCreated;
use App\Mail\ConversationReplyMessage;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentUploadService;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Mail is the most common support channel there is, and every ticket in
 * Wayfindr had to begin as a widget chat. An arriving email now becomes a
 * message on the right conversation, for the right person, at the right site.
 */
function mailSite(string $address = 'support@northwind.test'): Site
{
    return Site::factory()->for(Account::factory())->create(['inbound_address' => $address]);
}

function deliver(array $payload): ?ConversationMessage
{
    $message = InboundMessage::fromPayload($payload);

    return $message === null ? null : app(InboundMailRouter::class)->route($message);
}

function mailPayload(array $overrides = []): array
{
    return array_replace([
        'from' => 'Ada Lovelace <ada@example.test>',
        'to' => 'support@northwind.test',
        'subject' => 'My order has not arrived',
        'text' => 'It was due on Tuesday.',
        'message_id' => '<first@example.test>',
    ], $overrides);
}

test('an email becomes a conversation for a visitor nobody had met', function (): void {
    $site = mailSite();

    $stored = deliver(mailPayload());

    expect($stored)->not->toBeNull();

    $visitor = Visitor::query()->firstOrFail();
    $conversation = $stored->conversation;

    expect($visitor->email)->toBe('ada@example.test')
        ->and($visitor->name)->toBe('Ada Lovelace')
        // Never loaded the widget, so inventing a browser session would be a lie.
        ->and($visitor->anonymous_id)->toBeNull()
        ->and($conversation->site_id)->toBe($site->id)
        ->and($conversation->subject)->toBe('My order has not arrived')
        ->and($stored->body)->toBe('It was due on Tuesday.')
        ->and($stored->email_message_id)->toBe('<first@example.test>');
});

test('a reply lands on the conversation it is replying to, not a new one', function (): void {
    mailSite();
    $first = deliver(mailPayload());

    $second = deliver(mailPayload([
        'subject' => 'Re: My order has not arrived',
        'text' => "Still nothing.\n\nOn Tue, Support wrote:\n> We are checking.",
        'message_id' => '<second@example.test>',
        'in_reply_to' => '<first@example.test>',
    ]));

    expect(Conversation::query()->count())->toBe(1)
        ->and($second->conversation_id)->toBe($first->conversation_id)
        // And the quoted history did not come with it.
        ->and($second->body)->toBe('Still nothing.');
});

test('threading follows References when the client omits In-Reply-To', function (): void {
    // Clients disagree about which header they populate, and a thread that
    // loses one is a conversation split in two.
    mailSite();
    $first = deliver(mailPayload());

    $second = deliver(mailPayload([
        'message_id' => '<second@example.test>',
        'references' => '<older@example.test> <first@example.test>',
    ]));

    expect($second->conversation_id)->toBe($first->conversation_id);
});

test('a subject that matches is not enough to join a thread', function (): void {
    // Two unrelated "Re: Order" threads would collapse into one, and a customer
    // who edits the subject would start a second conversation about the thing
    // they are already discussing. Only the Message-ID chain is trusted.
    mailSite();
    deliver(mailPayload());

    deliver(mailPayload([
        'message_id' => '<unrelated@example.test>',
        'text' => 'Different problem entirely.',
    ]));

    expect(Conversation::query()->count())->toBe(2);
});

test('a stranger cannot thread into somebody else’s conversation by guessing an id', function (): void {
    // A Message-ID is not a secret. Threading on it alone would show a stranger
    // a transcript that is not theirs.
    mailSite();
    $first = deliver(mailPayload());

    $intruder = deliver(mailPayload([
        'from' => 'mallory@example.test',
        'message_id' => '<intruder@example.test>',
        'in_reply_to' => '<first@example.test>',
    ]));

    expect($intruder->conversation_id)->not->toBe($first->conversation_id)
        ->and(Conversation::query()->count())->toBe(2);
});

test('mail to an address nobody configured is refused rather than guessed at', function (): void {
    mailSite();

    expect(deliver(mailPayload(['to' => 'nobody@northwind.test'])))->toBeNull()
        ->and(Conversation::query()->count())->toBe(0);
});

test('an archived site stops answering its mail', function (): void {
    $site = mailSite();
    $site->forceFill(['archived_at' => now()])->save();

    expect(deliver(mailPayload()))->toBeNull();
});

test('a returning sender is the same visitor, and keeps the name they gave the widget', function (): void {
    $site = mailSite();
    $known = Visitor::factory()->for($site)->create([
        'email' => 'ada@example.test',
        'name' => 'Ada from the widget',
        'anonymous_id' => 'anon-ada',
    ]);

    deliver(mailPayload());

    expect(Visitor::query()->count())->toBe(1)
        ->and($known->fresh()->name)->toBe('Ada from the widget')
        ->and($known->fresh()->anonymous_id)->toBe('anon-ada');
});

test('a reply reopens a conversation that had been closed', function (): void {
    mailSite();
    $first = deliver(mailPayload());
    $first->conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    deliver(mailPayload([
        'message_id' => '<second@example.test>',
        'in_reply_to' => '<first@example.test>',
    ]));

    expect($first->conversation->fresh()->status)->toBe('open')
        ->and($first->conversation->fresh()->closed_at)->toBeNull();
});

test('a message with no sender is refused', function (): void {
    expect(InboundMessage::fromPayload(['to' => 'support@northwind.test', 'text' => 'hi']))->toBeNull();
});

test('an email carrying only attachments still reads as something', function (): void {
    mailSite();

    $stored = deliver(mailPayload(['text' => '']));

    expect($stored->body)->toBe('(no message text)');
});

test('provider field names are read without a class per provider', function (): void {
    // Postmark says TextBody, Mailgun says body-plain, and the difference is a
    // lookup rather than an adapter.
    mailSite();

    $postmark = deliver([
        'FromFull' => ['Email' => 'ada@example.test', 'Name' => 'Ada'],
        'To' => 'support@northwind.test',
        'Subject' => 'Postmark shape',
        'TextBody' => 'Sent through Postmark.',
        'MessageID' => '<pm@example.test>',
    ]);

    $mailgun = deliver([
        'sender' => 'bob@example.test',
        'recipient' => 'support@northwind.test',
        'subject' => 'Mailgun shape',
        'body-plain' => 'Sent through Mailgun.',
        'message-id' => '<mg@example.test>',
    ]);

    expect($postmark->body)->toBe('Sent through Postmark.')
        ->and($mailgun->body)->toBe('Sent through Mailgun.')
        ->and(Conversation::query()->count())->toBe(2);
});

function signedPost($test, array $payload, ?string $secret = 'inbound-secret')
{
    $body = json_encode($payload);

    // CONTENT_TYPE, not HTTP_CONTENT_TYPE: the framework reads the former when
    // deciding whether to parse the body as JSON, and without it $request->all()
    // is empty and every delivery looks like one with no sender.
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X_WAYFINDR_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', route('mail.inbound'), [], [], [], $server, $body);
}

test('a signed delivery becomes a conversation', function (): void {
    config()->set('wayfindr.mail.inbound_secret', 'inbound-secret');
    mailSite();

    signedPost($this, mailPayload())->assertOk()->assertJson(['message' => 'Accepted.']);

    expect(Conversation::query()->count())->toBe(1);
});

test('an unsigned or wrongly signed delivery writes nothing', function (): void {
    config()->set('wayfindr.mail.inbound_secret', 'inbound-secret');
    mailSite();

    signedPost($this, mailPayload(), null)->assertStatus(401);
    signedPost($this, mailPayload(), 'the-wrong-secret')->assertStatus(401);

    expect(Conversation::query()->count())->toBe(0);
});

test('the endpoint is closed until an operator configures a secret', function (): void {
    // An open endpoint that writes conversations is worse than one somebody has
    // to switch on, so an unconfigured install refuses rather than accepts.
    config()->set('wayfindr.mail.inbound_secret', '');
    mailSite();

    signedPost($this, mailPayload(), null)->assertNotFound();

    expect(Conversation::query()->count())->toBe(0);
});

test('a delivery Wayfindr cannot use is accepted rather than retried forever', function (): void {
    // A provider retries on a failure code. Mail for a site that does not exist
    // would retry until the provider gave up, so it is answered 200 and dropped.
    config()->set('wayfindr.mail.inbound_secret', 'inbound-secret');
    mailSite();

    signedPost($this, mailPayload(['to' => 'nobody@northwind.test']))
        ->assertOk()
        ->assertJson(['message' => 'Ignored.']);

    signedPost($this, ['to' => 'support@northwind.test', 'text' => 'no sender'])
        ->assertOk()
        ->assertJson(['message' => 'Ignored.']);

    expect(Conversation::query()->count())->toBe(0);
});

test('an agent replying to an email conversation sends an email back', function (): void {
    Mail::fake();
    $site = mailSite();
    $inbound = deliver(mailPayload());

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), [
            'body' => 'We have found it and it ships today.',
        ])
        ->assertRedirect();

    Mail::assertQueued(ConversationReplyMessage::class, function (ConversationReplyMessage $mail): bool {
        return $mail->hasTo('ada@example.test');
    });

    // The Message-ID it was sent as is on the row, so the visitor's reply can
    // be threaded against it.
    $reply = $inbound->conversation->fresh()->messages()->where('sender_type', User::class)->firstOrFail();

    expect($reply->email_message_id)->not->toBeNull();
});

test('a widget conversation is not also emailed', function (): void {
    // The visitor is already being answered where they are.
    Mail::fake();
    $site = mailSite();
    $visitor = Visitor::factory()->for($site)->create(['email' => 'inwidget@example.test']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $conversation->support_code), ['body' => 'Hello.'])
        ->assertRedirect();

    Mail::assertNothingQueued();
});

test('the visitor’s reply threads onto the agent’s email', function (): void {
    Mail::fake();
    $site = mailSite();
    $inbound = deliver(mailPayload());

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), ['body' => 'Shipping today.'])
        ->assertRedirect();

    $sentId = $inbound->conversation->fresh()->messages()
        ->where('sender_type', User::class)->firstOrFail()->email_message_id;

    $back = deliver(mailPayload([
        'message_id' => '<third@example.test>',
        'in_reply_to' => $sentId,
        'text' => 'Thank you.',
    ]));

    expect($back->conversation_id)->toBe($inbound->conversation_id)
        ->and(Conversation::query()->count())->toBe(1);
});

test('a provider retrying a delivery does not say it twice', function (): void {
    // Providers retry after a timeout or a lost 200. A retry that inserts again
    // duplicates a reply -- or, for a first email with no thread to join, opens
    // a SECOND conversation about the same question.
    mailSite();

    $first = deliver(mailPayload());
    $again = deliver(mailPayload());

    expect(Conversation::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->count())->toBe(1)
        ->and($again->id)->toBe($first->id);
});

test('an inbound message wakes the same listeners a widget message does', function (): void {
    // Without the event, email rows appear and nobody is told: no agent alert,
    // no realtime broadcast, and pending tickets stay pending.
    Event::fake([ConversationMessageCreated::class]);
    mailSite();

    $stored = deliver(mailPayload());

    Event::assertDispatched(
        ConversationMessageCreated::class,
        fn (ConversationMessageCreated $event): bool => $event->message->is($stored),
    );
});

test('an admin sets the address mail arrives at', function (): void {
    // The column existed and no form populated it, so every delivery was
    // ignored unless somebody edited the database.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['inbound_address' => null]);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.inbound-address.update', $site), ['inbound_address' => '  Support@Northwind.test '])
        ->assertRedirect();

    // Normalised, or Support@x and support@x are two addresses to the database
    // and one to every mail server.
    expect($site->fresh()->inbound_address)->toBe('support@northwind.test');

    expect(deliver(mailPayload())?->conversation->site_id)->toBe($site->id);
});

test('two sites cannot claim the same address', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    Site::factory()->for($account)->create(['inbound_address' => 'support@northwind.test']);
    $second = Site::factory()->for($account)->create(['inbound_address' => null]);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.inbound-address.update', $second), ['inbound_address' => 'SUPPORT@northwind.test'])
        ->assertSessionHasErrors('inbound_address');

    expect($second->fresh()->inbound_address)->toBeNull();
});

test('a plain agent cannot redirect a site’s mail', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['inbound_address' => null]);

    $this->actingAs($agent)
        ->put(route('dashboard.sites.inbound-address.update', $site), ['inbound_address' => 'mine@example.test'])
        ->assertForbidden();

    expect($site->fresh()->inbound_address)->toBeNull();
});

test('the files an agent attaches travel with the emailed reply', function (): void {
    // Otherwise the agent is told the reply was sent while the visitor receives
    // none of it -- and an attachment-only reply arrives as just a signature.
    Mail::fake();
    Storage::fake('attachments');
    $site = mailSite();
    $inbound = deliver(mailPayload());

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    $attachment = app(AttachmentUploadService::class)->store(
        $inbound->conversation,
        UploadedFile::fake()->createWithContent('label.pdf', '%PDF-1.4 fake'),
        $agent,
    );

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), [
            'body' => 'Here is the label.',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertRedirect();

    Mail::assertQueued(
        ConversationReplyMessage::class,
        fn (ConversationReplyMessage $mail): bool => count($mail->attachments()) === 1,
    );
});
