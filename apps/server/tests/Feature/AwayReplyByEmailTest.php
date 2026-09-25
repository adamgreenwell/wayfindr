<?php

use App\Enums\AccountRole;
use App\Jobs\SendConversationReplyDelivery;
use App\Mail\ConversationReplyMessage;
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
 * Out of hours the widget requires an email -- "the only way back to
 * somebody" -- and tells the visitor we will reply when we are back. The reply
 * went only to the widget, so the promise was kept for nobody who had closed
 * the tab. It now goes to the address they left as well, and the agent is told
 * so beside the reply box.
 */
function awayReplyByEmailSite(array $overrides = [], bool $away = true): Site
{
    return Site::factory()->for(Account::factory())->create(array_replace([
        'domain' => 'northwind.test',
        'inbound_address' => null,
        'settings' => [
            'intake' => ['fields' => ['email' => SiteIntake::OPTIONAL]],
            // Keep support hours, with no day open at all: the desk is away.
            'availability' => $away
                ? ['enabled' => true, 'timezone' => 'UTC', 'weekdays' => array_fill_keys(SiteAvailability::DAYS, null)]
                : [],
        ],
    ], $overrides));
}

/**
 * Opens a conversation the way the widget does, through the public endpoint,
 * so the flag is set by the code that sets it in production.
 */
function awayReplyByEmailOpen(Site $site, array $payload = [], ?Visitor $visitor = null): Conversation
{
    $visitor ??= Visitor::factory()->for($site)->create();

    test()->postJson('/api/conversations', array_merge([
        'site_public_key' => $site->public_key,
        'anonymous_id' => $visitor->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($site, $visitor),
        'visitor_email' => 'ada@example.test',
    ], $payload))->assertCreated();

    return Conversation::query()->latest('id')->firstOrFail();
}

function awayReplyByEmailAgent(Site $site, array $attributes = []): User
{
    $agent = User::factory()->for($site->account)->create(['account_role' => AccountRole::Admin] + $attributes);
    $site->supportAgents()->syncWithoutDetaching($agent->id);

    return $agent;
}

function awayReplyByEmailReply(Conversation $conversation, User $agent, string $body = 'We are back, and it ships today.'): void
{
    test()->actingAs($agent)
        ->post(route('dashboard.conversations.messages.store', $conversation->support_code), ['body' => $body])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
}

/**
 * The install's mailer, as an operator would have set it. Re-applied before
 * each reply: the sync queue fires JobProcessing, which re-applies operator
 * settings and returns config to its baseline.
 */
function awayReplyByEmailMailer(string $mailer = 'smtp'): void
{
    config()->set('mail.default', $mailer);
}

test('an agent reply to a conversation opened while the desk was away is emailed to the address the visitor left', function (): void {
    Mail::fake();
    $site = awayReplyByEmailSite();
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer();
    awayReplyByEmailReply($conversation, $agent);

    expect(Mail::sent(ConversationReplyMessage::class, fn (ConversationReplyMessage $mail): bool => $mail->hasTo('ada@example.test'))->count())
        ->toBe(1, 'a visitor told "we will reply when we are back" was never emailed the reply');
    expect(ConversationReplyDelivery::query()->sole()->recipient)->toBe('ada@example.test');
});

test('a conversation opened while the desk was open is not emailed, even with an address on file', function (): void {
    // The visitor is in the widget and is being answered there. Mailing them
    // as well is the product talking over itself.
    Mail::fake();
    $site = awayReplyByEmailSite(away: false);
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);

    expect($conversation->visitor->email)->toBe('ada@example.test');

    awayReplyByEmailMailer();
    awayReplyByEmailReply($conversation, $agent);

    expect(Mail::sent(ConversationReplyMessage::class)->count())
        ->toBe(0, 'a widget conversation opened during open hours was emailed as if the desk had been away');
    expect(ConversationReplyDelivery::query()->count())->toBe(0);
});

