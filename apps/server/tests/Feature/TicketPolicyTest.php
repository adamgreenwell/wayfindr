<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('ticket policy allows agents to work tickets only for sites they support', function (): void {
    $account = Account::factory()->create();
    $supportAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $otherAgent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $otherAccountAgent = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($supportAgent);
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    expect(Gate::forUser($supportAgent)->allows('view', $ticket))->toBeTrue()
        ->and(Gate::forUser($supportAgent)->allows('addNote', $ticket))->toBeTrue()
        ->and(Gate::forUser($supportAgent)->allows('update', $ticket))->toBeTrue()
        ->and(Gate::forUser($supportAgent)->allows('updateStatus', $ticket))->toBeTrue()
        ->and(Gate::forUser($supportAgent)->allows('assign', $ticket))->toBeTrue()
        ->and(Gate::forUser($otherAgent)->allows('view', $ticket))->toBeFalse()
        ->and(Gate::forUser($otherAgent)->allows('assign', $ticket))->toBeFalse()
        ->and(Gate::forUser($otherAccountAgent)->allows('view', $ticket))->toBeFalse();
});

test('ticket policy preserves account-wide site fallback until explicit support agents exist', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $otherAccountAgent = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    expect(Gate::forUser($agent)->allows('view', $ticket))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('update', $ticket))->toBeTrue()
        ->and(Gate::forUser($otherAccountAgent)->allows('view', $ticket))->toBeFalse();
});

test('ticket policy denies tickets whose account does not match the supported site', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($otherAccount)->for($site)->create();

    expect(Gate::forUser($agent)->allows('view', $ticket))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('update', $ticket))->toBeFalse()
        ->and(Gate::forUser($agent)->allows('assign', $ticket))->toBeFalse();
});

test('ticket policy denies deactivated agents even when stale site assignments remain', function (): void {
    $account = Account::factory()->create();
    $deactivatedAgent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'deactivated_at' => now(),
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($deactivatedAgent);
    $ticket = Ticket::factory()->for($account)->for($site)->for($deactivatedAgent, 'assignee')->create();

    expect(Gate::forUser($deactivatedAgent)->allows('view', $ticket))->toBeFalse()
        ->and(Gate::forUser($deactivatedAgent)->allows('addNote', $ticket))->toBeFalse()
        ->and(Gate::forUser($deactivatedAgent)->allows('reply', $ticket))->toBeFalse()
        ->and(Gate::forUser($deactivatedAgent)->allows('update', $ticket))->toBeFalse()
        ->and(Gate::forUser($deactivatedAgent)->allows('assign', $ticket))->toBeFalse();
});

test('ticket managers cannot read or reply to linked conversations without conversation permissions', function (array $ticketMetadata): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $ticketManager = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($ticketManager);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIVATE-CONVERSATION',
        'subject' => 'Private conversation subject',
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Private conversation transcript',
    ]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->create([
            'subject' => 'Visible ticket subject',
            'description' => 'Visitor: Copied private conversation transcript',
            'metadata' => $ticketMetadata,
        ]);
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $ticketManager->id,
        'action' => 'ticket.created',
        'metadata' => [
            'source' => 'conversation',
            'support_code' => 'WF-PRIVATE-CONVERSATION',
        ],
        'occurred_at' => now(),
    ]);

    expect(Gate::forUser($ticketManager)->allows('view', $ticket))->toBeTrue()
        ->and(Gate::forUser($ticketManager)->allows('reply', $ticket))->toBeFalse();

    $this->actingAs($ticketManager)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('Visible ticket subject')
        ->assertDontSee('WF-PRIVATE-CONVERSATION')
        ->assertDontSee('Private conversation transcript');

    $this->actingAs($ticketManager)
        ->get(route('dashboard.tickets.index', ['ticket_search' => 'WF-PRIVATE-CONVERSATION']))
        ->assertOk()
        ->assertDontSee('Visible ticket subject');

    $this->actingAs($ticketManager)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Visible ticket subject')
        ->assertDontSee('WF-PRIVATE-CONVERSATION')
        ->assertDontSee('Private conversation subject')
        ->assertDontSee('Private conversation transcript')
        ->assertDontSee('Visitor: Copied private conversation transcript')
        ->assertDontSee('Ticket created from conversation')
        ->assertDontSee('name="description"', false)
        ->assertDontSee(route('dashboard.conversations.show', $conversation->support_code), false)
        ->assertDontSee(route('dashboard.tickets.replies.store', $ticket), false);

    $this->actingAs($ticketManager)
        ->put(route('dashboard.tickets.update', $ticket), [
            'subject' => 'Updated visible ticket subject',
            'priority' => $ticket->priority,
        ])
        ->assertRedirect();

    expect($ticket->fresh())
        ->subject->toBe('Updated visible ticket subject')
        ->description->toBe('Visitor: Copied private conversation transcript')
        ->metadata->toBe($ticketMetadata);

    $this->actingAs($ticketManager)
        ->post(route('dashboard.tickets.replies.store', $ticket), [
            'message' => 'Unauthorized reply',
        ])
        ->assertNotFound();

    expect($conversation->messages()->where('body', 'Unauthorized reply')->exists())->toBeFalse();
})->with([
    'stamped conversation description' => [[
        'source' => 'conversation',
        'description_source' => 'conversation_transcript',
        'support_code' => 'WF-PRIVATE-CONVERSATION',
    ]],
    'legacy conversation description' => [[
        'source' => 'conversation',
        'support_code' => 'WF-PRIVATE-CONVERSATION',
    ]],
]);
