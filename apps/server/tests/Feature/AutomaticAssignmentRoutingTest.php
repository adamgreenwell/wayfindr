<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\SiteRoutingState;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\TicketAssigned;
use App\Support\Mail\InboundMailRouter;
use App\Support\Mail\InboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function routingSite(Account $account, bool $enabled = true, int $capacity = 5): Site
{
    return Site::factory()->for($account)->create([
        'settings' => [
            'mask_selectors' => ['input[type="password"]'],
            'routing' => [
                'enabled' => $enabled,
                'conversation_capacity' => $capacity,
            ],
        ],
    ]);
}

function onlineRoutingAgent(Account $account, array $attributes = []): User
{
    return User::factory()->for($account)->create(array_merge([
        'account_role' => AccountRole::Agent,
        'routing_status' => User::ROUTING_STATUS_ONLINE,
        'routing_status_changed_at' => now(),
    ], $attributes));
}

test('automatic assignment is off by default and agents are away by default', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['settings' => []]);
    $conversation = Conversation::factory()->for($site)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    expect($agent->routing_status)->toBe(User::ROUTING_STATUS_AWAY)
        ->and($agent->routing_status_changed_at)->toBeNull()
        ->and($conversation->assigned_agent_id)->toBeNull()
        ->and($ticket->assignee_id)->toBeNull()
        ->and(SiteRoutingState::query()->exists())->toBeFalse();
});

test('conversations rotate among online agents and stop at their capacity', function (): void {
    $account = Account::factory()->create();
    $site = routingSite($account, capacity: 1);
    $first = onlineRoutingAgent($account);
    $second = onlineRoutingAgent($account);

    $one = Conversation::factory()->for($site)->create();
    $two = Conversation::factory()->for($site)->create();
    $full = Conversation::factory()->for($site)->create();

    expect($one->assigned_agent_id)->toBe($first->id)
        ->and($two->assigned_agent_id)->toBe($second->id)
        ->and($full->assigned_agent_id)->toBeNull();

    $one->update(['status' => 'closed', 'closed_at' => now()]);
    $afterCapacityReturns = Conversation::factory()->for($site)->create();

    expect($afterCapacityReturns->assigned_agent_id)->toBe($first->id)
        ->and($site->routingState()->firstOrFail()->last_conversation_agent_id)->toBe($first->id);
});

test('conversation capacity follows an agent across sites in the account', function (): void {
    $account = Account::factory()->create();
    $firstSite = routingSite($account, capacity: 1);
    $secondSite = routingSite($account, capacity: 1);
    $agent = onlineRoutingAgent($account);

    $first = Conversation::factory()->for($firstSite)->create();
    $second = Conversation::factory()->for($secondSite)->create();

    expect($first->assigned_agent_id)->toBe($agent->id)
        ->and($second->assigned_agent_id)->toBeNull();
});

test('away deactivated unsupported and under-permissioned agents are skipped', function (): void {
    $account = Account::factory()->create();
    $site = routingSite($account);
    $away = User::factory()->for($account)->create();
    $deactivated = onlineRoutingAgent($account, ['deactivated_at' => now()]);
    $unsupported = onlineRoutingAgent($account);
    $conversationOnlyRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $conversationOnly = onlineRoutingAgent($account, ['custom_role_id' => $conversationOnlyRole->id]);
    $ticketOnlyRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $ticketOnly = onlineRoutingAgent($account, ['custom_role_id' => $ticketOnlyRole->id]);
    $site->supportAgents()->attach([$away->id, $deactivated->id, $conversationOnly->id, $ticketOnly->id]);

    $conversation = Conversation::factory()->for($site)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    expect($conversation->assigned_agent_id)->toBe($conversationOnly->id)
        ->and($ticket->assignee_id)->toBe($ticketOnly->id)
        ->and($conversation->assigned_agent_id)->not->toBe($unsupported->id);
});