test('a stored address the widget did not ask for again is promised the same reply', function (): void {
    // A stored address waives the out-of-hours question. That visitor was
    // told the same thing as one who typed an address now.
    Mail::fake();
    $site = awayReplyByEmailSite();
    $visitor = Visitor::factory()->for($site)->create(['email' => 'avery@example.test']);
    $conversation = awayReplyByEmailOpen($site, ['visitor_email' => null], $visitor);
    $agent = awayReplyByEmailAgent($site);

    expect($conversation->metadata[ConversationReplyMailer::REPLY_BY_EMAIL] ?? null)
        ->toBeTrue('an away conversation from a visitor whose address we already held was not marked for an emailed reply');

    awayReplyByEmailMailer();
    awayReplyByEmailReply($conversation, $agent);

    expect(Mail::sent(ConversationReplyMessage::class, fn (ConversationReplyMessage $mail): bool => $mail->hasTo('avery@example.test'))->count())
        ->toBe(1, 'the stored address was not emailed the reply');
});

test('without outbound mail nothing is queued, and the agent is told the visitor will not be emailed', function (string $mailer): void {
    // `log` accepts every message and delivers none. An outbox row marked
    // accepted would tell the agent a reply went that went nowhere.
    Mail::fake();
    $site = awayReplyByEmailSite();
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer($mailer);
    awayReplyByEmailReply($conversation, $agent);

    expect(ConversationReplyDelivery::query()->count())
        ->toBe(0, "an away reply was queued for email through the non-delivering {$mailer} mailer");
    Mail::assertNothingSent();

    awayReplyByEmailMailer($mailer);
    $html = $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'data-reply-by-email="not_emailed"'))
        ->toBeTrue('the agent was not told that replies to this visitor are not emailed');
    expect(str_contains($html, 'Not emailed'))->toBeTrue('the not-emailed label is missing');
    expect(str_contains($html, 'email is not set up on this install'))->toBeTrue('the agent is not told why');
    expect(str_contains($html, 'Also emailed to'))->toBeFalse('the page claims an email that is not being sent');
})->with(['log', 'array']);

test('the agent is told beside the reply box that replies also go to that inbox', function (): void {
    $site = awayReplyByEmailSite();
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer();
    $html = $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'data-reply-by-email="emailed"'))
        ->toBeTrue('the agent was not told that replies to this conversation are emailed');
    expect(str_contains($html, 'Also emailed to'))->toBeTrue('the emailed label is missing');
    expect(str_contains($html, '<span class="meta-value" lang="">ada@example.test</span>'))
        ->toBeTrue('the address replies go to is not shown, or is announced in the page language');
    expect(str_contains($html, 'has not been verified'))->toBeTrue('the agent is not told the address is unverified');
});

test('an ordinary widget conversation carries no email notice', function (): void {
    $site = awayReplyByEmailSite(away: false);
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer();
    $html = $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'data-reply-by-email'))
        ->toBeFalse('a conversation opened during open hours tells the agent its replies are emailed');
});

test('the notice speaks the agent language', function (): void {
    $site = awayReplyByEmailSite();
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site, ['locale' => 'de']);

    awayReplyByEmailMailer();
    $html = $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->getContent();

    expect(str_contains($html, 'Auch per E-Mail an'))->toBeTrue('the German agent is not told in German');
    expect(str_contains($html, 'Also emailed to'))->toBeFalse('English leaked onto the German page');
});

