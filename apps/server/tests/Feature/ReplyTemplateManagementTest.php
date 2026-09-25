<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Events\ConversationMessageCreated;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\ReplyTemplate;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

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
        ->assertSessionHas('status', 'reply_templates.flash.created');

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
        ->assertSessionHas('status', 'reply_templates.flash.updated');

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
        ->assertSessionHas('status', 'reply_templates.flash.archived');

    expect($template->fresh()->is_active)->toBeFalse();
});

test('reply template management guides admins before templates exist', function (): void {
    $admin = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/reply-templates')
        ->assertOk()
        ->assertSee('No reply templates yet.')
        // Still says what an empty page does NOT mean: the composer falls back
        // to the built-in helpers until an active account template exists.
        ->assertSee('Add a template when agents keep rewriting the same answer; until then, reply composers offer the built-in helpers.')
        // "Managed" was the code's word, not the reader's.
        ->assertDontSee('No managed reply templates yet.')
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

    $otherTemplate->forceFill(['is_active' => false])->save();

    $this->actingAs($admin)
        ->post("/dashboard/account/reply-templates/{$otherTemplate->id}/restore")
        ->assertNotFound();

    $this->assertDatabaseHas('reply_templates', [
        'id' => $otherTemplate->id,
        'name' => 'Other account helper',
        'is_active' => false,
    ]);
});

test('reply template mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $knowledgeRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageKnowledge->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $manager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $knowledgeRole->id,
    ]);
    // Restoring an ACTIVE template changes nothing, so a leaked restore would
    // pass unseen; that case starts from an archived one.
    $initiallyActive = $action !== 'restore';
    $template = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Original helper',
        'body' => 'Original reply body.',
        'is_active' => $initiallyActive,
    ]);

    $this->actingAs($manager);
    expect($manager->hasAccountPermission(AccountPermission::ManageKnowledge))->toBeTrue();
    User::query()->whereKey($manager->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'create' => $this->post(route('dashboard.account.reply-templates.store'), [
            'name' => 'Late helper',
            'body' => 'This write must not land.',
        ]),
        'update' => $this->put(route('dashboard.account.reply-templates.update', $template), [
            'name' => 'Late rename',
            'body' => 'This update must not land.',
        ]),
        'archive' => $this->post(route('dashboard.account.reply-templates.archive', $template)),
        'restore' => $this->post(route('dashboard.account.reply-templates.restore', $template)),
    };

    $action === 'create'
        ? $response->assertForbidden()
        : $response->assertNotFound();

    expect(ReplyTemplate::query()->count())->toBe(1)
        ->and($template->fresh()->name)->toBe('Original helper')
        ->and($template->fresh()->body)->toBe('Original reply body.')
        ->and($template->fresh()->is_active)->toBe($initiallyActive, "a stale role's {$action} changed whether the template is active");
})->with(['create', 'update', 'archive', 'restore']);

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
        ->assertSee('Writing this one yourself')
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
        ->assertSee('Writing this one yourself');

    $this->actingAs($agent)
        ->withSession(['_old_input' => ['reply_template' => ['managed:'.$template->id]]])
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('Reply assist')
        ->assertSee('Writing this one yourself');
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
        ->assertSessionHas('status', 'conversations.flash.reply_sent');

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
        ->assertSessionHas('status', 'tickets.flash.reply_sent');

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

test('the flash message reaches the agent in their own language', function (): void {
    // The key travels, not the sentence. The request that redirects and the
    // request that renders are different requests, and the agent's language is
    // resolved per request -- so a sentence chosen at redirect time would be
    // the language of whoever happened to be acting, not of whoever reads it.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'locale' => 'de',
    ]);

    $this->actingAs($admin)
        ->post(route('dashboard.account.reply-templates.store'), [
            'name' => 'Rückfrage',
            'body' => 'Danke für die Rückmeldung.',
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('dashboard.account.reply-templates.index'))
        ->assertOk()
        ->assertSee('Antwortvorlage erstellt.')
        // Neither the key nor the English sentence reaches the screen.
        ->assertDontSee('reply_templates.flash.created')
        ->assertDontSee('Reply template created.');
});