test('tickets use their own round robin and do not consume conversation capacity', function (): void {
    Notification::fake();
    $account = Account::factory()->create();
    $site = routingSite($account, capacity: 1);
    $first = onlineRoutingAgent($account);
    $second = onlineRoutingAgent($account);

    $conversation = Conversation::factory()->for($site)->create();
    $ticketOne = Ticket::factory()->for($account)->for($site)->create();
    $ticketTwo = Ticket::factory()->for($account)->for($site)->create();
    $ticketThree = Ticket::factory()->for($account)->for($site)->create();

    expect($conversation->assigned_agent_id)->toBe($first->id)
        ->and($ticketOne->assignee_id)->toBe($first->id)
        ->and($ticketTwo->assignee_id)->toBe($second->id)
        ->and($ticketThree->assignee_id)->toBe($first->id)
        ->and($site->routingState()->firstOrFail()->last_ticket_agent_id)->toBe($first->id);

    Notification::assertSentToTimes($first, TicketAssigned::class, 2);
    Notification::assertSentToTimes($second, TicketAssigned::class, 1);
});

test('closed work and already assigned work are not automatically routed', function (): void {
    $account = Account::factory()->create();
    $site = routingSite($account);
    $agent = onlineRoutingAgent($account);

    $closedConversation = Conversation::factory()->for($site)->create(['status' => 'closed']);
    $closedTicket = Ticket::factory()->for($account)->for($site)->create(['status' => 'closed']);
    $assignedConversation = Conversation::factory()->for($site)->create(['assigned_agent_id' => $agent->id]);
    $assignedTicket = Ticket::factory()->for($account)->for($site)->create(['assignee_id' => $agent->id]);

    expect($closedConversation->assigned_agent_id)->toBeNull()
        ->and($closedTicket->assignee_id)->toBeNull()
        ->and($assignedConversation->assigned_agent_id)->toBe($agent->id)
        ->and($assignedTicket->assignee_id)->toBe($agent->id)
        ->and(AuditEvent::query()->whereIn('subject_id', [
            $closedConversation->id,
            $closedTicket->id,
            $assignedConversation->id,
            $assignedTicket->id,
        ])->whereIn('action', ['conversation.assignee_updated', 'ticket.assignee_updated'])->exists())->toBeFalse();
});

test('automatic assignments are recorded as system round robin events', function (): void {
    $account = Account::factory()->create();
    $site = routingSite($account);
    $agent = onlineRoutingAgent($account);
    $conversation = Conversation::factory()->for($site)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    $conversationEvent = AuditEvent::query()
        ->where('action', 'conversation.assignee_updated')
        ->where('subject_type', Conversation::class)
        ->where('subject_id', $conversation->id)
        ->firstOrFail();
    $ticketEvent = AuditEvent::query()
        ->where('action', 'ticket.assignee_updated')
        ->where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->firstOrFail();

    foreach ([$conversationEvent, $ticketEvent] as $event) {
        expect($event->actor_type)->toBeNull()
            ->and($event->actor_id)->toBeNull()
            ->and($event->account_id)->toBe($account->id)
            ->and($event->site_id)->toBe($site->id)
            ->and($event->metadata)->toMatchArray([
                'old_assignee_id' => null,
                'new_assignee_id' => $agent->id,
                'new_assignee_name' => $agent->name,
                'source' => 'automatic',
                'strategy' => 'round_robin',
            ]);
    }
});

test('widget and inbound email arrivals enter the same routing path', function (): void {
    $account = Account::factory()->create();
    $site = routingSite($account, capacity: 2);
    $site->update(['inbound_address' => 'routing@example.test']);
    $agent = onlineRoutingAgent($account);

    $visitorToken = $this->postJson('/api/widget/bootstrap', [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-routing-arrival',
    ])->assertCreated()->json('data.visitor.token');

    $this->postJson(route('conversations.store'), [
        'site_public_key' => $site->public_key,
        'anonymous_id' => 'anon-routing-arrival',
        'visitor_token' => $visitorToken,
        'subject' => 'Widget arrival',
    ])->assertCreated();

    $message = InboundMessage::fromPayload([
        'from' => 'Visitor <visitor@example.test>',
        'to' => 'routing@example.test',
        'subject' => 'Email arrival',
        'text' => 'Please help.',
        'message_id' => '<routing-arrival@example.test>',
    ]);
    expect($message)->not->toBeNull();
    app(InboundMailRouter::class)->route($message);

    $assignments = Conversation::query()->orderBy('id')->pluck('assigned_agent_id');

    expect($assignments)->toHaveCount(2)
        ->and($assignments->all())->toBe([$agent->id, $agent->id]);
});

