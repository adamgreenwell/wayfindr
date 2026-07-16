<?php

// The break-glass request/approval flow over HTTP (ADR 0008, slice 2): the
// operator console requests and self-approves/closes; the account's
// owner/admin page approves, denies, and revokes. Lifecycle rules are proven
// in BreakGlassGrantTest — this suite proves the surfaces route through them
// and never leak across accounts.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function breakGlassFlowWorld(): array
{
    $account = Account::factory()->create();
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id]);

    return compact('account', 'operator', 'site', 'visitor', 'conversation');
}

// --- Operator console ---------------------------------------------------------

test('the operator console break-glass page renders the request form', function (): void {
    $w = breakGlassFlowWorld();

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.index'))
        ->assertOk()
        ->assertSee('Request access')
        ->assertSee('Support code (conversation scope)');
});

test('a non-operator cannot reach the break-glass console', function (): void {
    $w = breakGlassFlowWorld();
    $agent = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($agent)
        ->get(route('operator.break-glass.index'))
        ->assertForbidden();
});

test('an operator requests conversation access by support code', function (): void {
    $w = breakGlassFlowWorld();

    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.store'), [
            'scope_type' => 'conversation',
            'support_code' => strtolower($w['conversation']->support_code),
            'reason' => 'Visitor reports a corrupted transcript.',
            'requested_minutes' => 60,
        ])
        ->assertRedirect(route('operator.break-glass.index'))
        ->assertSessionHas('status');

    $grant = BreakGlassGrant::sole();

    expect($grant->scope_type)->toBe(BreakGlassGrant::SCOPE_CONVERSATION)
        ->and($grant->conversation_id)->toBe($w['conversation']->id)
        ->and($grant->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);

    expect(AuditEvent::where('action', 'break_glass.requested')->count())->toBe(1);
});

test('an unknown support code is rejected with no grant created', function (): void {
    $w = breakGlassFlowWorld();

    $this->actingAs($w['operator'])
        ->from(route('operator.break-glass.index'))
        ->post(route('operator.break-glass.store'), [
            'scope_type' => 'conversation',
            'support_code' => 'WF-NOPE',
            'reason' => 'Debugging.',
            'requested_minutes' => 60,
        ])
        ->assertRedirect(route('operator.break-glass.index'))
        ->assertSessionHasErrors('support_code');

    expect(BreakGlassGrant::count())->toBe(0);
});

test('an operator requests site and account scopes from the form', function (): void {
    $w = breakGlassFlowWorld();

    $this->actingAs($w['operator'])->post(route('operator.break-glass.store'), [
        'scope_type' => 'site',
        'site_id' => $w['site']->id,
        'reason' => 'Widget install audit.',
        'requested_minutes' => 240,
    ])->assertRedirect(route('operator.break-glass.index'));

    $this->actingAs($w['operator'])->post(route('operator.break-glass.store'), [
        'scope_type' => 'account',
        'account_id' => $w['account']->id,
        'reason' => 'Data migration verification.',
        'requested_minutes' => 1440,
    ])->assertRedirect(route('operator.break-glass.index'));

    expect(BreakGlassGrant::where('scope_type', BreakGlassGrant::SCOPE_SITE)->count())->toBe(1)
        ->and(BreakGlassGrant::where('scope_type', BreakGlassGrant::SCOPE_ACCOUNT)->count())->toBe(1);
});

test('a sole-admin operator self-approves from the console', function (): void {
    $w = breakGlassFlowWorld(); // operator is the only owner/admin
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create(['requester_id' => $w['operator']->id, 'account_id' => $w['account']->id]);

    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.approve', $grant))
        ->assertRedirect(route('operator.break-glass.index'));

    $grant = $grant->fresh();

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_ACTIVE)
        ->and($grant->self_approved)->toBeTrue();
});

test('self-approval from the console is refused while another admin exists', function (): void {
    $w = breakGlassFlowWorld();
    User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create(['requester_id' => $w['operator']->id, 'account_id' => $w['account']->id]);

    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.approve', $grant))
        ->assertForbidden();

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);
});

test('an operator cannot drive another requester\'s grant from the console', function (): void {
    $w = breakGlassFlowWorld();
    $otherOperator = User::factory()->for($w['account'])->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Admin,
    ]);
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create(['requester_id' => $otherOperator->id, 'account_id' => $w['account']->id]);

    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.approve', $grant))
        ->assertNotFound();
});