test('an agent who reads German gets the page in German', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'locale' => 'de',
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.reply-templates.index'))
        ->assertOk()
        ->assertSee('Antwortvorlagen')
        ->assertSee('Vorlagenstandards')
        // The two words this page exists to keep apart: the account template it
        // manages, and the built-in composer helper it says stays available.
        ->assertSee('Antwortvorlage')
        ->assertSee('Antworthilfen')
        ->assertDontSee('Reply templates')
        ->assertDontSee('Template standards');
});

test('the row controls reach a German agent in German', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'locale' => 'de',
    ]);
    ReplyTemplate::factory()->for($account)->create(['name' => 'Rückfrage']);
    ReplyTemplate::factory()->for($account)->archived()->create(['name' => 'Alte Hilfe']);

    $this->actingAs($admin)
        ->get(route('dashboard.account.reply-templates.index'))
        ->assertOk()
        ->assertSeeText('„Rückfrage“ bearbeiten')
        ->assertSee('Archivieren')
        ->assertSee('Wiederherstellen')
        ->assertDontSee('Edit template')
        ->assertDontSee('>Restore<', false);
});

test('each template row keeps its editor folded behind a disclosure', function (): void {
    // The Body column already shows the text. The editor used to be a full,
    // padded form in every row -- twenty templates were twenty stacked
    // editors -- so it waits behind one line until someone opens it.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $template = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => str_repeat('I will check the billing details and follow up shortly. ', 4),
    ]);

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );

    $disclosure = replyTemplateManagementElement(
        $xpath,
        '//td/details[.//form[@action="'.route('dashboard.account.reply-templates.update', $template).'"]]',
    );
    $summary = replyTemplateManagementElement($xpath, '//td/details/summary');

    expect($disclosure->hasAttribute('open'))->toBeFalse('the row editor is open before anyone asked for it')
        ->and(trim($summary->textContent))->toBe('Edit “Billing follow-up”')
        // The scannable preview stays.
        ->and($xpath->query('//td[normalize-space(.)="'.trim(Str::limit($template->body, 120)).'"]')->length)
        ->toBe(1, 'the truncated body preview is gone');
});

test('every template edit form says which template it edits', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $templates = ReplyTemplate::factory()->for($account)->count(2)->sequence(
        ['name' => 'Billing follow-up'],
        ['name' => 'Shipping update'],
    )->create();

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );

    foreach ($templates as $template) {
        replyTemplateManagementElement(
            $xpath,
            '//form[@action="'.route('dashboard.account.reply-templates.update', $template).'"]//input[@type="hidden" and @name="editing_template" and @value="'.$template->id.'"]',
        );
    }
});

