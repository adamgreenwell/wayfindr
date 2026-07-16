<?php

// The break-glass grant model + lifecycle (ADR 0008, slice 1): request ->
// approve/self-approve or deny -> close/expire, every transition audited; and
// the scope-coverage matrix proving a grant opens exactly what it names —
// never a sibling, never another site, never another account, never anything
// once expired or closed. No UI exists yet; this is the foundation everything
// else sits on.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\BreakGlass\BreakGlassGrants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function breakGlassWorld(): array
{
    $account = Account::factory()->create();
    // The operator belongs to the account (the common self-hosted shape) and is
    // its owner by default; tests override roles as needed.
    $operator = User::factory()->for($account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->create(['visitor_id' => $visitor->id]);

    return compact('account', 'operator', 'site', 'visitor', 'conversation');
}

function grants(): BreakGlassGrants
{
    return app(BreakGlassGrants::class);
}

// --- Requesting -------------------------------------------------------------

test('an operator requests a conversation-scoped grant with a reason', function (): void {
    $w = breakGlassWorld();

    $grant = grants()->request($w['operator'], $w['conversation'], 'Visitor reports a corrupted transcript.');

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_REQUESTED)
        ->and($grant->scope_type)->toBe(BreakGlassGrant::SCOPE_CONVERSATION)
        ->and($grant->account_id)->toBe($w['account']->id)
        ->and($grant->conversation_id)->toBe($w['conversation']->id)
        ->and($grant->site_id)->toBe($w['site']->id)
        ->and($grant->requested_minutes)->toBe(BreakGlassGrant::DEFAULT_MINUTES)
        ->and($grant->expires_at)->toBeNull();

    expect(AuditEvent::where('action', 'break_glass.requested')->count())->toBe(1);
});

test('a non-operator cannot request break-glass access', function (): void {
    $w = breakGlassWorld();
    $agent = User::factory()->for($w['account'])->create(['platform_role' => null]);

    expect(fn () => grants()->request($agent, $w['conversation'], 'Curious.'))
        ->toThrow(HttpException::class);
});

test('a blank reason is rejected', function (): void {
    $w = breakGlassWorld();

    expect(fn () => grants()->request($w['operator'], $w['account'], '   '))
        ->toThrow(ValidationException::class);
});

test('duration is bounded to the 24-hour maximum', function (): void {
    $w = breakGlassWorld();

    expect(fn () => grants()->request($w['operator'], $w['account'], 'Long investigation.', 1441))
        ->toThrow(ValidationException::class);
});

test('a request that cannot be audited leaves no grant behind', function (): void {
    // Grant and trail commit atomically — a grant with no account-visible
    // audit event must never exist, even transiently.
    $w = breakGlassWorld();
    AuditEvent::creating(function (): void {
        throw new RuntimeException('audit store down');
    });

    expect(fn () => grants()->request($w['operator'], $w['conversation'], 'Debugging.'))
        ->toThrow(RuntimeException::class);
    expect(BreakGlassGrant::count())->toBe(0);
});

// --- Approval ----------------------------------------------------------------

test('an account admin approves and the grant activates with a stamped expiry', function (): void {
    $w = breakGlassWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $grant = grants()->request($w['operator'], $w['conversation'], 'Debugging.', 90);
    $grant = grants()->approve($grant, $admin);

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_ACTIVE)
        ->and($grant->self_approved)->toBeFalse()
        ->and($grant->approver_id)->toBe($admin->id)
        ->and((int) $grant->approved_at->diffInMinutes($grant->expires_at))->toBe(90);

    expect(AuditEvent::where('action', 'break_glass.approved')->count())->toBe(1);
});

test('self-approval is refused while another admin exists', function (): void {
    $w = breakGlassWorld();
    User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $grant = grants()->request($w['operator'], $w['conversation'], 'Debugging.');

    expect(fn () => grants()->approve($grant, $w['operator']))
        ->toThrow(HttpException::class);
});

test('self-approval works on a single-human install and is marked as such', function (): void {
    $w = breakGlassWorld(); // operator is the only owner/admin

    $grant = grants()->request($w['operator'], $w['conversation'], 'Debugging.');
    $grant = grants()->approve($grant, $w['operator']);

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_ACTIVE)
        ->and($grant->self_approved)->toBeTrue();

    expect(AuditEvent::where('action', 'break_glass.self_approved')->count())->toBe(1);
});

