<?php

use App\Models\Account;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('conversation and ticket queues expose copy controls for support codes', function (): void {
    [$agent, $conversation, $ticket] = supportReferenceCopyContext('WF-COPYQUEUE');

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('WF-COPYQUEUE')
        ->assertSee('aria-label="Copy support code WF-COPYQUEUE"', false)
        ->assertSee('data-copy-value="WF-COPYQUEUE"', false);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee($ticket->subject)
        ->assertSee('WF-COPYQUEUE')
        ->assertSee('aria-label="Copy support code WF-COPYQUEUE"', false)
        ->assertSee('data-copy-value="WF-COPYQUEUE"', false);
});

test('conversation and ticket detail pages expose copy controls for the current support code', function (): void {
    [$agent, $conversation, $ticket] = supportReferenceCopyContext('WF-COPYDETAIL');

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertSee('Current support code')
        ->assertSee('WF-COPYDETAIL')
        ->assertSee('aria-label="Copy support code WF-COPYDETAIL"', false)
        ->assertSee('data-copy-value="WF-COPYDETAIL"', false);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Support code')
        ->assertSee('WF-COPYDETAIL')
        ->assertSee('aria-label="Copy support code WF-COPYDETAIL"', false)
        ->assertSee('data-copy-value="WF-COPYDETAIL"', false);
});

function supportReferenceCopyContext(string $supportCode): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-copy']);
    $conversation = Conversation::factory()
        ->for($site)
        ->for($visitor)
        ->create([
            'support_code' => $supportCode,
            'subject' => 'Copy reference question',
        ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Copy reference ticket',
        ]);

    return [$agent, $conversation, $ticket];
}
