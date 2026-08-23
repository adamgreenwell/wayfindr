<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