test('an operator closes their own active grant from the console', function (): void {
    $w = breakGlassFlowWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create();

    $this->actingAs($w['operator'])
        ->post(route('operator.break-glass.close', $grant))
        ->assertRedirect(route('operator.break-glass.index'));

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_CLOSED);
});

test('an operator\'s open grant never scrolls out behind their own history', function (): void {
    // Mirror of the account-page rule: the self-approve and close actions
    // must surface however much terminal history the operator accumulates.
    $w = breakGlassFlowWorld();

    $pending = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create([
            'requester_id' => $w['operator']->id,
            'account_id' => $w['account']->id,
            'reason' => 'The oldest still-open request.',
        ]);

    BreakGlassGrant::factory()->count(25)->create([
        'account_id' => $w['account']->id,
        'requester_id' => $w['operator']->id,
        'status' => BreakGlassGrant::STATUS_DENIED,
    ]);

    $this->actingAs($w['operator'])
        ->get(route('operator.break-glass.index'))
        ->assertOk()
        ->assertSee('The oldest still-open request.');
});

// --- Account approval page ------------------------------------------------------

test('an account admin sees pending requests and approves one', function (): void {
    $w = breakGlassFlowWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create([
            'requester_id' => $w['operator']->id,
            'account_id' => $w['account']->id,
            'reason' => 'Visitor reports a corrupted transcript.',
        ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.break-glass.index'))
        ->assertOk()
        ->assertSee('Awaiting your approval')
        ->assertSee('Visitor reports a corrupted transcript.');

    $this->actingAs($admin)
        ->post(route('dashboard.account.break-glass.approve', $grant))
        ->assertRedirect(route('dashboard.account.break-glass.index'));

    $grant = $grant->fresh();

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_ACTIVE)
        ->and($grant->self_approved)->toBeFalse()
        ->and((int) $grant->approver_id)->toBe($admin->id);
});

test('an account admin denies a request', function (): void {
    $w = breakGlassFlowWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create(['requester_id' => $w['operator']->id, 'account_id' => $w['account']->id]);

    $this->actingAs($admin)
        ->post(route('dashboard.account.break-glass.deny', $grant))
        ->assertRedirect(route('dashboard.account.break-glass.index'));

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_DENIED);
});

test('an account admin revokes an active grant', function (): void {
    $w = breakGlassFlowWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create();

    $this->actingAs($admin)
        ->post(route('dashboard.account.break-glass.close', $grant))
        ->assertRedirect(route('dashboard.account.break-glass.index'));

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_CLOSED);
});

test('an old pending request never scrolls out behind newer history rows', function (): void {
    // Open grants are uncapped: the approval queue and revoke buttons must
    // surface however much terminal history piles up on top of them.
    $w = breakGlassFlowWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $pending = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create([
            'requester_id' => $w['operator']->id,
            'account_id' => $w['account']->id,
            'reason' => 'The oldest still-open request.',
        ]);

    BreakGlassGrant::factory()->count(55)->create([
        'account_id' => $w['account']->id,
        'requester_id' => $w['operator']->id,
        'status' => BreakGlassGrant::STATUS_DENIED,
    ]);

    $this->actingAs($admin)
        ->get(route('dashboard.account.break-glass.index'))
        ->assertOk()
        ->assertSee('The oldest still-open request.');
});

test('a plain agent cannot open the operator-access page', function (): void {
    $w = breakGlassFlowWorld();
    $agent = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Agent]);

    $this->actingAs($agent)
        ->get(route('dashboard.account.break-glass.index'))
        ->assertForbidden();
});

test('an admin of another account cannot see or drive a foreign grant', function (): void {
    $w = breakGlassFlowWorld();
    $foreignAccount = Account::factory()->create();
    $foreignAdmin = User::factory()->for($foreignAccount)->create(['account_role' => AccountRole::Owner]);
    $grant = BreakGlassGrant::factory()
        ->scopedToConversation($w['conversation'])
        ->create(['requester_id' => $w['operator']->id, 'account_id' => $w['account']->id]);

    $this->actingAs($foreignAdmin)
        ->get(route('dashboard.account.break-glass.index'))
        ->assertOk()
        ->assertDontSee($grant->reason);

    $this->actingAs($foreignAdmin)
        ->post(route('dashboard.account.break-glass.approve', $grant))
        ->assertNotFound();

    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);
});
