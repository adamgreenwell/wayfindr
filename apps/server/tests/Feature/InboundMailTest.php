<?php

use App\Enums\AccountRole;
use App\Enums\AutomationRuleEvent;
use App\Events\ConversationMessageCreated;
use App\Jobs\SendConversationReplyDelivery;
use App\Mail\ConversationReplyMessage;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationReplyDelivery;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\ConversationNeedsReply;
use App\Support\Attachments\AttachmentUploadService;
use App\Support\Conversations\LegacyOwnerSessionSweep;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

/**
 * An install whose mailer can deliver, as the reply rule reads it. The suite's
 * own mailer is `array`, which delivers nothing, and an email conversation is
 * not answered through a mailer that cannot. Set just before a reply: the sync
 * queue fires JobProcessing, which returns config to its baseline.
 */
function inboundMailDeliveringMailer(): void
{
    config()->set('mail.default', 'smtp');
}

test('an email becomes a conversation for a visitor nobody had met', function (): void {
    $site = mailSite();
    $rule = AutomationRule::factory()->for($site->account()->firstOrFail())->enabled()->create([
        'name' => 'Close new email intake',
        'event' => AutomationRuleEvent::ConversationCreated,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'not arrived'],
        ],
        'actions' => [['type' => 'set_status', 'value' => 'closed']],
    ]);

    $stored = deliver(mailPayload());

    expect($stored)->not->toBeNull();

    $visitor = Visitor::query()->firstOrFail();
    $conversation = $stored->conversation->fresh();

    expect($visitor->email)->toBe('ada@example.test')
        ->and($visitor->name)->toBe('Ada Lovelace')
        // Never loaded the widget, so inventing a browser session would be a lie.
        ->and($visitor->anonymous_id)->toBeNull()
        ->and($conversation->site_id)->toBe($site->id)
        ->and($conversation->subject)->toBe('My order has not arrived')
        // The creation rule matched and ran, but its close is withheld: the
        // email arrived with the conversation, so its sender is already waiting.
        ->and($conversation->status)->toBe('open', 'a creation rule closed an emailed conversation its sender is waiting in')
        ->and($conversation->last_message_at?->equalTo($stored->created_at))->toBeTrue()
        ->and($stored->body)->toBe('It was due on Tuesday.')
        ->and($stored->email_message_id)->toBe('<first@example.test>')
        ->and(AutomationRuleExecution::query()->sole()->automation_rule_id)->toBe($rule->id);
});

test('a creation rule cannot close an emailed conversation before its first message alerts anyone', function (): void {
    // Mail stores the conversation and its first message in one operation, and
    // announces the creation before the message. Creation rules therefore run
    // while the sender is already waiting, and a close there left the alert
    // listener with nothing open to alert about: nobody was told.
    Notification::fake();
    $site = mailSite();
    $account = $site->account()->firstOrFail();
    $assignee = User::factory()->for($account)->create();
    $bystander = User::factory()->for($account)->create();
    $rule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Triage email intake',
        'event' => AutomationRuleEvent::ConversationCreated,
        'actions' => [
            ['type' => 'assign_agent', 'value' => $assignee->id],
            ['type' => 'set_status', 'value' => 'closed'],
            ['type' => 'set_priority', 'value' => 'high'],
        ],
    ]);

    $stored = deliver(mailPayload());
    $conversation = $stored->conversation->fresh();

    expect($conversation->status)->toBe('open', 'a creation rule closed the emailed conversation its sender is waiting in')
        ->and($conversation->auditEvents()->where('action', 'conversation.closed')->exists())->toBeFalse('a creation rule recorded a close no human made on an emailed conversation')
        ->and(Notification::sent($assignee, ConversationNeedsReply::class))->toHaveCount(1, 'a creation rule close silenced the first emailed message')
        ->and(Notification::sent($bystander, ConversationNeedsReply::class))->toHaveCount(0, 'creation rules still run before the alert, so their assignee is the one alerted')
        ->and($conversation->assigned_agent_id)->toBe($assignee->id)
        ->and($conversation->priority)->toBe('high', 'withholding the close must not stop the rest of the creation rule');

    $execution = AutomationRuleExecution::query()->sole();

    expect($execution->automation_rule_id)->toBe($rule->id)
        ->and($execution->action_results)->toBe([
            ['type' => 'assign_agent', 'status' => 'applied', 'detail' => 'agent:'.$assignee->id],
            ['type' => 'set_status', 'status' => 'skipped', 'detail' => 'visitor_awaiting_reply'],
            ['type' => 'set_priority', 'status' => 'applied', 'detail' => 'normal->high'],
        ], 'the execution log does not record the withheld close, in stored order, with its reason');
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

    DB::flushQueryLog();
    DB::enableQueryLog();

    deliver(mailPayload([
        'message_id' => '<second@example.test>',
        'in_reply_to' => '<first@example.test>',
    ]));

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();

    expect($first->conversation->fresh()->status)->toBe('open')
        ->and($first->conversation->fresh()->closed_at)->toBeNull();

    if (DB::getDriverName() === 'pgsql') {
        $accountLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "accounts"')
            && str_contains($query, 'for update'));
        $siteLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "sites"')
            && str_contains($query, 'for share'));
        $conversationUpdate = $queries->search(fn (string $query): bool => str_contains($query, 'update "conversations"'));

        expect($accountLock)->toBeInt()
            ->and($siteLock)->toBeInt()
            ->and($conversationUpdate)->toBeInt()
            ->and($accountLock)->toBeLessThan($siteLock)
            ->and($siteLock)->toBeLessThan($conversationUpdate);
    }
});

