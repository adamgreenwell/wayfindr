<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\AutomationRuleEvent;
use App\Events\TicketUpdated;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketBulkActionRun;
use App\Models\TicketLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('the ticket queue exposes accessible multi-selection and an exact no-write review', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Docs']);
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Billing']);
    $first = Ticket::factory()->for($account)->for($site)->create(['subject' => 'First request']);
    $second = Ticket::factory()->for($account)->for($site)->create(['subject' => 'Second request']);
    $second->labels()->attach($label);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertSee('data-ticket-bulk-form', false)
        ->assertSee('data-ticket-select-all', false)
        ->assertSee('name="ticket_ids[]"', false)
        ->assertSee('aria-label="Select ticket: First request"', false)
        ->assertSee('Review changes');

    $response = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$first->id, $second->id],
        'action' => 'add_label',
        'value' => (string) $label->id,
        'return_query' => ['ticket_status' => 'all'],
    ]);

    $response
        ->assertOk()
        ->assertViewIs('agent.tickets.bulk-confirm')
        ->assertViewHas('changedCount', 1)
        ->assertSee('Selected: 2. Changes: 1.')
        ->assertSee('First request')
        ->assertSee('Second request')
        ->assertSee('Not applied')
        ->assertSee('Billing')
        ->assertSee('Will change')
        ->assertSee('No change');

    expect($first->labels()->whereKey($label->id)->exists())->toBeFalse()
        ->and($second->labels()->whereKey($label->id)->exists())->toBeTrue()
        ->and(TicketBulkActionRun::query()->exists())->toBeFalse();
});

test('a reviewed label action changes only necessary tickets and its undo preserves pre-existing labels', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $label = TicketLabel::factory()->for($account)->create(['name' => 'VIP']);
    $first = Ticket::factory()->for($account)->for($site)->create();
    $second = Ticket::factory()->for($account)->for($site)->create();
    $second->labels()->attach($label);
    Event::fake([TicketUpdated::class]);

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$first->id, $second->id],
        'action' => 'add_label',
        'value' => (string) $label->id,
        'return_query' => ['ticket_status' => 'all'],
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertRedirect(route('dashboard.tickets.index', ['ticket_status' => 'all']))
        ->assertSessionHas('ticket_bulk_status', fn (array $status): bool => $status['changed'] === 1 && $status['selected'] === 2);

    $run = TicketBulkActionRun::query()->sole();
    $audit = $first->auditEvents()->where('action', 'ticket.label_added')->sole();

    expect($run->item_count)->toBe(2)
        ->and($run->changed_count)->toBe(1)
        ->and($run->changes)->toHaveCount(1)
        ->and($audit->metadata['ticket_bulk_action_run_id'])->toBe($run->id)
        ->and($first->labels()->whereKey($label->id)->exists())->toBeTrue();
    Event::assertNotDispatched(TicketUpdated::class);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.undo', $run))
        ->assertRedirect(route('dashboard.tickets.index', ['ticket_status' => 'all']))
        ->assertSessionHas('ticket_bulk_status', fn (array $status): bool => $status['reverted'] === 1 && $status['skipped'] === 0);

    expect($first->labels()->whereKey($label->id)->exists())->toBeFalse()
        ->and($second->labels()->whereKey($label->id)->exists())->toBeTrue()
        ->and($run->fresh()->undone_at)->not->toBeNull();
});

test('bulk assignment records the run and dispatches one ticket update per changed ticket', function (): void {
    Notification::fake();
    Event::fake([TicketUpdated::class]);
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create(['name' => 'Taylor']);
    $site = Site::factory()->for($account)->create();
    $first = Ticket::factory()->for($account)->for($site)->create(['assignee_id' => null]);
    $second = Ticket::factory()->for($account)->for($site)->for($target, 'assignee')->create();

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$first->id, $second->id],
        'action' => 'assign_agent',
        'value' => (string) $target->id,
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertRedirect(route('dashboard.tickets.index'));

    $run = TicketBulkActionRun::query()->sole();
    $audit = $first->auditEvents()->where('action', 'ticket.assignee_updated')->sole();

    expect($first->fresh()->assignee_id)->toBe($target->id)
        ->and($run->changed_count)->toBe(1)
        ->and($audit->metadata['source'])->toBe('bulk_action')
        ->and($audit->metadata['ticket_bulk_action_run_id'])->toBe($run->id);
    Event::assertDispatchedTimes(TicketUpdated::class, 1);
});