test('public API conversation and ticket arrivals are routed before their receipts return', function (): void {
    $account = Account::factory()->create();
    $site = routingSite($account);
    $visitor = Visitor::factory()->for($site)->create();
    $agent = onlineRoutingAgent($account);
    $generated = ApiToken::generate();
    $token = ApiToken::query()->create([
        'account_id' => $account->id,
        'created_by_id' => $agent->id,
        'name' => 'Routing acceptance',
        'token_hash' => $generated['hash'],
        'last_four' => $generated['last_four'],
        'abilities' => [ApiToken::ABILITY_WRITE],
        'restricts_sites' => true,
    ]);
    $token->sites()->attach($site->id);
    $headers = [
        'Authorization' => 'Bearer '.$generated['plain'],
        'Idempotency-Key' => 'routing-conversation-arrival',
    ];

    $this->postJson('/api/v1/conversations', [
        'site_id' => $site->id,
        'visitor_id' => $visitor->id,
        'subject' => 'API conversation',
    ], $headers)->assertCreated();

    $headers['Idempotency-Key'] = 'routing-ticket-arrival';
    $this->postJson('/api/v1/tickets', [
        'site_id' => $site->id,
        'requester_id' => $visitor->id,
        'subject' => 'API ticket',
    ], $headers)->assertCreated();

    expect(Conversation::query()->sole()->assigned_agent_id)->toBe($agent->id)
        ->and(Ticket::query()->sole()->assignee_id)->toBe($agent->id);
});

test('an agent can explicitly change their assignment availability', function (): void {
    $agent = User::factory()->for(Account::factory())->create();

    $this->actingAs($agent)
        ->get(route('dashboard.profile.show'))
        ->assertOk()
        ->assertSee('Assignment availability')
        ->assertSee('name="routing_status"', false);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.routing-status.update'), ['routing_status' => 'online'])
        ->assertRedirect(route('dashboard.profile.show'))
        ->assertSessionHas('status', 'profile.flash.routing_status_updated');

    $event = AuditEvent::query()->where('action', 'agent.routing_status_updated')->firstOrFail();

    expect($agent->fresh()->routing_status)->toBe(User::ROUTING_STATUS_ONLINE)
        ->and($agent->fresh()->routing_status_changed_at)->not->toBeNull()
        ->and($event->actor_id)->toBe($agent->id)
        ->and($event->subject_id)->toBe($agent->id)
        ->and($event->metadata)->toMatchArray(['old_status' => 'away', 'new_status' => 'online']);
});

test('assignment availability rejects unknown states and requires an account', function (): void {
    $agent = User::factory()->for(Account::factory())->create();
    $detached = User::factory()->create(['account_id' => null]);

    $this->actingAs($agent)
        ->put(route('dashboard.profile.routing-status.update'), ['routing_status' => 'busy'])
        ->assertSessionHasErrors('routing_status');

    $this->actingAs($detached)
        ->put(route('dashboard.profile.routing-status.update'), ['routing_status' => 'online'])
        ->assertForbidden();

    expect($agent->fresh()->routing_status)->toBe(User::ROUTING_STATUS_AWAY)
        ->and(AuditEvent::query()->where('action', 'agent.routing_status_updated')->exists())->toBeFalse();
});

test('deactivation makes an online agent away and reactivation does not opt them back in', function (): void {
    $account = Account::factory()->create();
    $owner = User::factory()->for($account)->create(['account_role' => AccountRole::Owner]);
    $agent = onlineRoutingAgent($account);

    $this->actingAs($owner)
        ->post(route('dashboard.account.agents.deactivate', $agent))
        ->assertRedirect();
    $this->actingAs($owner)
        ->post(route('dashboard.account.agents.reactivate', $agent))
        ->assertRedirect();

    $event = AuditEvent::query()
        ->where('action', 'agent.routing_status_updated')
        ->where('subject_id', $agent->id)
        ->sole();

    expect($agent->fresh()->deactivated_at)->toBeNull()
        ->and($agent->fresh()->routing_status)->toBe(User::ROUTING_STATUS_AWAY)
        ->and($event->actor_id)->toBe($owner->id)
        ->and($event->metadata)->toMatchArray([
            'old_status' => User::ROUTING_STATUS_ONLINE,
            'new_status' => User::ROUTING_STATUS_AWAY,
            'reason' => 'deactivated',
        ]);
});