test('an operator without standing on the account cannot self-approve, even with no admin left', function (): void {
    // Self-approval is the single-human-install fallback, never a hosted
    // bypass: an operator who is not an owner/admin OF THE TARGET ACCOUNT has
    // no consent to give, even when that account has no active approver at
    // all. The request simply waits for one.
    $w = breakGlassWorld();
    $adminlessAccount = Account::factory()->create(); // no owner/admin users
    $adminlessSite = Site::factory()->for($adminlessAccount)->create();

    $grant = grants()->request($w['operator'], $adminlessSite, 'Storage audit.');

    expect(fn () => grants()->approve($grant, $w['operator']))
        ->toThrow(HttpException::class);
    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);
});

test('a plain-agent operator cannot self-approve even when no admin exists', function (): void {
    $w = breakGlassWorld();
    $w['operator']->update(['account_role' => AccountRole::Agent]);

    $grant = grants()->request($w['operator'], $w['conversation'], 'Transcript check.');

    expect(fn () => grants()->approve($grant, $w['operator']))
        ->toThrow(HttpException::class);
    expect($grant->fresh()->status)->toBe(BreakGlassGrant::STATUS_REQUESTED);
});

test('a plain agent cannot approve', function (): void {
    $w = breakGlassWorld();
    $agent = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Agent]);

    $grant = grants()->request($w['operator'], $w['conversation'], 'Debugging.');

    expect(fn () => grants()->approve($grant, $agent))
        ->toThrow(HttpException::class);
});

test('an admin from another account cannot approve', function (): void {
    $w = breakGlassWorld();
    $foreignAdmin = User::factory()->for(Account::factory()->create())->create(['account_role' => AccountRole::Admin]);

    $grant = grants()->request($w['operator'], $w['conversation'], 'Debugging.');

    expect(fn () => grants()->approve($grant, $foreignAdmin))
        ->toThrow(HttpException::class);
});

test('an already-approved grant cannot be approved again', function (): void {
    $w = breakGlassWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $grant = grants()->request($w['operator'], $w['conversation'], 'Debugging.');
    grants()->approve($grant, $admin);

    expect(fn () => grants()->approve($grant->fresh(), $admin))
        ->toThrow(HttpException::class);
});

test('an admin denies a request and the trail records it', function (): void {
    $w = breakGlassWorld();
    $admin = User::factory()->for($w['account'])->create(['account_role' => AccountRole::Admin]);

    $grant = grants()->request($w['operator'], $w['account'], 'Broad look.');
    $grant = grants()->deny($grant, $admin);

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_DENIED)
        ->and($grant->isActive())->toBeFalse();

    expect(AuditEvent::where('action', 'break_glass.denied')->count())->toBe(1);
});

// --- Close / expire -----------------------------------------------------------

test('the requester closes an active grant early', function (): void {
    $w = breakGlassWorld();
    $grant = grants()->approve(grants()->request($w['operator'], $w['conversation'], 'Debugging.'), $w['operator']);

    $grant = grants()->close($grant, $w['operator']);

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_CLOSED)
        ->and($grant->isActive())->toBeFalse();

    expect(AuditEvent::where('action', 'break_glass.closed')->count())->toBe(1);
});

test('closing an overdue grant records expiry, never an early close', function (): void {
    // In the gap between expires_at and the scheduled sweep the row still
    // says active; a close attempt must stamp the honest terminal state — the
    // trail can never claim a ran-out grant was closed early.
    $w = breakGlassWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create(['expires_at' => now()->subMinutes(5)]);

    $grant = grants()->close($grant, $w['operator']);

    expect($grant->status)->toBe(BreakGlassGrant::STATUS_EXPIRED)
        ->and($grant->closed_at)->toBeNull();

    expect(AuditEvent::where('action', 'break_glass.expired')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'break_glass.closed')->count())->toBe(0);
});

test('a grant past its expiry is inactive live, before the sweep stamps it', function (): void {
    $w = breakGlassWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToConversation($w['conversation'])
        ->create(['expires_at' => now()->subMinute()]);

    expect($grant->isActive())->toBeFalse()
        ->and($grant->coversConversation($w['conversation']))->toBeFalse();
});

