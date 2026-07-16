<?php

// Break-glass hardening (ADR 0008, slice 5): the hosted shape end-to-end, the
// deactivated-actor matrix, and proof that a grant opens ONLY the break-glass
// viewers — never the ordinary dashboard of the account it touches. The
// per-slice suites prove each surface; this one proves the boundaries between
// them hold under the awkward shapes.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The hosted shape: the operator lives on the PLATFORM account, not the
 * customer account whose content a grant would open.
 */
function breakGlassHardeningWorld(): array
{
    $platformAccount = Account::factory()->create(['name' => 'Platform Co']);
    $operator = User::factory()->for($platformAccount)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);

    $customerAccount = Account::factory()->create(['name' => 'Customer Co']);
    $customerAdmin = User::factory()->for($customerAccount)->create(['account_role' => AccountRole::Admin]);
    $customerAgent = User::factory()->for($customerAccount)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($customerAccount)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'The checkout button does nothing.',
    ]);

    return compact('platformAccount', 'operator', 'customerAccount', 'customerAdmin', 'customerAgent', 'site', 'visitor', 'conversation');
}

test('the hosted flow works end to end: request, approve, view, and the account sees all of it', function (): void {
    $w = breakGlassHardeningWorld();

    // The external operator requests conversation access by support code.
    $this->actingAs($w['operator'])->post(route('operator.break-glass.store'), [
        'scope_type' => 'conversation',
        'support_code' => $w['conversation']->support_code,
        'reason' => 'Reported checkout failure needs transcript-level debugging.',
        'requested_minutes' => 60,
    ])->assertRedirect(route('operator.break-glass.index'));

    $grant = BreakGlassGrant::sole();

    // The external operator cannot self-approve — no standing on Customer Co.
    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.approve', $grant))
        ->assertForbidden();

    // Customer Co's admin sees it pending and approves.
    $this->actingAs($w['customerAdmin'])
        ->get(route('dashboard.account.break-glass.index'))
        ->assertOk()
        ->assertSee('Reported checkout failure needs transcript-level debugging.');

    $this->actingAs($w['customerAdmin'])
        ->post(route('dashboard.account.break-glass.approve', $grant))
        ->assertRedirect(route('dashboard.account.break-glass.index'));

    // The operator reads the transcript through the grant.
    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$grant->fresh(), $w['conversation']]))
        ->assertOk()
        ->assertSee('The checkout button does nothing.');

    // Customer Co's plain agent sees the live banner; the platform account's
    // own dashboard shows nothing (the grant is homed to the customer).
    $this->actingAs($w['customerAgent'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Platform operator access is active');

    $this->actingAs($w['operator'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Platform operator access is active');

    // The whole trail is homed to the customer account.
    expect(AuditEvent::where('account_id', $w['customerAccount']->id)->pluck('action')->sort()->values()->all())
        ->toBe(['break_glass.approved', 'break_glass.opened', 'break_glass.requested', 'break_glass.resource_viewed'])
        ->and(AuditEvent::where('account_id', $w['platformAccount']->id)->count())->toBe(0);
});

test('an active grant opens nothing on the ordinary dashboard of the account it touches', function (): void {
    // The grant authorizes the break-glass viewers, full stop: the operator
    // still cannot walk Customer Co's normal agent routes.
    $w = breakGlassHardeningWorld();
    BreakGlassGrant::factory()
        ->activeFor($w['customerAccount'], $w['operator'])
        ->create();

    $this->actingAs($w['operator'])
        ->get(route('dashboard.conversations.show', $w['conversation']->support_code))
        ->assertNotFound();

    $this->actingAs($w['operator'])
        ->get(route('dashboard.account.break-glass.index'))
        ->assertOk()
        ->assertDontSee('Customer Co'); // their own (empty) account page, not the customer's

    $this->actingAs($w['operator'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertDontSee('break_glass');
});

test('a deactivated operator is signed out, grant or no grant', function (): void {
    $w = breakGlassHardeningWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['customerAccount'], $w['operator'])
        ->create();

    $w['operator']->forceFill(['deactivated_at' => now()])->save();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.show', $grant))
        ->assertRedirect();

    $this->assertGuest();
});

test('a deactivated admin cannot approve, and their absence enables nothing by itself', function (): void {
    $w = breakGlassHardeningWorld();
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create(['requester_id' => $w['operator']->id, 'account_id' => $w['customerAccount']->id]);

    $w['customerAdmin']->forceFill(['deactivated_at' => now()])->save();

    $this->actingAs($w['customerAdmin'])
        ->post(route('dashboard.account.break-glass.approve', $grant))
        ->assertRedirect(); // signed out by EnsureAgentIsActive, not approved

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);

    // With every Customer Co admin deactivated, the external operator STILL
    // cannot self-approve — standing on the account is required, so the
    // request simply waits.
    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.approve', $grant))
        ->assertForbidden();

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);
});

test('scope columns beyond the declared scope type grant nothing', function (): void {
    // A site-scoped grant carrying a stray conversation_id must not widen
    // into conversation coverage elsewhere, and vice versa: coverage follows
    // scope_type alone.
    $w = breakGlassHardeningWorld();
    $otherSite = Site::factory()->for($w['customerAccount'])->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();
    $otherConversation = Conversation::factory()->for($otherSite)->create(['visitor_id' => $otherVisitor->id]);

    $grant = BreakGlassGrant::factory()
        ->activeFor($w['customerAccount'], $w['operator'])
        ->create([
            'scope_type' => BreakGlassGrant::SCOPE_SITE,
            'site_id' => $w['site']->id,
            // Stray reference to a conversation on ANOTHER site.
            'conversation_id' => $otherConversation->id,
        ]);

    expect($grant->coversConversation($w['conversation']))->toBeTrue()
        ->and($grant->coversConversation($otherConversation))->toBeFalse();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.conversations.show', [$grant, $otherConversation]))
        ->assertNotFound();
});