test('a bulk state change runs each matching ticket updated rule once', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'priority' => 'normal',
        'status' => 'open',
    ]);
    $rule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Hold urgent tickets',
        'event' => AutomationRuleEvent::TicketUpdated,
        'conditions' => [[
            'field' => 'priority',
            'operator' => 'equals',
            'value' => 'urgent',
        ]],
        'actions' => [['type' => 'set_status', 'value' => 'pending']],
    ]);

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$ticket->id],
        'action' => 'set_priority',
        'value' => 'urgent',
    ]);
    $this->actingAs($agent)->post(route('dashboard.tickets.bulk.store'), [
        'preview_token' => $preview->viewData('token'),
    ]);

    expect($ticket->fresh())
        ->priority->toBe('urgent')
        ->status->toBe('pending')
        ->and(AutomationRuleExecution::query()->where('automation_rule_id', $rule->id)->count())->toBe(1);
});

test('priority and lifecycle bulk actions write their normal audit events', function (string $action, ?string $value, array $initial, array $expected, string $auditAction): void {
    Event::fake([TicketUpdated::class]);
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create($initial);

    $payload = [
        'ticket_ids' => [$ticket->id],
        'action' => $action,
    ];

    if ($value !== null) {
        $payload['value'] = $value;
    }

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), $payload);
    $preview->assertOk()->assertViewHas('changedCount', 1);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertRedirect(route('dashboard.tickets.index'));

    $fresh = $ticket->fresh();
    $run = TicketBulkActionRun::query()->sole();
    $audit = $ticket->auditEvents()->where('action', $auditAction)->sole();

    foreach ($expected as $field => $expectedValue) {
        expect($fresh->{$field})->toBe($expectedValue);
    }

    expect($audit->metadata['ticket_bulk_action_run_id'])->toBe($run->id);
    Event::assertDispatchedTimes(TicketUpdated::class, 1);
})->with([
    'priority' => ['set_priority', 'urgent', ['priority' => 'normal'], ['priority' => 'urgent'], 'ticket.updated'],
    'pending status' => ['set_status', 'pending', ['status' => 'open'], ['status' => 'pending'], 'ticket.pending'],
    'close' => ['close', null, ['status' => 'open'], ['status' => 'closed'], 'ticket.closed'],
]);

test('a stale review applies nothing and cannot be reused', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$ticket->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);
    $token = $preview->viewData('token');
    $ticket->forceFill(['priority' => 'urgent'])->save();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $token])
        ->assertRedirect(route('dashboard.tickets.index'))
        ->assertSessionHas('ticket_bulk_error', 'tickets.bulk.errors.preview_stale');

    expect($ticket->fresh()->priority)->toBe('urgent')
        ->and(TicketBulkActionRun::query()->exists())->toBeFalse();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $token])
        ->assertSessionHas('ticket_bulk_error', 'tickets.bulk.errors.preview_expired');
});

test('a renamed action target expires the exact review', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $label = TicketLabel::factory()->for($account)->create(['name' => 'Billing']);
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$ticket->id],
        'action' => 'add_label',
        'value' => (string) $label->id,
    ]);
    $label->forceFill(['name' => 'Accounts'])->save();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertSessionHas('ticket_bulk_error', 'tickets.bulk.errors.preview_stale');

    expect($ticket->labels()->exists())->toBeFalse()
        ->and(TicketBulkActionRun::query()->exists())->toBeFalse();
});

