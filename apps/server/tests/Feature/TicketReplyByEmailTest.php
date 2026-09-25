<?php

use App\Enums\AccountRole;
use App\Jobs\SendConversationReplyDelivery;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationReplyDelivery;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Mail\ConversationReplyMailer;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use App\Support\Sites\SiteAvailability;
use App\Support\Sites\SiteIntake;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

/**
 * A linked ticket's page has its own reply form, and it writes the same
 * visitor-facing message the conversation page does -- but it never called the
 * mailer. A reply written there reached the widget and nobody else: not
 * somebody who wrote in by email, and not somebody the widget had promised an
 * email while the desk was away.
 *
 * Delivered through the real job and the real `array` transport rather than
 * Mail::fake(). A fake records a mailable without rendering it, and a reply
 * email that threw on every send once went unnoticed behind one.
 */
function ticketReplyByEmailAgent(Site $site): User
{
    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    return $agent;
}

function ticketReplyByEmailTicket(Conversation $conversation): Ticket
{
    return Ticket::factory()
        ->for($conversation->site->account)
        ->for($conversation->site)
        ->create(['conversation_id' => $conversation->id]);
}

/**
 * Replies from the ticket page, then runs the delivery job production queues
 * against the real `array` transport. Null when nothing was put in the outbox.
 */
function ticketReplyByEmailSend(Ticket $ticket, User $agent, string $body): ?Email
{
    Queue::fake();
    // The reply rule asks whether the install's mailer delivers; the suite's
    // own `array` mailer does not. So the reply is decided on a real transport
    // and sent, below, through the array one.
    config()->set('mail.default', 'smtp');

    test()->actingAs($agent)
        ->post(route('dashboard.tickets.replies.store', $ticket), ['message' => $body])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $delivery = ConversationReplyDelivery::query()->first();

    if ($delivery === null) {
        return null;
    }

    Queue::assertPushed(
        SendConversationReplyDelivery::class,
        fn (SendConversationReplyDelivery $job): bool => $job->uniqueId() === (string) $delivery->id,
    );

    config()->set('mail.default', 'array');
    $thrown = null;

    try {
        (new SendConversationReplyDelivery($delivery->id))->handle();
    } catch (Throwable $exception) {
        $thrown = $exception::class.': '.$exception->getMessage();
    }

    expect($thrown)->toBeNull('the ticket-page reply email could not be built: '.$thrown);

    return Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
}

test('a reply written on a linked ticket answers an email conversation by email', function (): void {
    $site = Site::factory()->for(Account::factory())->create(['inbound_address' => 'support@northwind.test']);
    $inbound = app(InboundMailRouter::class)->route(InboundMessage::fromPayload([
        'from' => 'Ada Lovelace <ada@example.test>',
        'to' => 'support@northwind.test',
        'subject' => 'My order has not arrived',
        'text' => 'It was due on Tuesday.',
        'message_id' => '<first@example.test>',
    ]));
    $conversation = $inbound->conversation;
    $ticket = ticketReplyByEmailTicket($conversation);

    $email = ticketReplyByEmailSend($ticket, ticketReplyByEmailAgent($site), "We've found it & it ships today.");

    expect($email)->not->toBeNull('a reply written on the ticket page was never emailed to a visitor who wrote in by email');
    expect($email->getTo()[0]->getAddress())->toBe('ada@example.test');
    expect(str_contains((string) $email->getTextBody(), "We've found it & it ships today."))
        ->toBeTrue("the ticket-page reply's words did not arrive verbatim:\n".$email->getTextBody());

    // The same durable outbox the conversation page writes: one row, accepted
    // by the transport, whose Message-ID is the one the email carried and the
    // one the message will be threaded against.
    $reply = $conversation->messages()->where('sender_type', User::class)->sole();
    $delivery = ConversationReplyDelivery::query()->sole();

    expect($delivery->conversation_message_id)->toBe($reply->id)
        ->and($delivery->accepted_at)->not->toBeNull()
        ->and($reply->email_message_id)->toBe($delivery->message_id)
        ->and($email->getHeaders()->get('Message-ID')->getBodyAsString())->toBe($delivery->message_id)
        ->and($email->getHeaders()->get('In-Reply-To')?->getBodyAsString())->toBe('<first@example.test>');

    $answer = app(InboundMailRouter::class)->route(InboundMessage::fromPayload([
        'from' => 'ada@example.test',
        'to' => 'support@northwind.test',
        'subject' => 'Re: My order has not arrived',
        'text' => 'Thank you.',
        'message_id' => '<answer@example.test>',
        'in_reply_to' => $delivery->message_id,
    ]));

    expect($answer?->conversation_id)
        ->toBe($conversation->id, "the visitor's answer to a ticket-page reply opened a new conversation instead of continuing this one");
});

test('a reply written on a linked ticket keeps the out-of-hours promise of an email', function (): void {
    $site = Site::factory()->for(Account::factory())->create([
        'domain' => 'northwind.test',
        'inbound_address' => null,
        'settings' => [
            'intake' => ['fields' => ['email' => SiteIntake::OPTIONAL]],
            // Support hours with no day open at all: the desk is away.
            'availability' => ['enabled' => true, 'timezone' => 'UTC', 'weekdays' => array_fill_keys(SiteAvailability::DAYS, null)],
        ],
    ]);
    $visitor = Visitor::factory()->for($site)->create();

    // Opened through the widget endpoint, so the promise is recorded by the
    // code that records it in production.
    $this->postJson('/api/conversations', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
        'visitor_email' => 'ada@example.test',
    ])->assertCreated();

    $conversation = Conversation::query()->sole();

    expect($conversation->metadata[ConversationReplyMailer::REPLY_BY_EMAIL] ?? null)->toBeTrue();

    $email = ticketReplyByEmailSend(
        ticketReplyByEmailTicket($conversation),
        ticketReplyByEmailAgent($site),
        'We are back, and it ships today.',
    );

    expect($email)->not->toBeNull('a visitor told "we will reply when we are back" was not emailed a reply written on the ticket page');
    expect($email->getTo()[0]->getAddress())->toBe('ada@example.test');
    expect(str_contains((string) $email->getTextBody(), 'We are back, and it ships today.'))
        ->toBeTrue("the ticket-page reply's words did not arrive:\n".$email->getTextBody());
});