test('a rejected edit comes back open, to the row that sent it and nowhere else', function (): void {
    // Every edit form and the create form post the same `name` and `body`.
    // Before the rows said which one they were, a rejected edit came back as a
    // detached message at the top of the page with the rejected values
    // painted into the create form and EVERY row -- so a Save on any other
    // row sent them as that row's content.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $edited = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details.',
    ]);
    $bystander = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Shipping update',
        'body' => 'Your parcel is on its way.',
    ]);

    $html = $this->actingAs($admin)
        ->from(route('dashboard.account.reply-templates.index'))
        ->followingRedirects()
        ->put(route('dashboard.account.reply-templates.update', $edited), [
            'editing_template' => (string) $edited->id,
            'name' => 'Billing status',
            'body' => '',
        ])
        ->assertOk()
        ->getContent();

    $xpath = replyTemplateManagementXPath($html);
    $editedDisclosure = replyTemplateManagementElement($xpath, '//details[.//form[@action="'.route('dashboard.account.reply-templates.update', $edited).'"]]');
    $bystanderDisclosure = replyTemplateManagementElement($xpath, '//details[.//form[@action="'.route('dashboard.account.reply-templates.update', $bystander).'"]]');
    $name = replyTemplateManagementElement($xpath, '//input[@id="reply-template-'.$edited->id.'-name"]');
    $body = replyTemplateManagementElement($xpath, '//textarea[@id="reply-template-'.$edited->id.'-body"]');
    replyTemplateManagementElement($xpath, '//p[@id="reply-template-'.$edited->id.'-body-error"]');
    $otherName = replyTemplateManagementElement($xpath, '//input[@id="reply-template-'.$bystander->id.'-name"]');
    $otherBody = replyTemplateManagementElement($xpath, '//textarea[@id="reply-template-'.$bystander->id.'-body"]');
    $createName = replyTemplateManagementElement($xpath, '//input[@id="new-template-name"]');
    $createBody = replyTemplateManagementElement($xpath, '//textarea[@id="new-template-body"]');

    expect($editedDisclosure->hasAttribute('open'))->toBeTrue('the failing row came back folded, hiding its own error')
        ->and($bystanderDisclosure->hasAttribute('open'))->toBeFalse('a row that was not submitted opened')
        ->and($name->getAttribute('value'))->toBe('Billing status', 'the failing row lost what the agent typed')
        ->and($name->hasAttribute('aria-invalid'))->toBeFalse('a valid field in the failing row is marked invalid')
        ->and($body->getAttribute('aria-invalid'))->toBe('true', 'the failing field is not marked invalid')
        ->and($body->getAttribute('aria-describedby'))->toBe('reply-template-'.$edited->id.'-body-error', 'the failing field is not described by its message')
        ->and($body->hasAttribute('autofocus'))->toBeTrue('the reloaded page does not open on the failing field')
        ->and($otherName->getAttribute('value'))->toBe('Shipping update', 'the rejected edit was painted into another row')
        ->and(trim($otherBody->textContent))->toBe('Your parcel is on its way.', 'the rejected edit blanked another row')
        ->and($otherBody->hasAttribute('aria-invalid'))->toBeFalse('a row that was not submitted is marked invalid')
        ->and($createName->getAttribute('value'))->toBe('', 'the rejected edit was painted into the create form')
        ->and($createBody->hasAttribute('aria-invalid'))->toBeFalse('the create form is marked invalid for an edit')
        ->and($xpath->query('//p[contains(@class, "field-error")]')->length)->toBe(1, 'the message is printed more than once');
});

test('a rejected create comes back to the create form and leaves every row alone', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $existing = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details.',
    ]);

    $html = $this->actingAs($admin)
        ->from(route('dashboard.account.reply-templates.index'))
        ->followingRedirects()
        ->post(route('dashboard.account.reply-templates.store'), [
            'name' => '',
            'body' => '',
        ])
        ->assertOk()
        ->getContent();

    $xpath = replyTemplateManagementXPath($html);
    $createName = replyTemplateManagementElement($xpath, '//input[@id="new-template-name"]');
    $createBody = replyTemplateManagementElement($xpath, '//textarea[@id="new-template-body"]');
    replyTemplateManagementElement($xpath, '//p[@id="new-template-name-error"]');
    replyTemplateManagementElement($xpath, '//p[@id="new-template-body-error"]');
    $rowName = replyTemplateManagementElement($xpath, '//input[@id="reply-template-'.$existing->id.'-name"]');
    $rowBody = replyTemplateManagementElement($xpath, '//textarea[@id="reply-template-'.$existing->id.'-body"]');
    $rowDisclosure = replyTemplateManagementElement($xpath, '//details[.//form[@action="'.route('dashboard.account.reply-templates.update', $existing).'"]]');

    expect($createName->getAttribute('aria-invalid'))->toBe('true', 'the create name is not marked invalid')
        ->and($createName->getAttribute('aria-describedby'))->toBe('new-template-name-error', 'the create name is not described by its message')
        ->and($createBody->getAttribute('aria-invalid'))->toBe('true', 'the create body is not marked invalid')
        ->and($createBody->getAttribute('aria-describedby'))->toBe('new-template-body-error', 'the create body is not described by its message')
        // Two invalid fields, one focus: the first of them.
        ->and($createName->hasAttribute('autofocus'))->toBeTrue('the reloaded page does not open on the first failing field')
        ->and($createBody->hasAttribute('autofocus'))->toBeFalse('two fields claim the page focus')
        ->and($rowName->getAttribute('value'))->toBe('Billing follow-up', 'the rejected create was painted into a row')
        ->and(trim($rowBody->textContent))->toBe('I will check the billing details.', 'the rejected create blanked a row')
        ->and($rowName->hasAttribute('aria-invalid'))->toBeFalse('a row is marked invalid for a create')
        ->and($rowDisclosure->hasAttribute('open'))->toBeFalse('a row opened for a create');
});