test('an admin can enable routing without replacing other site settings', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['settings' => ['presence' => ['enabled' => false]]]);

    $this->actingAs($admin)
        ->get(route('dashboard.sites.show', $site))
        ->assertOk()
        ->assertSee('Automatic assignment')
        ->assertSee('name="routing_conversation_capacity"', false);

    $this->actingAs($admin)
        ->put(route('dashboard.sites.routing.update', $site), [
            'routing_enabled' => '1',
            'routing_conversation_capacity' => '7',
        ])
        ->assertRedirect(route('dashboard.sites.show', $site))
        ->assertSessionHas('status', 'site_settings.flash.routing_saved');

    $settings = $site->fresh()->settings;
    $event = AuditEvent::query()->where('action', 'site.routing_updated')->firstOrFail();

    expect($settings['presence'])->toBe(['enabled' => false])
        ->and($settings['routing'])->toBe(['enabled' => true, 'conversation_capacity' => 7])
        ->and($event->actor_id)->toBe($admin->id)
        ->and($event->site_id)->toBe($site->id)
        ->and($event->metadata)->toMatchArray([
            'old_enabled' => false,
            'new_enabled' => true,
            'old_conversation_capacity' => 5,
            'new_conversation_capacity' => 7,
        ]);
});

test('a plain agent cannot configure routing and invalid capacity is rejected', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Agent]);
    $site = Site::factory()->for($account)->create(['settings' => []]);

    $this->actingAs($agent)
        ->put(route('dashboard.sites.routing.update', $site), [
            'routing_enabled' => '1',
            'routing_conversation_capacity' => '5',
        ])->assertForbidden();

    $this->actingAs($admin)
        ->put(route('dashboard.sites.routing.update', $site), [
            'routing_enabled' => '1',
            'routing_conversation_capacity' => '0',
        ])->assertSessionHasErrors('routing_conversation_capacity');

    expect($site->fresh()->settings['routing'] ?? null)->toBeNull();
});

test('manual conversation assignment and release keep an audit trail', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['support_code' => 'WF-ROUTEAUDIT']);

    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-ROUTEAUDIT/claim')
        ->assertRedirect();
    $this->actingAs($agent)
        ->post('/dashboard/conversations/WF-ROUTEAUDIT/release')
        ->assertRedirect();

    $events = AuditEvent::query()
        ->where('action', 'conversation.assignee_updated')
        ->where('subject_id', $conversation->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(2)
        ->and($events[0]->actor_id)->toBe($agent->id)
        ->and($events[0]->metadata)->toMatchArray([
            'old_assignee_id' => null,
            'new_assignee_id' => $agent->id,
            'source' => 'manual',
        ])
        ->and($events[1]->metadata)->toMatchArray([
            'old_assignee_id' => $agent->id,
            'new_assignee_id' => null,
            'source' => 'manual',
        ]);
});

test('routing mutations take the account lock before mutable routing rows', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL exposes the row-lock clauses used by this contract.');
    }

    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = routingSite($account, enabled: false);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($admin)->put(route('dashboard.sites.routing.update', $site), [
        'routing_enabled' => '1',
        'routing_conversation_capacity' => '5',
    ])->assertRedirect();

    $queries = collect(DB::getQueryLog())->pluck('query')->values();
    DB::disableQueryLog();
    $accountLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "accounts"')
        && str_contains($query, 'for update'));
    $siteLock = $queries->search(fn (string $query): bool => str_contains($query, 'from "sites"')
        && str_contains($query, 'for update'));

    expect($accountLock)->toBeInt()
        ->and($siteLock)->toBeInt()
        ->and($accountLock)->toBeLessThan($siteLock);
});
