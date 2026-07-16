<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('ticket brief and work state stay lean', function (): void {
    [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

    $ticket = Ticket::factory()
        ->for($agent->account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Lean brief ticket',
            'priority' => 'high',
            'category' => 'billing',
        ]);

    $response = $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        // The brief: identity + routing facts and the one useful jump.
        ->assertSeeInOrder(['Ticket brief', 'Owner', 'Priority', 'Category', 'Reference', 'Open conversation'])
        // The work state: status, timing — no ambient previews or coaching.
        ->assertSeeInOrder(['Work state', 'Status', 'Timing']);

    // The coaching surfaces are gone from the task page.
    $response->assertDontSee('Next action')
        ->assertDontSee('Status action readiness')
        ->assertDontSee('Reply visibility');
});

test('ticket detail work state shows linked ticket timing context', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 16:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

        ConversationMessage::factory()->for($conversation)->create([
            'body' => 'Can someone help me finish the billing export?',
            'created_at' => now()->subHours(3),
            'sender_id' => $visitor->id,
            'sender_type' => Visitor::class,
        ]);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($conversation)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'created_at' => now()->subDays(2),
                'subject' => 'Billing export timing context',
                'updated_at' => now()->subHours(3),
            ]);

        $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder([
                'Work state',
                'Timing',
                'Opened 2 days ago',
                'Waiting on reply for 3 hours',
                'Support reference',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail work state shows graceful standalone timing context', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 16:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor] = ticketWorkStateContext(false);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'created_at' => now()->subDays(5),
                'description' => 'Follow up with operations about the missing label.',
                'subject' => 'Standalone timing context',
                'updated_at' => now()->subDays(5),
            ]);

        $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder([
                'Work state',
                'Timing',
                'Opened 5 days ago',
                'Waiting on agent update since ticket opened',
                'Support reference',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail work state surfaces the latest lifecycle handoff note', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 19:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor] = ticketWorkStateContext(false);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'closed_at' => now()->subMinutes(3),
                'status' => 'closed',
                'subject' => 'Lifecycle handoff context',
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.pending',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'pending_note' => 'Waiting on customer billing contact.',
                ],
                'occurred_at' => now()->subMinutes(8),
                'site_id' => $site->id,
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.closed',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'resolution_note' => 'Customer confirmed the billing export is fixed.',
                ],
                'occurred_at' => now()->subMinutes(3),
                'site_id' => $site->id,
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.note_added',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'body' => 'Private note that should stay in the timeline.',
                ],
                'occurred_at' => now()->subMinute(),
                'site_id' => $site->id,
            ]);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder([
                'Work state',
                'Lifecycle note',
                'Ticket closed',
                'Customer confirmed the billing export is fixed.',
                'Ada Agent',
                '3 minutes ago',
                'Support reference',
            ]);

        expect(Str::betweenFirst($response->content(), 'id="ticket-work-state-heading"', '</section>'))
            ->not->toContain('Private note that should stay in the timeline.')
            ->not->toContain('Waiting on customer billing contact.');
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail work state hides stale lifecycle notes after a blank transition', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 19:30:00', 'UTC'));

    try {
        [$agent, $site, $visitor] = ticketWorkStateContext(false);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'status' => 'open',
                'subject' => 'Blank reopen context',
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.closed',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'resolution_note' => 'Older resolution that should no longer summarize the ticket.',
                ],
                'occurred_at' => now()->subMinutes(10),
                'site_id' => $site->id,
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.reopened',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [],
                'occurred_at' => now()->subMinutes(2),
                'site_id' => $site->id,
            ]);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk();

        expect(Str::between($response->content(), 'Work state', 'Support reference'))
            ->not->toContain('Lifecycle note')
            ->not->toContain('Older resolution that should no longer summarize the ticket.');
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail timeline summarizes conversation, internal note, and activity visibility', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 17:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

        ConversationMessage::factory()->for($conversation)->create([
            'body' => 'The export still fails on the last step.',
            'created_at' => now()->subMinutes(7),
            'sender_id' => $visitor->id,
            'sender_type' => Visitor::class,
        ]);
        ConversationMessage::factory()->for($conversation)->create([
            'body' => 'I can reproduce that and will keep digging.',
            'created_at' => now()->subMinutes(6),
            'sender_id' => $agent->id,
            'sender_type' => User::class,
        ]);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($conversation)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'subject' => 'Export failure follow-up',
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.note_added',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'body' => 'private vendor escalation detail',
                ],
                'occurred_at' => now()->subMinutes(5),
                'site_id' => $site->id,
            ]);
        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.pending',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'pending_note' => 'Waiting on export logs.',
                ],
                'occurred_at' => now()->subMinutes(4),
                'site_id' => $site->id,
            ]);

        $response = $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSeeInOrder([
                'Timeline',
                '4 events',
                'Conversation',
                '2 items',
                'Internal notes',
                '1 note',
                'Ticket activity',
                '1 update',
            ])
            ->assertSee('Visitor messages and customer-visible replies.')
            ->assertSee('Private agent context for handoff.')
            ->assertSee('Status, assignment, label, and integration events.')
            ->assertSee('private vendor escalation detail');

        expect(Str::between($response->content(), 'Timeline', 'Visitor message'))
            ->not->toContain('private vendor escalation detail');
    } finally {
        Carbon::setTestNow();
    }
});