test('an archived template can be restored, and archiving is not styled as deletion', function (): void {
    // Archiving keeps the record and only takes the template out of the reply
    // helpers -- the same kind of step as unpublishing an article. It was a
    // danger button and one-way, with a working Save form on a row nobody
    // could bring back.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $active = ReplyTemplate::factory()->for($account)->create(['name' => 'Billing follow-up']);
    $archived = ReplyTemplate::factory()->for($account)->archived()->create(['name' => 'Old helper']);

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );

    $archive = replyTemplateManagementElement($xpath, '//form[@action="'.route('dashboard.account.reply-templates.archive', $active).'"]//button');
    $restore = replyTemplateManagementElement($xpath, '//form[@action="'.route('dashboard.account.reply-templates.restore', $archived).'"]//button');

    expect($archive->getAttribute('class'))->toBe('button secondary', 'archiving is styled as something other than a reversible step')
        ->and($restore->getAttribute('class'))->toBe('button secondary')
        ->and(trim($restore->textContent))->toBe('Restore')
        ->and($xpath->query('//form[@action="'.route('dashboard.account.reply-templates.restore', $active).'"]')->length)
        ->toBe(0, 'an active template offers Restore')
        ->and($xpath->query('//form[@action="'.route('dashboard.account.reply-templates.archive', $archived).'"]')->length)
        ->toBe(0, 'an archived template offers Archive');

    $this->actingAs($admin)
        ->from(route('dashboard.account.reply-templates.index'))
        ->post(route('dashboard.account.reply-templates.restore', $archived))
        ->assertRedirect(route('dashboard.account.reply-templates.index'))
        ->assertSessionHas('status', 'reply_templates.flash.restored');

    expect($archived->fresh()->is_active)->toBeTrue('restoring did not bring the template back')
        ->and($active->fresh()->is_active)->toBeTrue();

    $this->actingAs($admin)
        ->get(route('dashboard.account.reply-templates.index'))
        ->assertOk()
        ->assertSee('Reply template restored.');
});

test('template names and bodies are marked as account data, not dashboard copy', function (): void {
    // The composer already language-resets the same name and body
    // (ReplyTemplateOptions::forAgent); the page that manages them has to
    // agree.
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'locale' => 'de',
    ]);
    $template = ReplyTemplate::factory()->for($account)->create([
        'name' => 'Billing follow-up',
        'body' => 'I will check the billing details.',
    ]);

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );

    $name = replyTemplateManagementElement($xpath, '//input[@id="reply-template-'.$template->id.'-name"]');
    $body = replyTemplateManagementElement($xpath, '//textarea[@id="reply-template-'.$template->id.'-body"]');

    expect($xpath->query('//td/strong[@lang="" and normalize-space(.)="Billing follow-up"]')->length)
        ->toBe(1, 'the template name is not marked as account data')
        ->and($xpath->query('//td[@lang="" and normalize-space(.)="I will check the billing details."]')->length)
        ->toBe(1, 'the body preview is not marked as account data')
        ->and($name->hasAttribute('lang') && $name->getAttribute('lang') === '')
        ->toBeTrue('the name field, whose value is account data, is not marked as such')
        ->and($body->hasAttribute('lang') && $body->getAttribute('lang') === '')
        ->toBeTrue('the body field, whose value is account data, is not marked as such');
});

test('explanatory ledes sit under their headings, and the count stays on the right', function (): void {
    // `.section-header` is a space-between flex row. A lede that is the h2's
    // SIBLING is pushed to the far edge -- right for a count, wrong for a
    // sentence that explains the heading it belongs to.
    $admin = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Admin]);

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );

    $header = 'div[contains(concat(" ", normalize-space(@class), " "), " section-header ")]';

    foreach (['reply-template-standards-heading', 'new-reply-template-heading'] as $heading) {
        expect($xpath->query('//'.$header.'/div[h2[@id="'.$heading.'"]]/p[@class="lede"]')->length)
            ->toBe(1, "the {$heading} lede is not under its heading");
    }

    expect($xpath->query('//'.$header.'[h2[@id="reply-templates-heading"]]/span[@class="lede"]')->length)
        ->toBe(1, 'the template count left the right-hand slot');
});

function replyTemplateManagementXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

    return new DOMXPath($document);
}

/**
 * The one element a query names. Failing on a count of zero OR two says the
 * query is wrong before any attribute assertion can pass on the wrong node.
 */
function replyTemplateManagementElement(DOMXPath $xpath, string $query): DOMElement
{
    $nodes = $xpath->query($query);

    expect($nodes === false ? 0 : $nodes->length)->toBe(1, "expected exactly one element for {$query}");

    return $nodes->item(0);
}

test('restoring a reply template stays inside the same boundaries as archiving it', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $outsider = User::factory()->for($otherAccount)->create(['account_role' => AccountRole::Admin]);
    $archived = ReplyTemplate::factory()->for($account)->create(['is_active' => false]);

    $this->actingAs($outsider)
        ->post(route('dashboard.account.reply-templates.restore', $archived))
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('dashboard.account.reply-templates.restore', $archived))
        ->assertNotFound();

    expect($archived->fresh()->is_active)
        ->toBeFalse('A reply template was restored by someone who cannot manage this account\'s knowledge.');
});

test('a row discriminator sent as an array still lands on the page, not a 500', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $template = ReplyTemplate::factory()->for($account)->create();

    // Laravel flashes the whole request on a validation redirect, so a crafted
    // `editing_template[]` comes back through old() as an array.
    $this->actingAs($admin)
        ->from(route('dashboard.account.reply-templates.index'))
        ->followingRedirects()
        ->put(route('dashboard.account.reply-templates.update', $template), [
            'editing_template' => [(string) $template->id],
            'name' => 'Billing status',
            'body' => '',
        ])
        ->assertOk();
});

test('each template editor is named for its own row', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    ReplyTemplate::factory()->for($account)->create(['name' => 'Billing follow-up']);
    ReplyTemplate::factory()->for($account)->create(['name' => 'Shipping update']);

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );
    $summaries = collect(iterator_to_array($xpath->query('//td/details/summary')))
        ->map(fn (DOMNode $summary): string => trim($summary->textContent))
        ->all();

    expect($summaries)->toEqualCanonicalizing(['Edit “Billing follow-up”', 'Edit “Shipping update”'], 'every row editor has the same accessible name, so a screen reader cannot tell them apart')
        ->and($xpath->query('//td/details/summary/span[@lang=""]')->length)->toBe(2, 'the template name inside the translated label does not keep its own language');
});

test('archive and restore controls name the template they act on', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $active = ReplyTemplate::factory()->for($account)->create(['name' => 'Billing follow-up']);
    $archived = ReplyTemplate::factory()->for($account)->archived()->create(['name' => 'Shipping update']);

    $xpath = replyTemplateManagementXPath(
        $this->actingAs($admin)->get(route('dashboard.account.reply-templates.index'))->assertOk()->getContent(),
    );
    $archive = replyTemplateManagementElement($xpath, '//form[@action="'.route('dashboard.account.reply-templates.archive', $active).'"]//button');
    $restore = replyTemplateManagementElement($xpath, '//form[@action="'.route('dashboard.account.reply-templates.restore', $archived).'"]//button');

    expect($archive->getAttribute('aria-label'))->toBe('Archive “Billing follow-up”', 'every Archive button has the same accessible name')
        ->and($restore->getAttribute('aria-label'))->toBe('Restore “Shipping update”', 'every Restore button has the same accessible name')
        // Label in name: the visible word is inside the accessible name, so
        // voice control still finds the button by what it shows.
        ->and(str_contains($restore->getAttribute('aria-label'), trim($restore->textContent)))->toBeTrue();
});

test('a malformed reply template id is a 404, not a database error', function (string $method, string $suffix): void {
    // Without a numeric constraint the route matches, and PostgreSQL refuses to
    // compare the string with a bigint key while binding the model.
    $admin = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->call($method, '/dashboard/account/reply-templates/not-a-number'.$suffix, ['name' => 'x', 'body' => 'y'])
        ->assertNotFound();
})->with([
    'update' => ['PUT', ''],
    'archive' => ['POST', '/archive'],
    'restore' => ['POST', '/restore'],
]);
