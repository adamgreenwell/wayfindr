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

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.tickets.show', [$accountGrant, $ticket]))
        ->assertOk()
        ->assertSee('Upload pipeline failure');

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.tickets.show', [$accountGrant, $foreignTicket]))
        ->assertNotFound();

    expect(AuditEvent::where('action', 'break_glass.resource_viewed')->where('metadata->resource_type', 'ticket')->count())->toBe(1);
});

test('a non-operator cannot reach any viewer route', function (): void {
    $w = breakGlassViewerWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->get(route('operator.break-glass.show', $w['grant']))
        ->assertForbidden();
});
