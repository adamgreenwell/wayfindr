<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\VisitorSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * closed_at is nulled on reopen, so it cannot answer how long resolution took
 * or how often a resolution failed to hold. History has to be recorded as it
 * happens -- none of this can be backfilled (ADR 0015).
 */
function lifecycleWorld(): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['status' => 'open']);

    return compact('account', 'site', 'agent', 'visitor', 'conversation');
}

function lifecycleActions(): array
{
    return AuditEvent::query()
        ->whereIn('action', [ConversationLifecycleLog::CLOSED, ConversationLifecycleLog::REOPENED])
        ->orderBy('id')
        ->pluck('action')
        ->all();
}

test('closing a conversation is recorded with what it was before', function (): void {
    $w = lifecycleWorld();

    $this->actingAs($w['agent'])
        ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
        ->assertRedirect();

    $event = AuditEvent::query()->where('action', ConversationLifecycleLog::CLOSED)->firstOrFail();

    expect($event->subject_id)->toBe($w['conversation']->id)
        ->and($event->account_id)->toBe($w['account']->id)
        ->and($event->site_id)->toBe($w['site']->id)
        ->and($event->actor_id)->toBe($w['agent']->id)
        ->and($event->metadata['previous_status'])->toBe('open')
        ->and($event->metadata['actor'])->toBe('agent');
});

test('a visitor reply to a closed conversation is recorded as the reopen it is', function (): void {
    // The most interesting event in the product: the resolution did not hold.
    // It used to be indistinguishable from any other message.
    $w = lifecycleWorld();
    $w['conversation']->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    $this->postJson('/api/conversations/'.$w['conversation']->support_code.'/messages', [
        'site_public_key' => $w['site']->public_key,
        'anonymous_id' => $w['visitor']->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($w['site'], $w['visitor']),
        'body' => 'This is still broken.',
    ])->assertSuccessful();

    $event = AuditEvent::query()->where('action', ConversationLifecycleLog::REOPENED)->firstOrFail();

    expect($event->metadata['previous_status'])->toBe('closed')
        ->and($event->metadata['actor'])->toBe('visitor')
        ->and($event->actor_id)->toBe($w['visitor']->id);
});

test('replying to an open conversation records nothing', function (): void {
    // Only a transition is an event. Otherwise this writes a row per message.
    $w = lifecycleWorld();

    $this->postJson('/api/conversations/'.$w['conversation']->support_code.'/messages', [
        'site_public_key' => $w['site']->public_key,
        'anonymous_id' => $w['visitor']->anonymous_id,
        'visitor_token' => app(VisitorSessionToken::class)->issue($w['site'], $w['visitor']),
        'body' => 'One more thing.',
    ])->assertSuccessful();

    expect(lifecycleActions())->toBe([]);
});

test('an agent reply to a closed conversation is recorded as a reopen', function (): void {
    $w = lifecycleWorld();
    $w['conversation']->forceFill(['status' => 'closed', 'closed_at' => now()])->save();

    $this->actingAs($w['agent'])
        ->post(route('dashboard.conversations.messages.store', $w['conversation']->support_code), [
            'body' => 'Following up on this.',
        ])->assertRedirect();

    $event = AuditEvent::query()->where('action', ConversationLifecycleLog::REOPENED)->firstOrFail();

    expect($event->metadata['actor'])->toBe('agent')
        ->and($event->metadata['previous_status'])->toBe('closed');
});

test('the whole sequence survives, which is the point', function (): void {
    // closed_at can only ever hold the most recent close. The question a
    // support lead asks -- did this keep coming back? -- needs the sequence.
    $w = lifecycleWorld();
    $token = app(VisitorSessionToken::class)->issue($w['site'], $w['visitor']);

    foreach (range(1, 2) as $round) {
        $this->actingAs($w['agent'])
            ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
            ->assertRedirect();

        $this->postJson('/api/conversations/'.$w['conversation']->support_code.'/messages', [
            'site_public_key' => $w['site']->public_key,
            'anonymous_id' => $w['visitor']->anonymous_id,
            'visitor_token' => $token,
            'body' => 'Still not fixed, round '.$round.'.',
        ])->assertSuccessful();
    }

    $this->actingAs($w['agent'])
        ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
        ->assertRedirect();

    expect(lifecycleActions())->toBe([
        ConversationLifecycleLog::CLOSED,
        ConversationLifecycleLog::REOPENED,
        ConversationLifecycleLog::CLOSED,
        ConversationLifecycleLog::REOPENED,
        ConversationLifecycleLog::CLOSED,
    ]);

    // The column keeps only the last one, which is exactly why the events exist.
    expect($w['conversation']->fresh()->closed_at)->not->toBeNull();
});

test('lifecycle history is scoped to the account that owns it', function (): void {
    $w = lifecycleWorld();
    $other = Account::factory()->create();

    $this->actingAs($w['agent'])
        ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
        ->assertRedirect();

    expect(AuditEvent::query()->where('account_id', $other->id)->count())->toBe(0)
        ->and(AuditEvent::query()->where('account_id', $w['account']->id)->count())->toBeGreaterThan(0);
});

test('a close submitted twice records one close', function (): void {
    // A double-click, a retry, or a stale page. Two consecutive closes with no
    // reopen between corrupts the count and every interval derived from it.
    $w = lifecycleWorld();

    foreach (range(1, 2) as $ignored) {
        $this->actingAs($w['agent'])
            ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
            ->assertRedirect();
    }

    expect(lifecycleActions())->toBe([ConversationLifecycleLog::CLOSED]);
});

test('the audit log names which conversation, and finds it by support code', function (): void {
    // Without this every entry reads "Account": an admin sees that something
    // was closed and cannot tell which of a thousand conversations it was.
    $w = lifecycleWorld();

    $this->actingAs($w['agent'])
        ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
        ->assertRedirect();

    $this->actingAs($w['agent'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('Conversation '.$w['conversation']->support_code);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.account.audit.index', ['audit_search' => $w['conversation']->support_code]))
        ->assertOk()
        ->assertSee('Conversation '.$w['conversation']->support_code);

    $this->actingAs($w['agent'])
        ->get(route('dashboard.account.audit.index', ['audit_search' => 'WF-NOTHINGLIKEIT']))
        ->assertOk()
        ->assertDontSee('Conversation '.$w['conversation']->support_code);
});

test('the audit log shows the code, never the visitor-authored subject', function (): void {
    // This page is exported. A subject line is visitor-authored text; a support
    // code is a reference by construction, which is the rule the break-glass
    // and cobrowse labels already follow.
    $w = lifecycleWorld();
    $w['conversation']->forceFill(['subject' => 'My card number is 4111 1111 1111 1111'])->save();

    $this->actingAs($w['agent'])
        ->post(route('dashboard.conversations.close', $w['conversation']->support_code))
        ->assertRedirect();

    $this->actingAs($w['agent'])
        ->get(route('dashboard.account.audit.index'))
        ->assertOk()
        ->assertSee('Conversation '.$w['conversation']->support_code)
        ->assertDontSee('4111 1111 1111 1111');
});

// NOT COVERED BY A TEST, deliberately: the concurrent-close race needs two
// simultaneous requests against a real database, and the suite runs SQLite
// in-memory on one connection. A test that constructs a "stale" instance proves
// nothing, because conversationForAgent() loads its own fresh copy either way --
// the first version of this test passed with the lock removed.
//
// What is covered is the transition guard above ("a close submitted twice
// records one close"). The lock is what makes that guard hold when the two
// requests overlap rather than follow each other.