test('ticket detail timeline filters by visibility type', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-21 18:00:00', 'UTC'));

    try {
        [$agent, $site, $visitor, $conversation] = ticketWorkStateContext();

        ConversationMessage::factory()->for($conversation)->create([
            'body' => 'Customer-visible visitor timeline item.',
            'created_at' => now()->subMinutes(8),
            'sender_id' => $visitor->id,
            'sender_type' => Visitor::class,
        ]);
        ConversationMessage::factory()->for($conversation)->create([
            'body' => 'Customer-visible agent timeline item.',
            'created_at' => now()->subMinutes(7),
            'sender_id' => $agent->id,
            'sender_type' => User::class,
        ]);

        $ticket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($conversation)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'subject' => 'Timeline filter follow-up',
            ]);

        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.note_added',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'body' => 'Private timeline filter detail.',
                ],
                'occurred_at' => now()->subMinutes(6),
                'site_id' => $site->id,
            ]);
        AuditEvent::factory()
            ->for($agent->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => 'ticket.pending',
                'actor_id' => $agent->id,
                'actor_type' => User::class,
                'metadata' => [
                    'pending_note' => 'Ticket activity timeline filter detail.',
                ],
                'occurred_at' => now()->subMinutes(5),
                'site_id' => $site->id,
            ]);

        $defaultTimeline = $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Timeline')
            ->assertSee('4 events')
            ->assertSee('All events')
            ->assertSee('Customer-visible')
            ->assertSee('Internal notes')
            ->assertSee('Ticket activity');

        $defaultTimelineHtml = Str::betweenFirst($defaultTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($defaultTimelineHtml)
            ->toContain('Customer-visible visitor timeline item.')
            ->toContain('Customer-visible agent timeline item.')
            ->toContain('Private timeline filter detail.')
            ->toContain('Ticket activity timeline filter detail.');

        $conversationTimeline = $this->actingAs($agent)
            ->get("/dashboard/tickets/{$ticket->id}?timeline_filter=conversation")
            ->assertOk()
            ->assertSee('2 of 4 events');

        $conversationTimelineHtml = Str::betweenFirst($conversationTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($conversationTimelineHtml)
            ->toContain('Customer-visible visitor timeline item.')
            ->toContain('Customer-visible agent timeline item.')
            ->not->toContain('Private timeline filter detail.')
            ->not->toContain('Ticket activity timeline filter detail.');

        $internalTimeline = $this->actingAs($agent)
            ->get("/dashboard/tickets/{$ticket->id}?timeline_filter=internal_notes")
            ->assertOk()
            ->assertSee('1 of 4 events');

        $internalTimelineHtml = Str::betweenFirst($internalTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($internalTimelineHtml)
            ->toContain('Private timeline filter detail.')
            ->not->toContain('Customer-visible visitor timeline item.')
            ->not->toContain('Ticket activity timeline filter detail.');

        $activityTimeline = $this->actingAs($agent)
            ->get("/dashboard/tickets/{$ticket->id}?timeline_filter=ticket_activity")
            ->assertOk()
            ->assertSee('1 of 4 events');

        $activityTimelineHtml = Str::betweenFirst($activityTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($activityTimelineHtml)
            ->toContain('Ticket activity timeline filter detail.')
            ->not->toContain('Customer-visible visitor timeline item.')
            ->not->toContain('Private timeline filter detail.');

        $emptyTimelineTicket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($conversation)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'subject' => 'Timeline empty state follow-up',
            ]);

        $emptyTimeline = $this->actingAs($agent)
            ->get("/dashboard/tickets/{$emptyTimelineTicket->id}?timeline_filter=internal_notes")
            ->assertOk()
            ->assertSee('0 of 2 events');

        $emptyTimelineHtml = Str::betweenFirst($emptyTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($emptyTimelineHtml)
            ->toContain('No internal note timeline events yet.')
            ->toContain('Private handoff notes will appear here after an agent records context for the team.');

        $emptyActivityTimeline = $this->actingAs($agent)
            ->get("/dashboard/tickets/{$emptyTimelineTicket->id}?timeline_filter=ticket_activity")
            ->assertOk()
            ->assertSee('0 of 2 events');

        $emptyActivityTimelineHtml = Str::betweenFirst($emptyActivityTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($emptyActivityTimelineHtml)
            ->toContain('No ticket activity timeline events yet.')
            ->toContain('Status, assignment, label, and external-link changes will appear here as the ticket moves.');

        $standaloneTicket = Ticket::factory()
            ->for($agent->account)
            ->for($site)
            ->for($visitor, 'requester')
            ->for($agent, 'assignee')
            ->create([
                'subject' => 'Standalone empty timeline follow-up',
            ]);

        $emptyAllTimeline = $this->actingAs($agent)
            ->get(route('dashboard.tickets.show', $standaloneTicket))
            ->assertOk()
            ->assertSee('0 events');

        $emptyAllTimelineHtml = Str::betweenFirst($emptyAllTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($emptyAllTimelineHtml)
            ->toContain('No ticket timeline events yet.')
            ->toContain('Conversation replies, internal notes, and ticket updates will appear here as this ticket gets worked.');

        $emptyConversationTimeline = $this->actingAs($agent)
            ->get("/dashboard/tickets/{$standaloneTicket->id}?timeline_filter=conversation")
            ->assertOk()
            ->assertSee('0 of 0 events');

        $emptyConversationTimelineHtml = Str::betweenFirst($emptyConversationTimeline->content(), '<section class="section" aria-labelledby="ticket-timeline-heading">', '</section>');

        expect($emptyConversationTimelineHtml)
            ->toContain('No customer-visible timeline events yet.')
            ->toContain('Visitor messages and agent replies will appear here once this ticket is linked to an active conversation.');
    } finally {
        Carbon::setTestNow();
    }
});

/**
 * @return array{0: User, 1: Site, 2: Visitor, 3?: Conversation}
 */
function ticketWorkStateContext(bool $withConversation = true): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);

    if (! $withConversation) {
        return [$agent, $site, $visitor];
    }

    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => 'WF-WORKSTATE',
            'status' => 'open',
        ]);

    return [$agent, $site, $visitor, $conversation];
}