test('the scheduled sweep stamps overdue grants expired and audits it', function (): void {
    $w = breakGlassWorld();
    BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create(['expires_at' => now()->subMinutes(5)]);

    $this->artisan('wayfindr:expire-break-glass-grants')
        ->expectsOutputToContain('Expired 1 break-glass grant')
        ->assertSuccessful();

    expect(BreakGlassGrant::where('status', BreakGlassGrant::STATUS_EXPIRED)->count())->toBe(1)
        ->and(AuditEvent::where('action', 'break_glass.expired')->count())->toBe(1);
});

// --- Scope coverage matrix ------------------------------------------------------

test('a conversation-scoped grant covers exactly its conversation and linked tickets', function (): void {
    $w = breakGlassWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToConversation($w['conversation'])
        ->create();

    $siblingConversation = Conversation::factory()->for($w['site'])->create(['visitor_id' => $w['visitor']->id]);
    $linkedTicket = Ticket::factory()->for($w['account'])->for($w['site'])->for($w['conversation'])->create();
    $unlinkedTicket = Ticket::factory()->for($w['account'])->for($w['site'])->create();

    expect($grant->coversConversation($w['conversation']))->toBeTrue()
        ->and($grant->coversConversation($siblingConversation))->toBeFalse()
        ->and($grant->coversTicket($linkedTicket))->toBeTrue()
        ->and($grant->coversTicket($unlinkedTicket))->toBeFalse()
        ->and($grant->coversSite($w['site']))->toBeFalse();
});

test('a site-scoped grant covers the site\'s conversations and tickets, not a sibling site', function (): void {
    $w = breakGlassWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToSite($w['site'])
        ->create();

    $otherSite = Site::factory()->for($w['account'])->create();
    $otherVisitor = Visitor::factory()->for($otherSite)->create();
    $otherConversation = Conversation::factory()->for($otherSite)->create(['visitor_id' => $otherVisitor->id]);
    $siteTicket = Ticket::factory()->for($w['account'])->for($w['site'])->create();

    expect($grant->coversConversation($w['conversation']))->toBeTrue()
        ->and($grant->coversTicket($siteTicket))->toBeTrue()
        ->and($grant->coversSite($w['site']))->toBeTrue()
        ->and($grant->coversConversation($otherConversation))->toBeFalse()
        ->and($grant->coversSite($otherSite))->toBeFalse();
});

test('an account-scoped grant covers the account and nothing beyond it', function (): void {
    $w = breakGlassWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create(); // account scope is the factory default

    $foreignSite = Site::factory()->create();
    $foreignVisitor = Visitor::factory()->for($foreignSite)->create();
    $foreignConversation = Conversation::factory()->for($foreignSite)->create(['visitor_id' => $foreignVisitor->id]);
    $foreignTicket = Ticket::factory()->create();

    expect($grant->coversConversation($w['conversation']))->toBeTrue()
        ->and($grant->coversSite($w['site']))->toBeTrue()
        ->and($grant->coversConversation($foreignConversation))->toBeFalse()
        ->and($grant->coversTicket($foreignTicket))->toBeFalse()
        ->and($grant->coversSite($foreignSite))->toBeFalse();
});

test('a mismatched scope row can never cross accounts, whatever it claims', function (): void {
    // Defense-in-depth: even a (hypothetically corrupted) grant whose
    // conversation_id points into another account is refused, because coverage
    // re-derives the account from the RESOURCE side.
    $w = breakGlassWorld();
    $foreignSite = Site::factory()->create();
    $foreignVisitor = Visitor::factory()->for($foreignSite)->create();
    $foreignConversation = Conversation::factory()->for($foreignSite)->create(['visitor_id' => $foreignVisitor->id]);

    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create([
            'scope_type' => BreakGlassGrant::SCOPE_CONVERSATION,
            'conversation_id' => $foreignConversation->id,
            'site_id' => $foreignSite->id,
        ]);

    expect($grant->coversConversation($foreignConversation))->toBeFalse();
});

test('a conversation-scoped grant refuses a same-account ticket from another site', function (): void {
    // A ticket row on a sibling site claiming the covered conversation is
    // inconsistent — conversation coverage requires the conversation's own
    // site, not just the same account.
    $w = breakGlassWorld();
    $siblingSite = Site::factory()->for($w['account'])->create();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToConversation($w['conversation'])
        ->create();

    $mismatchedTicket = Ticket::factory()
        ->for($w['account'])
        ->for($siblingSite)
        ->create(['conversation_id' => $w['conversation']->id]);

    expect($grant->coversTicket($mismatchedTicket))->toBeFalse();
});