test('a reply that reopens a closed conversation reaches the support team', function (): void {
    // The resolution did not hold, which is the reply that most needs an
    // answer. The alert used to be decided from a copy of the conversation
    // read while the reply was being stored, before it was reopened, so it
    // still said closed and nobody was told.
    $site = mailSite();
    $agent = User::factory()->for($site->account()->firstOrFail())->create();
    $first = deliver(mailPayload());
    // Handled and closed: the first alert is read, so a new one is owed.
    $agent->unreadNotifications->markAsRead();
    $first->conversation->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    Notification::fake();

    deliver(mailPayload([
        'text' => 'Still nothing.',
        'message_id' => '<second@example.test>',
        'in_reply_to' => '<first@example.test>',
    ]));

    expect($first->conversation->fresh()->status)->toBe('open')
        ->and(Notification::sent($agent, ConversationNeedsReply::class))->toHaveCount(1, 'an emailed reply that reopened a closed conversation alerted nobody');
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

    inboundMailDeliveringMailer();
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), [
            'body' => 'We have found it and it ships today.',
        ])
        ->assertRedirect();

    Mail::assertSent(ConversationReplyMessage::class, function (ConversationReplyMessage $mail): bool {
        return $mail->hasTo('ada@example.test');
    });

    // The Message-ID it was sent as is on the row, so the visitor's reply can
    // be threaded against it.
    $reply = $inbound->conversation->fresh()->messages()->where('sender_type', User::class)->firstOrFail();

    expect($reply->email_message_id)->not->toBeNull();
});

test('an agent reply to an email conversation is actually delivered, not only handed to a fake', function (): void {
    // Every other reply test fakes the mailer, and a fake never renders the
    // view. The view read `$message`, which the mailer overwrites with its own
    // Illuminate\Mail\Message, so every real send threw -- and no reply by email
    // was ever delivered while the suite stayed green.
    $site = mailSite();
    $inbound = deliver(mailPayload());

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    // Decided on a mailer that delivers, then sent by the job production
    // queues -- run here against the real `array` transport.
    Queue::fake();
    inboundMailDeliveringMailer();
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), [
            'body' => "We've found it and it ships today.",
        ])
        ->assertRedirect();

    config()->set('mail.default', 'array');
    $thrown = null;

    try {
        foreach (ConversationReplyDelivery::query()->pluck('id') as $deliveryId) {
            (new SendConversationReplyDelivery($deliveryId))->handle();
        }
    } catch (Throwable $exception) {
        $thrown = $exception::class.': '.$exception->getMessage();
    }

    expect($thrown)->toBeNull('The agent reply was never delivered: rendering the reply email failed: '.$thrown);

    $sent = Mail::mailer('array')->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1, 'The agent reply was never delivered: rendering the reply email failed.');

    $text = $sent->first()->getOriginalMessage()->getTextBody();

    // Plain text, so nothing may be HTML-escaped: "We&#039;ve" in an inbox is
    // the agent's words mangled.
    expect(str_contains($text, "We've found it and it ships today."))
        ->toBeTrue("The delivered email does not carry the agent's words verbatim: {$text}");

    $reply = $inbound->conversation->fresh()->messages()->where('sender_type', User::class)->firstOrFail();

    expect(ConversationReplyDelivery::query()->where('conversation_message_id', $reply->id)->value('accepted_at'))
        ->not->toBeNull();
});

test('a queue outage does not turn a durably stored agent reply into a resubmit-inducing error', function (): void {
    Event::fake([ConversationMessageCreated::class]);
    Log::spy();
    $site = mailSite();
    $inbound = deliver(mailPayload());
    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);
    $queueManager = Queue::getFacadeRoot();

    Queue::shouldReceive('connection')
        ->once()
        ->andThrow(new RuntimeException('Redis unavailable.'));

    inboundMailDeliveringMailer();

    try {
        $this->actingAs($agent)
            ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), [
                'body' => 'This reply is safely in the outbox.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    } finally {
        Queue::swap($queueManager);
    }

    $reply = $inbound->conversation->messages()->where('sender_type', User::class)->sole();
    $delivery = ConversationReplyDelivery::query()->sole();

    expect($reply->body)->toBe('This reply is safely in the outbox.')
        ->and($delivery->conversation_message_id)->toBe($reply->id)
        ->and($delivery->accepted_at)->toBeNull()
        ->and($delivery->failed_at)->toBeNull();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Conversation reply stored, but its immediate queue handoff failed.'
            && $context['conversation_reply_delivery_id'] === $delivery->id);
});