test('an all-noop review cannot be submitted around its disabled confirmation', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'high']);

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$ticket->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);

    $preview
        ->assertOk()
        ->assertViewHas('changedCount', 0)
        ->assertDontSee('name="preview_token"', false);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertSessionHas('ticket_bulk_error', 'tickets.bulk.errors.nothing_to_change');

    expect(TicketBulkActionRun::query()->exists())->toBeFalse();
});

test('the bulk review follows the agents dashboard language', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['locale' => 'de']);
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['status' => 'open']);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.preview'), [
            'ticket_ids' => [$ticket->id],
            'action' => 'close',
        ])
        ->assertOk()
        ->assertSee('<html lang="de">', false)
        ->assertSee('Sammeländerungen prüfen')
        ->assertSee('Ausgewählt: 1. Änderungen: 1.')
        ->assertDontSee('Review bulk changes');
});

test('undo skips a ticket when newer work touched the same field', function (): void {
    Event::fake([TicketUpdated::class]);
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);

    $preview = $this->actingAs($agent)->post(route('dashboard.tickets.bulk.preview'), [
        'ticket_ids' => [$ticket->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);
    $this->actingAs($agent)->post(route('dashboard.tickets.bulk.store'), [
        'preview_token' => $preview->viewData('token'),
    ]);
    $run = TicketBulkActionRun::query()->sole();

    $ticket->forceFill(['priority' => 'urgent'])->save();
    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.updated',
        'metadata' => ['changes' => ['priority' => ['old' => 'high', 'new' => 'urgent']]],
        'occurred_at' => now(),
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.undo', $run))
        ->assertSessionHas('ticket_bulk_status', fn (array $status): bool => $status['reverted'] === 0 && $status['skipped'] === 1);

    expect($ticket->fresh()->priority)->toBe('urgent')
        ->and($run->fresh()->undo_result)->toMatchArray(['reverted' => 0, 'skipped' => 1]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.undo', $run))
        ->assertSessionHas('ticket_bulk_error', 'tickets.bulk.errors.already_undone');
});

test('bulk actions reject tickets and assignment targets outside the current scope', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);
    $foreignTicket = Ticket::factory()->for($otherAccount)->for(Site::factory()->for($otherAccount))->create();
    $foreignAgent = User::factory()->for($otherAccount)->create();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.preview'), [
            'ticket_ids' => [$ticket->id, $foreignTicket->id],
            'action' => 'set_priority',
            'value' => 'high',
        ])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.preview'), [
            'ticket_ids' => [$ticket->id],
            'action' => 'assign_agent',
            'value' => (string) $foreignAgent->id,
        ])
        ->assertSessionHasErrors('value');

    expect($ticket->fresh()->priority)->toBe('normal')
        ->and($ticket->fresh()->assignee_id)->toBeNull();
});

test('ticket managers without assignment permission do not see or invoke bulk assignment', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $target = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.index'))
        ->assertOk()
        ->assertDontSee('value="assign_agent"', false);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.preview'), [
            'ticket_ids' => [$ticket->id],
            'action' => 'assign_agent',
            'value' => (string) $target->id,
        ])
        ->assertForbidden();
});

test('an assignment target must support every selected ticket site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create();
    $firstSite = Site::factory()->for($account)->create();
    $secondSite = Site::factory()->for($account)->create();
    $firstSite->supportAgents()->attach([$agent->id, $target->id]);
    $secondSite->supportAgents()->attach($agent);
    $first = Ticket::factory()->for($account)->for($firstSite)->create();
    $second = Ticket::factory()->for($account)->for($secondSite)->create();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.bulk.preview'), [
            'ticket_ids' => [$first->id, $second->id],
            'action' => 'assign_agent',
            'value' => (string) $target->id,
        ])
        ->assertSessionHasErrors('value');

    expect($first->fresh()->assignee_id)->toBeNull()
        ->and($second->fresh()->assignee_id)->toBeNull();
});
