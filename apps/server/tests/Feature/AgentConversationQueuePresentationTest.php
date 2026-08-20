<?php

// The rebuilt conversation queue (ADR 0014, step 5). These pin the decisions
// that are easy to undo by accident: colour that means something, chrome that
// does not come back, and a site rail wired to the site's own colour.

use App\Enums\SiteColor;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function queueAgentAndConversation(SiteColor $color = SiteColor::Violet): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs', 'color' => $color]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-queue']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-QUEUE01',
        'subject' => 'Checkout fails',
        'status' => 'open',
    ]);

    return [$agent, $conversation];
}

test('each row carries its site colour as a rail', function (): void {
    [$agent] = queueAgentAndConversation(SiteColor::Violet);

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('--wf-row-site: var(--wf-site-violet)', false);
});

test('the lanes carry their own counts, so the separate snapshot band is gone', function (): void {
    [$agent] = queueAgentAndConversation();

    $response = $this->actingAs($agent)->get('/dashboard/conversations')->assertOk();

    $response->assertSee('aria-label="Conversation lanes"', false)
        ->assertSee('class="wf-lane-count"', false)
        // Three stacked bands is what this replaces.
        ->assertDontSee('Queue snapshot');
});

test('a resting cobrowse state is not coloured as a warning', function (): void {
    // Amber used to mark "Unavailable" and "Quiet" -- the resting states of
    // nearly every row -- which made a calm queue look like a problem.
    [$agent] = queueAgentAndConversation();

    $response = $this->actingAs($agent)->get('/dashboard/conversations')->assertOk();

    expect($response->getContent())
        ->toContain('class="wf-queue-cobrowse"')
        ->toContain('Unavailable')
        // The transport is still stated; it just is not painted as a warning.
        ->not->toContain('wf-queue-cobrowse" data-tone');
});

test('the support code is set in mono beside the subject it belongs to', function (): void {
    [$agent] = queueAgentAndConversation();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('class="support-reference"', false)
        ->assertSee('WF-QUEUE01');
});

test('the queue still says how many match and how many are shown', function (): void {
    // Dropping this in the rebuild lost the pagination context entirely, which
    // is why it is asserted rather than left to the eye.
    [$agent] = queueAgentAndConversation();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('class="wf-queue-summary"', false)
        ->assertSee('Showing 1 conversation matching the current queue filters.');
});

test('searchable references are explained on the field that searches them', function (): void {
    [$agent] = queueAgentAndConversation();

    $this->actingAs($agent)
        ->get('/dashboard/conversations')
        ->assertOk()
        ->assertSee('class="wf-filter-help"', false)
        ->assertSee('Search by subject, support code, visitor ID, visitor name, or visitor email.');
});

// ── Queue switcher (ADR 0014) ───────────────────────────────────────────────

test('the switcher walks the queue the agent came from, in the queue order', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    foreach ([['WF-AAA1', 'First', 3], ['WF-BBB2', 'Second', 2], ['WF-CCC3', 'Third', 1]] as [$code, $subject, $agoMinutes]) {
        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => $code,
            'subject' => $subject,
            'status' => 'open',
            'last_message_at' => now()->subMinutes($agoMinutes),
        ]);
    }

    // Queue order is last_message_at desc, so: Third, Second, First.
    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-BBB2?from_queue=1')
        ->assertOk()
        ->assertSee('aria-label="Move through the conversation queue"', false)
        ->assertSee('2 of 3')
        ->assertSee(route('dashboard.conversations.show', ['supportCode' => 'WF-CCC3']), false)
        ->assertSee(route('dashboard.conversations.show', ['supportCode' => 'WF-AAA1']), false);
});

test('a conversation outside the queue offers no neighbours rather than wrong ones', function (): void {
    // Opened from search or a notification, or replied to and no longer in the
    // lane. Offering neighbours from a list it is not part of would be a lie
    // about where "next" goes.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-CLOSED1',
        'subject' => 'Resolved',
        'status' => 'closed',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-CLOSED1?from_queue=1')
        ->assertOk()
        ->assertDontSee('aria-label="Move through the conversation queue"', false);
});

test('the switcher never names a conversation from another account', function (): void {
    // The real visibility boundary, as opposed to the panel rules: the switcher
    // reads the same visibleTo scope the queue does.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MINE1',
        'subject' => 'My conversation',
        'status' => 'open',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-MINE2',
        'subject' => 'My other conversation',
        'status' => 'open',
    ]);

    $strangerSite = Site::factory()->for(Account::factory()->create())->create();
    $strangerVisitor = Visitor::factory()->for($strangerSite)->create();
    Conversation::factory()->for($strangerSite)->for($strangerVisitor)->create([
        'support_code' => 'WF-STRANGER',
        'subject' => 'Somebody else entirely',
        'status' => 'open',
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-MINE1?from_queue=1')
        ->assertOk()
        ->assertSee('aria-label="Move through the conversation queue"', false)
        ->assertDontSee('Somebody else entirely')
        ->assertDontSee('WF-STRANGER');
});

test('the switcher survives the new-activity lane marking the conversation read', function (): void {
    // Opening a conversation marks it read, and the new-activity lane is
    // DEFINED by unread state -- so computing siblings after that removed the
    // conversation from its own list and hid the control entirely.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    foreach (['WF-NEW1', 'WF-NEW2'] as $index => $code) {
        $conversation = Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => $code,
            'subject' => 'Unread '.$code,
            'status' => 'open',
            'last_message_at' => now()->subMinutes($index + 1),
        ]);
        ConversationMessage::factory()->for($conversation)->create([
            'sender_type' => Visitor::class,
            'sender_id' => $visitor->id,
            'body' => 'Please help.',
        ]);
    }

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-NEW1?from_queue=1&conversation_filter=new_activity')
        ->assertOk()
        ->assertSee('aria-label="Move through the conversation queue"', false)
        ->assertSee('1 of 2');
});

test('a conversation opened outside a queue offers no neighbours', function (): void {
    // Notifications, tickets, the visitor page and support-code lookup all link
    // without queue parameters. Treating that as the all-open queue would offer
    // neighbours from a list the agent never navigated.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    foreach (['WF-DIRECT1', 'WF-DIRECT2'] as $code) {
        Conversation::factory()->for($site)->for($visitor)->create([
            'support_code' => $code,
            'subject' => 'Open '.$code,
            'status' => 'open',
        ]);
    }

    $this->actingAs($agent)
        ->get('/dashboard/conversations/WF-DIRECT1')
        ->assertOk()
        ->assertDontSee('aria-label="Move through the conversation queue"', false);
});
