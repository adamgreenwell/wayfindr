<?php

use App\Enums\AccountRole;
use App\Events\ConversationMessageCreated;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ReplyTemplate;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('account admins can create edit and archive reply templates', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/reply-templates')
        ->assertOk()
        ->assertSee('Reply templates')
        ->assertSee('Create template');

    $this->actingAs($admin)
        ->from('/dashboard/account/reply-templates')
        ->post('/dashboard/account/reply-templates', [
            'name' => 'Billing follow-up',
            'body' => 'Thanks for reaching out. I will check the billing details and follow up shortly.',
        ])
        ->assertRedirect('/dashboard/account/reply-templates')
        ->assertSessionHas('status', 'Reply template created.');

    $template = ReplyTemplate::query()
        ->where('account_id', $account->id)
        ->firstOrFail();

    expect($template)
        ->name->toBe('Billing follow-up')
        ->body->toBe('Thanks for reaching out. I will check the billing details and follow up shortly.')
        ->is_active->toBeTrue();

    $this->actingAs($admin)
        ->from('/dashboard/account/reply-templates')
        ->put("/dashboard/account/reply-templates/{$template->id}", [
            'name' => 'Billing status check',
            'body' => 'I am checking the billing status and will keep this ticket updated.',
        ])
        ->assertRedirect('/dashboard/account/reply-templates')
        ->assertSessionHas('status', 'Reply template updated.');

    $this->assertDatabaseHas('reply_templates', [
        'id' => $template->id,
        'account_id' => $account->id,
        'name' => 'Billing status check',
        'body' => 'I am checking the billing status and will keep this ticket updated.',
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/reply-templates')
        ->post("/dashboard/account/reply-templates/{$template->id}/archive")
        ->assertRedirect('/dashboard/account/reply-templates')
        ->assertSessionHas('status', 'Reply template archived.');

    expect($template->fresh()->is_active)->toBeFalse();
});

test('reply template management guides admins before templates exist', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/reply-templates')
        ->assertOk()
        ->assertSee('No managed reply templates yet.')
        ->assertSee('Built-in helpers stay available in reply composers until your team adds account templates.')
        ->assertSee('Create the first template')
        ->assertSee('href="#new-reply-template-heading"', false);
});

test('reply template management explains template standards', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/reply-templates')
        ->assertOk()
        ->assertSee('Template standards')
        ->assertSee('Treat templates as calm starting points, not scripts agents must send unchanged.')
        ->assertSee('Keep visitor-visible templates free of passwords, payment details, private handoff notes, and promises your team cannot keep.')
        ->assertSee('Use templates for acknowledgements, status updates, next steps, and common clarification requests.');
});

test('reply template management stays inside account admin boundaries', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    $otherTemplate = ReplyTemplate::factory()->for($otherAccount)->create([
        'name' => 'Other account helper',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account/reply-templates')
        ->assertForbidden();

    $this->actingAs($agent)
        ->post('/dashboard/account/reply-templates', [
            'name' => 'Nope',
            'body' => 'Agents cannot manage account reply templates.',
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->put("/dashboard/account/reply-templates/{$otherTemplate->id}", [
            'name' => 'Borrowed',
            'body' => 'This should not cross accounts.',
        ])
        ->assertNotFound();

    $this->actingAs($admin)
        ->post("/dashboard/account/reply-templates/{$otherTemplate->id}/archive")
        ->assertNotFound();

    $this->assertDatabaseHas('reply_templates', [
        'id' => $otherTemplate->id,
        'name' => 'Other account helper',
    ]);
});

test('reply template management rejects blank trimmed input', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->from('/dashboard/account/reply-templates')
        ->post('/dashboard/account/reply-templates', [
            'name' => '   ',
            'body' => 'Helpful reply body.',
        ])
        ->assertRedirect('/dashboard/account/reply-templates')
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->from('/dashboard/account/reply-templates')
        ->post('/dashboard/account/reply-templates', [
            'name' => 'Helpful helper',
            'body' => '   ',
        ])
        ->assertRedirect('/dashboard/account/reply-templates')
        ->assertSessionHasErrors('body');

    expect($admin->account->replyTemplates()->count())->toBe(0);
});

test('managed active reply templates appear in conversation and ticket reply helpers', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TEMPLATES',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create(['status' => 'open']);
    ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details.',
    ]);
    ReplyTemplate::factory()->for($account)->archived()->create([
        'name' => 'Archived helper',
        'body' => 'This old helper should not show up.',
    ]);
    ReplyTemplate::factory()->for($otherAccount)->create([
        'name' => 'Other account helper',
        'body' => 'This should not show up.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('Reply helper')
        ->assertSee('Billing follow-up')
        ->assertDontSee('Archived helper')
        ->assertDontSee('Other account helper')
        ->assertDontSee('Looking into it');

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Reply helper')
        ->assertSee('Billing follow-up')
        ->assertDontSee('Archived helper')
        ->assertDontSee('Other account helper')
        ->assertDontSee('Looking into it');
});

test('static reply helpers remain available until an account creates active templates', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-DEFAULTS',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create(['status' => 'open']);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Looking into it')
        ->assertSee('Need more detail')
        ->assertSee('Ticket follow-up');

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('Looking into it')
        ->assertSee('Need more detail')
        ->assertSee('Ticket follow-up');
});

