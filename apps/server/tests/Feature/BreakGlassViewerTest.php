<?php

// The scoped read-only viewers (ADR 0008, slice 3): what an active grant
// opens, what it refuses, and the .opened / .resource_viewed trail. Coverage
// re-derivation is proven in BreakGlassGrantTest — this suite proves the HTTP
// surfaces route through it: requester-only, active-only, in-scope-only, and
// attachment METADATA only.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function breakGlassViewerWorld(): array
{
    $account = Account::factory()->create();
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'My uploads keep failing.',
    ]);

    $grant = BreakGlassGrant::factory()
        ->activeFor($account, $operator)
        ->scopedToConversation($conversation)
        ->create();

    return compact('account', 'operator', 'site', 'visitor', 'conversation', 'grant');
}

test('the requester opens the grant and its covered transcript, audited once each', function (): void {
    $w = breakGlassViewerWorld();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.show', $w['grant']))
        ->assertOk()
        ->assertSee($w['conversation']->support_code);

    // Reload the grant page and view the transcript twice: dedup means one
    // .opened and one .resource_viewed, not four events.
    $this->actingAs($w['operator'])->get(route('operator.break-glass.show', $w['grant']));

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk()
        ->assertSee('My uploads keep failing.')
        ->assertSee('Visitor');

    $this->actingAs($w['operator'])->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]));

    expect(AuditEvent::where('action', 'break_glass.opened')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'break_glass.resource_viewed')->count())->toBe(1);

    $viewed = AuditEvent::where('action', 'break_glass.resource_viewed')->first();

    expect(data_get($viewed->metadata, 'resource_type'))->toBe('conversation')
        ->and(data_get($viewed->metadata, 'resource_id'))->toBe($w['conversation']->id)
        ->and($viewed->site_id)->toBeNull();
});

test('a direct resource view still records the grant as opened', function (): void {
    // Bookmarks and browser history skip the overview page — the trail must
    // still read opened -> resource_viewed, exactly once each.
    $w = breakGlassViewerWorld();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk();

    expect(AuditEvent::where('action', 'break_glass.opened')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'break_glass.resource_viewed')->count())->toBe(1);

    // Visiting the overview afterwards adds nothing.
    $this->actingAs($w['operator'])->get(route('operator.break-glass.show', $w['grant']));

    expect(AuditEvent::where('action', 'break_glass.opened')->count())->toBe(1);
});

test('attachments render as metadata with no path to the binary', function (): void {
    $w = breakGlassViewerWorld();
    $message = ConversationMessage::factory()->for($w['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $w['visitor']->id,
        'body' => null,
    ]);
    ConversationMessageAttachment::factory()->create([
        'conversation_message_id' => $message->id,
        'conversation_id' => $w['conversation']->id,
        'account_id' => $w['account']->id,
        'site_id' => $w['site']->id,
        'original_filename' => 'crash-report.txt',
    ]);

    $response = $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk()
        ->assertSee('crash-report.txt')
        ->assertSee('metadata only');

    expect($response->getContent())->not->toContain('/attachments/');
});

test('a sibling conversation under a conversation-scoped grant is refused', function (): void {
    $w = breakGlassViewerWorld();
    $sibling = Conversation::factory()->for($w['site'])->create(['visitor_id' => $w['visitor']->id]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $sibling]))
        ->assertNotFound();

    expect(AuditEvent::where('action', 'break_glass.resource_viewed')->count())->toBe(0);
});

test('another operator cannot use someone else\'s grant', function (): void {
    $w = breakGlassViewerWorld();
    $otherOperator = User::factory()->for($w['account'])->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Admin,
    ]);

    $this->actingAs($otherOperator)
        ->get(route('operator.break-glass.show', $w['grant']))
        ->assertNotFound();
});

test('an overdue or closed grant opens nothing, live', function (): void {
    $w = breakGlassViewerWorld();

    $w['grant']->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.show', $w['grant']))
        ->assertForbidden();

    $w['grant']->forceFill(['status' => BreakGlassGrant::STATUS_CLOSED, 'expires_at' => now()->addHour()])->save();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertForbidden();
});

test('a site-scoped grant lists its own conversations and not a sibling site\'s', function (): void {
    $w = breakGlassViewerWorld();
    $otherSite = Site::factory()->for($w['account'])->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();
    $otherConversation = Conversation::factory()->for($otherSite)->create(['visitor_id' => $otherVisitor->id]);

    $siteGrant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToSite($w['site'])
        ->create();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.show', $siteGrant))
        ->assertOk()
        ->assertSee($w['conversation']->support_code)
        ->assertDontSee($otherConversation->support_code);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$siteGrant, $otherConversation]))
        ->assertNotFound();
});