test('the emailed reply renders in the visitor language and sends them back to the chat when the site receives no mail', function (): void {
    // Through the real job and a real transport, not a fake: a fake records
    // the mailable without rendering it, which is how a view that threw on
    // every send went unnoticed.
    Queue::fake();
    $site = awayReplyByEmailSite();
    $conversation = awayReplyByEmailOpen($site, ['locale' => 'de']);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer();
    awayReplyByEmailReply($conversation, $agent, "We've found it & it ships <today>.");

    $delivery = ConversationReplyDelivery::query()->sole();
    config()->set('mail.default', 'array');
    $thrown = null;

    try {
        (new SendConversationReplyDelivery($delivery->id))->handle();
    } catch (Throwable $exception) {
        $thrown = $exception::class.': '.$exception->getMessage();
    }

    expect($thrown)->toBeNull('the reply email could not be built: '.$thrown);

    /** @var Email $email */
    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    $body = (string) $email->getTextBody();

    expect($email->getTo()[0]->getAddress())->toBe('ada@example.test')
        ->and($delivery->fresh()->accepted_at)->not->toBeNull();
    expect(str_contains($body, "We've found it & it ships <today>."))
        ->toBeTrue("the agent's words were escaped as if a text email were HTML:\n".$body);
    expect($email->getSubject())->toBe('Re: Ihre Supportanfrage', 'the subject is not in the language the visitor was promised a reply in');
    expect(str_contains($body, 'öffnen Sie den Chat auf northwind.test'))
        ->toBeTrue("the visitor is not sent back to the chat, in their language:\n".$body);
    expect(str_contains($body, 'Antworten Sie auf diese E-Mail'))
        ->toBeFalse('the visitor is invited to reply to an address that reaches no conversation');
    expect($email->getReplyTo())->toBe([]);
});

test('with an inbound address the email invites a reply, and that reply threads back onto the conversation', function (): void {
    Queue::fake();
    $site = awayReplyByEmailSite(['inbound_address' => 'support@northwind.test']);
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer();
    awayReplyByEmailReply($conversation, $agent);

    $delivery = ConversationReplyDelivery::query()->sole();
    config()->set('mail.default', 'array');
    (new SendConversationReplyDelivery($delivery->id))->handle();

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

    expect($email->getReplyTo()[0]->getAddress())->toBe('support@northwind.test')
        ->and(str_contains((string) $email->getTextBody(), 'Reply to this email and it will reach the same conversation.'))->toBeTrue();

    $answer = app(InboundMailRouter::class)->route(InboundMessage::fromPayload([
        'from' => 'ada@example.test',
        'to' => 'support@northwind.test',
        'subject' => 'Re: Your support request',
        'text' => 'Thank you.',
        'message_id' => '<answer@example.test>',
        'in_reply_to' => $delivery->message_id,
    ]));

    expect($answer?->conversation_id)->toBe($conversation->id, 'the promised reply-by-email opened a new conversation instead of continuing this one');
});

test('a conversation that arrived by email still needs the address it arrived at', function (): void {
    // Unchanged: the email channel is answered from the site's inbound address.
    Mail::fake();
    $site = awayReplyByEmailSite(['inbound_address' => null], away: false);
    $visitor = Visitor::factory()->for($site)->create(['email' => 'ada@example.test', 'anonymous_id' => null]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['metadata' => ['channel' => 'email']]);
    $agent = awayReplyByEmailAgent($site);

    awayReplyByEmailMailer();
    awayReplyByEmailReply($conversation, $agent);

    expect(ConversationReplyDelivery::query()->count())
        ->toBe(0, 'an email conversation was answered although its site no longer receives mail');
});

test('a ticket note on such a conversation is never emailed', function (): void {
    // Only replies are visitor-facing. A note is for the team.
    Mail::fake();
    $site = awayReplyByEmailSite();
    $conversation = awayReplyByEmailOpen($site);
    $agent = awayReplyByEmailAgent($site);
    $ticket = Ticket::factory()->for($site->account)->for($site)->create(['conversation_id' => $conversation->id]);

    awayReplyByEmailMailer();
    $this->actingAs($agent)
        ->post(route('dashboard.tickets.notes.store', $ticket), ['body' => 'Refund approved internally.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Mail::sent(ConversationReplyMessage::class)->count())
        ->toBe(0, 'an internal ticket note was emailed to the visitor');
    expect(ConversationReplyDelivery::query()->count())->toBe(0);
});