test('conversation reply surface shows context and helper preview affordances', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'assigned_agent_id' => $agent->id,
        'support_code' => 'WF-REPLYCOMFORT',
        'subject' => 'Checkout button stuck',
        'status' => 'open',
    ]);
    ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details and follow up shortly.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('Reply assist')
        ->assertSee('Reply context')
        ->assertSee('Needs reply')
        ->assertSee('Assigned to you')
        ->assertSee('Billing follow-up')
        ->assertSee('I will check the billing details and follow up shortly.')
        ->assertSee('data-shortcut-submit', false)
        ->assertSee('aria-describedby="reply-shortcut-help"', false)
        ->assertSee('Command or Control plus Enter sends this reply.')
        ->assertSee('Keep sensitive details out of replies unless the visitor supplied them here.');
});

test('ticket visitor reply surface shows helper preview affordances', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-TICKETHELPER',
        'subject' => 'Invoice export question',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);
    ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details and follow up shortly.',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Reply assist')
        ->assertSee('No helper selected')
        ->assertSee('Billing follow-up')
        ->assertSee('I will check the billing details and follow up shortly.')
        ->assertSee('data-template-preview-item="managed:', false)
        ->assertSee('Keep sensitive details out of visitor replies unless the visitor supplied them here.');
});

test('reply surfaces tolerate malformed flashed reply template input', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BADHELPER',
        'status' => 'open',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['status' => 'open']);
    $template = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details and follow up shortly.',
    ]);

    $this->actingAs($agent)
        ->withSession(['_old_input' => ['reply_template' => ['managed:'.$template->id]]])
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertOk()
        ->assertSee('Reply assist')
        ->assertSee('No helper selected');

    $this->actingAs($agent)
        ->withSession(['_old_input' => ['reply_template' => ['managed:'.$template->id]]])
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Reply assist')
        ->assertSee('No helper selected');
});

test('agents can send replies from managed account templates', function (): void {
    Event::fake([ConversationMessageCreated::class]);

    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MANAGED',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create(['status' => 'open']);
    $template = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details and follow up shortly.',
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/conversations/{$conversation->support_code}")
        ->post("/dashboard/conversations/{$conversation->support_code}/messages", [
            'reply_template' => 'managed:'.$template->id,
            'body' => '',
        ])
        ->assertRedirect("/dashboard/conversations/{$conversation->support_code}")
        ->assertSessionHas('status', 'Reply sent.');

    $conversationReply = $conversation->messages()->latest('id')->firstOrFail();

    expect($conversationReply)
        ->body->toBe('I will check the billing details and follow up shortly.')
        ->and($conversationReply->metadata)->toMatchArray([
            'reply_template_id' => $template->id,
            'reply_template_name' => 'Billing follow-up',
        ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/replies", [
            'reply_template' => 'managed:'.$template->id,
            'message' => '',
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Reply sent.');

    $ticketReply = $conversation->messages()->latest('id')->firstOrFail();

    expect($ticketReply)
        ->body->toBe('I will check the billing details and follow up shortly.')
        ->and($ticketReply->metadata)->toMatchArray([
            'source' => 'ticket',
            'ticket_id' => $ticket->id,
            'reply_template_id' => $template->id,
            'reply_template_name' => 'Billing follow-up',
        ]);

    Event::assertDispatched(
        ConversationMessageCreated::class,
        fn (ConversationMessageCreated $event): bool => $event->message->is($conversationReply)
            || $event->message->is($ticketReply)
    );
});

test('the account management hub links admins to reply template management', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account')
        ->assertOk()
        ->assertSee('Reply templates')
        ->assertSee('/dashboard/account/reply-templates', false);
});