test('an account-scoped grant opens a covered ticket; a foreign ticket is refused', function (): void {
    $w = breakGlassViewerWorld();
    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->for($w['conversation'])->create([
        'subject' => 'Upload pipeline failure',
    ]);
    $foreignTicket = Ticket::factory()->create();

    $accountGrant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create();

    // The overview lists tickets by reference only — the subject is content
    // and renders solely on the per-resource-audited detail page.
    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.show', $accountGrant))
        ->assertOk()
        ->assertSee('Ticket #'.$ticket->id)
        ->assertDontSee('Upload pipeline failure');

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.tickets.show', [$accountGrant, $ticket]))
        ->assertOk()
        ->assertSee('Upload pipeline failure');

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.tickets.show', [$accountGrant, $foreignTicket]))
        ->assertNotFound();

    $viewed = AuditEvent::where('action', 'break_glass.resource_viewed')
        ->where('metadata->resource_type', 'ticket')
        ->get();

    // The label is a reference, never content — the customer-entered subject
    // must not be persisted into a trail designed to outlive the ticket.
    expect($viewed)->toHaveCount(1)
        ->and(data_get($viewed->first()->metadata, 'resource_label'))->toBe('Ticket #'.$ticket->id)
        ->and(json_encode($viewed->first()->metadata))->not->toContain('Upload pipeline failure');
});

test('a mismatched grant row cannot even name a foreign resource on the index', function (): void {
    // Defense in depth on the LISTING too: a corrupted grant whose
    // conversation_id points into another account renders an empty index —
    // not a foreign support code.
    $w = breakGlassViewerWorld();
    $foreignSite = Site::factory()->create();
    $foreignVisitor = Visitor::factory()->for($foreignSite)->create();
    $foreignConversation = Conversation::factory()->for($foreignSite)->create(['visitor_id' => $foreignVisitor->id]);

    $corrupted = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create([
            'scope_type' => BreakGlassGrant::SCOPE_CONVERSATION,
            'conversation_id' => $foreignConversation->id,
            'site_id' => $foreignSite->id,
        ]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.show', $corrupted))
        ->assertOk()
        ->assertDontSee($foreignConversation->support_code);
});

test('a mismatched linked ticket never renders under a covered transcript', function (): void {
    $w = breakGlassViewerWorld();
    $foreignAccount = Account::factory()->create();
    Ticket::factory()->for($foreignAccount)->for($w['site'])->for($w['conversation'])->create([
        'subject' => 'Mismatched ticket row subject',
    ]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk()
        ->assertDontSee('Mismatched ticket row subject');
});

test('a covered ticket never names an out-of-scope conversation', function (): void {
    // A site-scoped grant covers the ticket, but its conversation_id points
    // at another account's conversation — the record acknowledges the link
    // without naming the support code.
    $w = breakGlassViewerWorld();
    $foreignSite = Site::factory()->create();
    $foreignVisitor = Visitor::factory()->for($foreignSite)->create();
    $foreignConversation = Conversation::factory()->for($foreignSite)->create(['visitor_id' => $foreignVisitor->id]);

    $ticket = Ticket::factory()->for($w['account'])->for($w['site'])->create([
        'conversation_id' => $foreignConversation->id,
        'subject' => 'Stale link ticket',
    ]);

    $siteGrant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToSite($w['site'])
        ->create();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.tickets.show', [$siteGrant, $ticket]))
        ->assertOk()
        ->assertSee('Stale link ticket')
        ->assertSee('(out of scope)')
        ->assertDontSee($foreignConversation->support_code);
});

test('a mismatched attachment row renders nothing, not even its filename', function (): void {
    $w = breakGlassViewerWorld();
    $foreignSite = Site::factory()->create();
    $message = ConversationMessage::factory()->for($w['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $w['visitor']->id,
        'body' => 'See attached.',
    ]);
    ConversationMessageAttachment::factory()->create([
        'conversation_message_id' => $message->id,
        'conversation_id' => Conversation::factory()->for($foreignSite)->create([
            'visitor_id' => Visitor::factory()->for($foreignSite)->create()->id,
        ])->id,
        'account_id' => $foreignSite->account_id,
        'site_id' => $foreignSite->id,
        'original_filename' => 'foreign-secrets.pdf',
    ]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk()
        ->assertSee('See attached.')
        ->assertDontSee('foreign-secrets.pdf');
});

test('the account audit page names exactly what an operator reached', function (): void {
    // The audit UI hides raw metadata by design — the break-glass label
    // fields are references by construction, so they surface as the subject.
    $w = breakGlassViewerWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('Break Glass Resource Viewed')
        ->assertSee('Break-glass: Conversation '.$w['conversation']->support_code);
});

test('the transcript renders in chronological order, not insertion order', function (): void {
    $w = breakGlassViewerWorld();
    ConversationMessage::factory()->for($w['conversation'])->create([
        'sender_type' => Visitor::class,
        'sender_id' => $w['visitor']->id,
        'body' => 'Backfilled earlier message.',
        'created_at' => now()->subHour(),
    ]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk()
        ->assertSeeInOrder(['Backfilled earlier message.', 'My uploads keep failing.']);
});

test('audit search finds break-glass events by their surfaced label', function (): void {
    $w = breakGlassViewerWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$w['grant'], $w['conversation']]))
        ->assertOk();

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index', ['audit_search' => $w['conversation']->support_code]))
        ->assertOk()
        ->assertSee('Break-glass: Conversation '.$w['conversation']->support_code);

    $this->actingAs($admin)
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'WF-ZZZZZZZZ']))
        ->assertOk()
        ->assertDontSee('Break-glass: Conversation');
});

test('a non-operator cannot reach any viewer route', function (): void {
    $w = breakGlassViewerWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->get(route('operator.break-glass.show', $w['grant']))
        ->assertForbidden();
});