test('an email conversation is not answered through a mailer that cannot deliver, and the agent is told so', function (string $mailer): void {
    // `log` and `array` accept every message and deliver none. The out-of-hours
    // path already refused them; this channel queued the reply anyway and
    // marked it accepted, so the one visitor who can ONLY be answered by email
    // received nothing while the agent read "Reply sent."
    Mail::fake();
    $site = mailSite();
    $inbound = deliver(mailPayload());
    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    config()->set('mail.default', $mailer);
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), ['body' => 'We have found it.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ConversationReplyDelivery::query()->count())
        ->toBe(0, "an email conversation's reply was queued through the non-delivering {$mailer} mailer and recorded as sent");
    Mail::assertNothingSent();

    config()->set('mail.default', $mailer);
    $html = $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $inbound->conversation->support_code))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'data-reply-by-email="not_emailed"'))
        ->toBeTrue('the agent answering an email conversation was not told its replies are not emailed');
    expect(str_contains($html, 'They wrote in by email, but email is not set up on this install'))
        ->toBeTrue('the agent is not told why an email conversation is not answered by email');
    expect(str_contains($html, 'while the desk was away'))
        ->toBeFalse('an email conversation is explained to the agent as an out-of-hours one');
})->with(['log', 'array']);

test('a widget conversation is not also emailed', function (): void {
    // The visitor is already being answered where they are.
    Mail::fake();
    $site = mailSite();
    $visitor = Visitor::factory()->for($site)->create(['email' => 'inwidget@example.test']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    // On a mailer that delivers. On the suite's own `array` mailer nothing is
    // emailed anyway, and this would pass with every widget reply mailed.
    inboundMailDeliveringMailer();
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $conversation->support_code), ['body' => 'Hello.'])
        ->assertRedirect();

    // Sent, not queued: the delivery job sends the mailable itself, so
    // assertNothingQueued() stayed green with every widget reply emailed.
    Mail::assertNothingSent();
});

test('the visitor’s reply threads onto the agent’s email', function (): void {
    Mail::fake();
    $site = mailSite();
    $inbound = deliver(mailPayload());

    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin]);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    inboundMailDeliveringMailer();
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

test('a refused address is announced on the address field itself', function (): void {
    // The error printed under the field, attached to nothing: a screen-reader
    // user back on the form heard no control say it was invalid, or why. The
    // collision is the case that matters -- the browser's own email check
    // cannot see it, so only the server can refuse it.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    Site::factory()->for($account)->create(['inbound_address' => 'support@northwind.test']);
    $second = Site::factory()->for($account)->create(['inbound_address' => null]);

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$this->actingAs($admin)
        ->followingRedirects()
        ->from(route('dashboard.sites.show', $second))
        ->put(route('dashboard.sites.inbound-address.update', $second), ['inbound_address' => 'support@northwind.test'])
        ->assertOk()->getContent());
    $page = new DOMXPath($document);

    $control = $page->query('//input[@id="inbound_address"]')->item(0);

    expect($control)->not->toBeNull('the address control did not render; this guard is checking nothing')
        ->and($control?->getAttribute('aria-invalid'))->toBe('true', 'the refused address is not marked invalid');

    $described = array_values(array_filter(preg_split('/\s+/', (string) $control->getAttribute('aria-describedby')) ?: []));
    $errors = [];

    foreach ($described as $id) {
        $target = $page->query('//*[@id="'.$id.'"]')->item(0);

        expect($target)->not->toBeNull("the address control is described by #{$id}, which does not exist");

        if (in_array('field-error', explode(' ', (string) $target->getAttribute('class')), true)) {
            $errors[] = $target;
        }
    }

    expect($errors)->toHaveCount(1, 'the address error is not bound to the address control');
    expect(trim($errors[0]->textContent))->toBe(__('site_settings.validation.inbound_unique'));
    expect($errors[0]->parentNode->isSameNode($control->parentNode))
        ->toBeTrue('the address error is printed away from its control');
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

    inboundMailDeliveringMailer();
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $inbound->conversation->support_code), [
            'body' => 'Here is the label.',
            'attachment_ids' => [$attachment->id],
        ])
        ->assertRedirect();

    Mail::assertSent(
        ConversationReplyMessage::class,
        fn (ConversationReplyMessage $mail): bool => count($mail->attachments()) === 1,
    );
});

test('an emailed conversation records that no widget session owns it', function (): void {
    $site = mailSite();

    deliver(mailPayload());

    $conversation = Conversation::query()->where('site_id', $site->id)->sole();

    // Not null. A conversation belongs to the widget session that opened it, and
    // there is no widget session behind an email -- but null is the state that
    // means "a path that should have recorded one did not", and the sweep that
    // closes the upgrade window claims nulls as predating the control. Claimed,
    // this row would become reachable by any earlier session of that visitor.
    expect($conversation->owner_session_id)->toBe(Conversation::NO_OWNER_SESSION);

    LegacyOwnerSessionSweep::run();

    expect($conversation->refresh()->owner_session_id)
        ->toBe(Conversation::NO_OWNER_SESSION, 'The sweep must not claim a row that states nobody owns it.');
});