test('a ticket whose claimed account disagrees with its site is covered by neither account', function (): void {
    // Same defense-in-depth for tickets: coverage re-derives ownership through
    // the ticket's site, so a row claiming account A while sitting on account
    // B's site satisfies no grant on either side.
    $w = breakGlassWorld();
    $foreignSite = Site::factory()->create();
    $mismatchedTicket = Ticket::factory()->for($w['account'])->for($foreignSite)->create();

    $grantOnClaimedAccount = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->create(); // account scope

    $foreignOperator = User::factory()->for($foreignSite->account)->create([
        'platform_role' => 'operator',
        'account_role' => AccountRole::Owner,
    ]);
    $grantOnSiteAccount = BreakGlassGrant::factory()
        ->activeFor($foreignSite->account, $foreignOperator)
        ->create();

    expect($grantOnClaimedAccount->coversTicket($mismatchedTicket))->toBeFalse()
        ->and($grantOnSiteAccount->coversTicket($mismatchedTicket))->toBeFalse();
});

// --- Retention ----------------------------------------------------------------

test('the grant and its trail outlive the conversation it scoped', function (): void {
    // The grant row is the accountability record (ADR 0008): pruning the
    // conversation it exposed nulls the reference but keeps the grant, its
    // audit events, and an honest label — and the nulled scope covers nothing.
    $w = breakGlassWorld();
    $grant = grants()->request($w['operator'], $w['conversation'], 'Visitor reports a corrupted transcript.');
    $grant = grants()->approve($grant, $w['operator']); // single-admin world: self-approval
    $trailBefore = $grant->auditEvents()->count();

    $w['conversation']->delete();

    $grant = $grant->fresh();
    $survivor = Conversation::factory()->for($w['site'])->create(['visitor_id' => $w['visitor']->id]);

    expect($grant)->not->toBeNull()
        ->and($grant->conversation_id)->toBeNull()
        ->and($grant->status)->toBe(BreakGlassGrant::STATUS_ACTIVE)
        ->and($grant->auditEvents()->count())->toBe($trailBefore)
        ->and($grant->scopeLabel())->toBe('Conversation (deleted)')
        ->and($grant->coversConversation($survivor))->toBeFalse();
});

test('the grant and its trail outlive the site it scoped', function (): void {
    // audit_events.site_id cascades on site deletion, so break-glass events
    // are account-homed only — the trail survives the site along with the
    // grant row.
    $w = breakGlassWorld();
    $grant = grants()->request($w['operator'], $w['site'], 'Storage audit.');
    $grant = grants()->approve($grant, $w['operator']); // single-admin world: self-approval
    $trailBefore = $grant->auditEvents()->count();

    $w['site']->delete();

    $grant = $grant->fresh();
    $survivorSite = Site::factory()->for($w['account'])->create();

    expect($grant)->not->toBeNull()
        ->and($grant->site_id)->toBeNull()
        ->and($grant->auditEvents()->count())->toBe($trailBefore)
        ->and($grant->scopeLabel())->toBe('Site (deleted)')
        ->and($grant->coversSite($survivorSite))->toBeFalse();
});

test('a closed grant covers nothing', function (): void {
    $w = breakGlassWorld();
    $grant = BreakGlassGrant::factory()
        ->activeFor($w['account'], $w['operator'])
        ->scopedToConversation($w['conversation'])
        ->create();

    grants()->close($grant, $w['operator']);

    expect($grant->fresh()->coversConversation($w['conversation']))->toBeFalse();
});

// --- Audit metadata ----------------------------------------------------------

test('audit metadata names scope and reason, never content', function (): void {
    $w = breakGlassWorld();
    $grant = grants()->request($w['operator'], $w['conversation'], 'Visitor reports a corrupted transcript.');

    $event = AuditEvent::where('action', 'break_glass.requested')->first();

    expect(data_get($event->metadata, 'scope_type'))->toBe('conversation')
        ->and(data_get($event->metadata, 'scope_label'))->toContain($w['conversation']->support_code)
        ->and(data_get($event->metadata, 'reason'))->toBe('Visitor reports a corrupted transcript.')
        ->and($event->account_id)->toBe($w['account']->id);
});
