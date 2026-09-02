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
use App\Support\Conversations\ConversationQueueQuery;
use App\Support\ReaderNumber;
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

test('the queue renders at most one page of rows and says how many there are', function (): void {
    // The queue rendered EVERY matching row: 187 MB of HTML after twenty-three
    // seconds on a year of a busy desk (#837). A page nobody can load is not a
    // queue.
    //
    // The cap is on the rows only. The count beside them is not capped, because
    // reporting the cap as the total is the one number an agent would have
    // trusted -- the same shape the live visitor board already uses.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    $over = ConversationQueueQuery::DISPLAY_LIMIT + 25;

    Conversation::factory()
        ->count($over)
        ->for($site)
        ->for(Visitor::factory()->for($site))
        ->create(['status' => 'open']);

    $response = $this->actingAs($agent)->get('/dashboard/conversations');

    $response->assertOk();

    $rendered = $response->viewData('conversations');

    expect($rendered)->toHaveCount(ConversationQueueQuery::DISPLAY_LIMIT,
        'the queue rendered more rows than it caps at');

    // And it reports the real total, not the cap.
    expect($response->viewData('conversationsShownOf'))->toBe($over,
        'the queue reported its cap as the number of conversations that exist');

    // Through the reader-aware formatter, because a grouped number is not the
    // same string in every locale -- and a test asserting `number_format()`
    // would pass while the page rendered something else for a German reader.
    $response->assertSee(ReaderNumber::count(ConversationQueueQuery::DISPLAY_LIMIT), false);
    $response->assertSee(ReaderNumber::count($over), false);
});

test('a queue that fits says nothing about being capped', function (): void {
    // The notice is for a desk that has outgrown one page, which is not every
    // desk. A banner on every queue is noise that teaches agents to skip it.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    Conversation::factory()
        ->count(3)
        ->for($site)
        ->for(Visitor::factory()->for($site))
        ->create(['status' => 'open']);

    $response = $this->actingAs($agent)->get('/dashboard/conversations');

    $response->assertOk();

    expect($response->viewData('conversationsShownOf'))->toBe(3);

    $response->assertDontSee(__('conversations.summary.capped_notice', [
        'shown' => '3',
        'total' => '3',
    ]), false);
});

test('a conversation with no messages does not bury one that has them', function (): void {
    // PostgreSQL sorts NULLs FIRST on a descending order and SQLite sorts them
    // last, so ordering by `last_message_at` alone put every message-less
    // conversation above every conversation with a recent reply on the shipped
    // deployment. Unbounded that was odd ordering; with the row cap it is a
    // queue that hides everything anybody has actually said.
    //
    // A conversation with no messages was last active when it was opened, so it
    // ranks by `created_at` -- which is the true answer, not a workaround.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    // Enough message-less conversations to fill the page on their own, all
    // OLDER than the one with a message.
    Conversation::factory()
        ->count(ConversationQueueQuery::DISPLAY_LIMIT + 10)
        ->for($site)
        ->for($visitor)
        ->create([
            'status' => 'open',
            'last_message_at' => null,
            'created_at' => now()->subDays(30),
        ]);

    $answered = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-HASAREPLY',
        'subject' => 'Somebody actually said something',
        'status' => 'open',
        'created_at' => now()->subDays(30),
        'last_message_at' => now()->subMinute(),
    ]);

    $response = $this->actingAs($agent)->get('/dashboard/conversations');

    $response->assertOk()->assertSee('Somebody actually said something');

    // And it is FIRST, because it is the most recently active.
    expect($response->viewData('conversations')->first()->id)
        ->toBe($answered->id, 'a conversation nobody has written to outranked one with a reply a minute ago');
});

test('the queue summary counts the lane, not the page', function (): void {
    // With the row cap, the number rendered and the number that exist differ.
    // The primary summary must report the lane -- a page saying "200 open"
    // above "showing the 200 most recently active of 225" contradicts itself,
    // and an agent needs to know how many are actually open.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();

    $open = ConversationQueueQuery::DISPLAY_LIMIT + 25;

    Conversation::factory()
        ->count($open)
        ->for($site)
        ->for(Visitor::factory()->for($site))
        ->create(['status' => 'open']);

    $response = $this->actingAs($agent)->get('/dashboard/conversations');

    $response->assertOk();

    $summary = $response->viewData('conversationQueueCountSummary');

    // `str_contains` rather than `toContain($needle, $message)`: that matcher is
    // VARIADIC, so the failure message is read as a second needle and the
    // assertion demands both. It fails on a correct page, which is how it
    // caught my attention here for the second time in a day.
    expect(str_contains($summary['heading'], (string) $open))
        ->toBeTrue('the summary reported the row cap as the number of open conversations');

    expect(str_contains($summary['heading'], ConversationQueueQuery::DISPLAY_LIMIT.' open'))
        ->toBeFalse('the summary reported the capped row count as the lane size');

    // And the DETAIL, which says "Showing ...", reports the page rather than
    // the lane. The two sentences sit next to each other, so getting one right
    // by making the other wrong is not a fix: the page would claim to show 225
    // rows immediately above a notice saying it shows 200 of them.
    expect(str_contains($summary['detail'], (string) ConversationQueueQuery::DISPLAY_LIMIT))
        ->toBeTrue('the detail claims to be showing more rows than the page holds');

    expect(str_contains($summary['detail'], (string) $open))
        ->toBeFalse('the detail reported the lane size as the number of rows shown');
});

test('the queue switcher does not rebuild the whole lane', function (): void {
    // Opening a row from the queue carries `from_queue=1`, and the sibling
    // lookup rebuilt the lane to find the neighbours -- unbounded, so the
    // queue-to-detail flow hydrated every matching conversation and paid
    // exactly the cost the row cap exists to remove, on the path agents use
    // most.
    //
    // Asserted on the switcher's own `total`, which is the size of the set it
    // materialised. Counting QUERIES does not work here and I wrote that
    // version first: an unbounded lookup runs the same number of statements,
    // it just brings back more rows, so the count is identical either way.
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();

    Conversation::factory()
        ->count(ConversationQueueQuery::DISPLAY_LIMIT + 50)
        ->for($site)
        ->for($visitor)
        ->create(['status' => 'open', 'last_message_at' => now()->subMinutes(5)]);

    // The one the agent opens, ranked first so it is inside the window.
    $opened = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-SWITCHER',
        'status' => 'open',
        'last_message_at' => now(),
    ]);

    $response = $this->actingAs($agent)
        ->get('/dashboard/conversations/'.$opened->support_code.'?from_queue=1');

    $response->assertOk();

    $switcher = $response->viewData('conversationSiblings');

    expect($switcher['total'])->toBe(ConversationQueueQuery::DISPLAY_LIMIT,
        'the switcher materialised the whole lane rather than the window the queue rendered');

    // The lane really is bigger than the cap, or this proves nothing.
    expect(Conversation::query()->count())
        ->toBeGreaterThan(ConversationQueueQuery::DISPLAY_LIMIT);
});
