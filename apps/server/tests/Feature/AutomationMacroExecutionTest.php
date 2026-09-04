<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Enums\AutomationExecutionStatus;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationMacro;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\ConversationNeedsReply;
use App\Notifications\TicketAssigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('an agent can apply the complete ticket action sequence in one click', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create();
    $assignee = User::factory()->for($account)->create();
    $notifiedAgent = User::factory()->for($account)->create();
    $label = TicketLabel::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'priority' => 'normal',
        'status' => 'open',
    ]);
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Prepare billing handoff',
        'subject_type' => 'ticket',
        'actions' => [
            ['type' => 'assign_agent', 'value' => $assignee->id],
            ['type' => 'add_label', 'value' => $label->id],
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'set_status', 'value' => 'pending'],
            ['type' => 'notify_agent', 'value' => $notifiedAgent->id],
            ['type' => 'post_internal_note', 'value' => 'Billing handoff prepared privately.'],
        ],
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Prepare billing handoff');

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $macro]))
        ->assertRedirect(route('dashboard.tickets.show', $ticket))
        ->assertSessionHas('status', 'automation_macros.flash.applied');

    $ticket->refresh();

    expect($ticket->assignee_id)->toBe($assignee->id)
        ->and($ticket->priority)->toBe('urgent')
        ->and($ticket->status)->toBe('pending')
        ->and($ticket->labels()->whereKey($label->id)->exists())->toBeTrue();

    $assignment = $ticket->auditEvents()->where('action', 'ticket.assignee_updated')->sole();
    $note = $ticket->auditEvents()->where('action', 'ticket.note_added')->sole();

    expect($assignment->actor->is($agent))->toBeTrue()
        ->and($assignment->metadata['source'])->toBe('macro')
        ->and($note->actor->is($agent))->toBeTrue()
        ->and($note->metadata['source'])->toBe('macro')
        ->and($note->metadata['automation_macro_id'])->toBe($macro->id)
        ->and($note->metadata['body'])->toBe('Billing handoff prepared privately.')
        ->and($ticket->auditEvents()->where('action', 'ticket.pending')->sole()->actor->is($agent))->toBeTrue();

    $execution = AutomationRuleExecution::query()->sole();

    expect($execution->automation_rule_id)->toBeNull()
        ->and($execution->macro->is($macro))->toBeTrue()
        ->and($execution->triggeredBy->is($agent))->toBeTrue()
        ->and($macro->executions()->sole()->is($execution))->toBeTrue()
        ->and($execution->rule_name)->toBe($macro->name)
        ->and($execution->event)->toBe('macro.ticket')
        ->and($execution->statusEnum())->toBe(AutomationExecutionStatus::Succeeded)
        ->and($execution->subject->is($ticket))->toBeTrue()
        ->and($execution->metadata)->toMatchArray([
            'source' => 'macro',
            'automation_macro_id' => $macro->id,
            'triggered_by_user_id' => $agent->id,
            'triggered_by_name' => $agent->name,
        ])
        ->and(array_column($execution->action_results, 'type'))->toBe([
            'assign_agent',
            'add_label',
            'set_priority',
            'set_status',
            'notify_agent',
            'post_internal_note',
        ]);

    $macroAudit = AuditEvent::query()->where('action', 'automation_macro.applied')->sole();
    expect($macroAudit->actor->is($agent))->toBeTrue()
        ->and($macroAudit->subject->is($macro))->toBeTrue()
        ->and($macroAudit->metadata['execution_id'])->toBe($execution->id);

    Notification::assertSentTo($assignee, TicketAssigned::class);
    Notification::assertSentTo(
        $notifiedAgent,
        AutomationRuleMatched::class,
        fn (AutomationRuleMatched $notification): bool => $notification->toArray($notifiedAgent)['automation_kind'] === 'macro',
    );

    $macro->delete();
    expect($execution->fresh()->automation_macro_id)->toBeNull()
        ->and($execution->fresh()->macro)->toBeNull()
        ->and($execution->fresh()->rule_name)->toBe('Prepare billing handoff');
});

test('a conversation macro changes only internal work state and records the agent', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $agent = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $assignee = User::factory()->for($account)->create();
    $notifiedAgent = User::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'priority' => 'normal',
        'status' => 'open',
    ]);
    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'Please check this.',
    ]);
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Close urgent conversation',
        'subject_type' => 'conversation',
        'actions' => [
            ['type' => 'assign_agent', 'value' => $assignee->id],
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'set_status', 'value' => 'closed'],
            ['type' => 'notify_agent', 'value' => $notifiedAgent->id],
        ],
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.macros.run', [$conversation->support_code, $macro]))
        ->assertRedirect(route('dashboard.conversations.show', $conversation->support_code))
        ->assertSessionHas('status', 'automation_macros.flash.applied');

    $conversation->refresh();

    expect($conversation->assigned_agent_id)->toBe($assignee->id)
        ->and($conversation->priority)->toBe('urgent')
        ->and($conversation->status)->toBe('closed')
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->auditEvents()->where('action', 'conversation.assignee_updated')->sole()->actor->is($agent))->toBeTrue()
        ->and($conversation->auditEvents()->where('action', 'conversation.closed')->sole()->actor->is($agent))->toBeTrue();

    $execution = AutomationRuleExecution::query()->sole();
    expect($execution->metadata['source'])->toBe('macro')
        ->and($execution->metadata['triggered_by_user_id'])->toBe($agent->id)
        ->and($execution->actions)->toBe($macro->actions);

    Notification::assertSentTo(
        $notifiedAgent,
        AutomationRuleMatched::class,
        fn (AutomationRuleMatched $notification): bool => $notification->toArray($notifiedAgent)['automation_kind'] === 'macro',
    );
    Notification::assertNotSentTo($assignee, ConversationNeedsReply::class);

    $this->actingAs($agent)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Manual Conversation macro')
        ->assertSee('Close urgent conversation');
});

test('macro buttons and run endpoints cannot launder support permissions', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $limitedRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $agent = User::factory()->for($account)->create(['custom_role_id' => $limitedRole->id]);
    $target = User::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);
    $priorityMacro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Raise priority',
        'subject_type' => 'ticket',
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);
    $assignmentMacro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Assign specialist',
        'subject_type' => 'ticket',
        'actions' => [['type' => 'assign_agent', 'value' => $target->id]],
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Raise priority')
        ->assertDontSee('Assign specialist');

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $assignmentMacro]))
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $priorityMacro]))
        ->assertRedirect();

    expect($ticket->fresh()->priority)->toBe('high')
        ->and($ticket->fresh()->assignee_id)->toBeNull();
});

test('view-only conversation agents can run notification macros but not state-changing macros', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $viewRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $agent = User::factory()->for($account)->create(['custom_role_id' => $viewRole->id]);
    $target = User::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create();
    $notifyMacro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Notify lead',
        'subject_type' => 'conversation',
        'actions' => [['type' => 'notify_agent', 'value' => $target->id]],
    ]);
    $closeMacro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Close conversation',
        'subject_type' => 'conversation',
        'actions' => [['type' => 'set_status', 'value' => 'closed']],
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.show', $conversation->support_code))
        ->assertOk()
        ->assertSee('Notify lead')
        ->assertDontSee('Close conversation');

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.macros.run', [$conversation->support_code, $closeMacro]))
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.macros.run', [$conversation->support_code, $notifyMacro]))
        ->assertRedirect();

    expect($conversation->fresh()->status)->toBe('open');
    Notification::assertSentTo($target, AutomationRuleMatched::class);
});

test('draft wrong-type and cross-account macros cannot be applied', function (string $case): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $macro = match ($case) {
        'draft' => AutomationMacro::factory()->for($account)->create(['subject_type' => 'ticket']),
        'wrong type' => AutomationMacro::factory()->for($account)->enabled()->create(['subject_type' => 'conversation']),
        'other account' => AutomationMacro::factory()->for($otherAccount)->enabled()->create(['subject_type' => 'ticket']),
    };

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $macro]))
        ->assertNotFound();

    expect(AutomationRuleExecution::query()->count())->toBe(0);
})->with(['draft', 'wrong type', 'other account']);

test('macro execution reauthorizes a stale actor under the account lock', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $workRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create(['custom_role_id' => $workRole->id]);
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'subject_type' => 'ticket',
        'actions' => [['type' => 'set_priority', 'value' => 'urgent']],
    ]);

    $this->actingAs($agent);
    expect($agent->hasAccountPermission(AccountPermission::ManageTickets))->toBeTrue();
    User::query()->whereKey($agent->id)->update(['custom_role_id' => $revokedRole->id]);

    $this->post(route('dashboard.tickets.macros.run', [$ticket, $macro]))
        ->assertNotFound();

    expect($ticket->fresh()->priority)->toBe('normal')
        ->and(AutomationRuleExecution::query()->count())->toBe(0);
});

test('stale macro targets become explicit no-ops and later actions still run', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create(['deactivated_at' => now()]);
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'subject_type' => 'ticket',
        'actions' => [
            ['type' => 'assign_agent', 'value' => $target->id],
            ['type' => 'notify_agent', 'value' => $target->id],
            ['type' => 'set_priority', 'value' => 'urgent'],
        ],
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $macro]))
        ->assertRedirect()
        ->assertSessionHas('status', 'automation_macros.flash.applied');

    $execution = AutomationRuleExecution::query()->sole();
    expect($ticket->fresh()->assignee_id)->toBeNull()
        ->and($ticket->fresh()->priority)->toBe('urgent')
        ->and($execution->action_results)->toBe([
            ['type' => 'assign_agent', 'status' => 'noop', 'detail' => 'target_unavailable'],
            ['type' => 'notify_agent', 'status' => 'noop', 'detail' => 'target_unavailable'],
            ['type' => 'set_priority', 'status' => 'applied', 'detail' => 'normal->urgent'],
        ]);
    Notification::assertNothingSent();
});

test('a corrupt macro rolls back its whole sequence and records a failed execution', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create();
    $initialTarget = User::factory()->for($account)->create();
    $wrongTarget = User::factory()->for($otherAccount)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['priority' => 'normal']);
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'subject_type' => 'ticket',
        'actions' => [
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'assign_agent', 'value' => $initialTarget->id],
        ],
    ]);

    DB::table('automation_macros')->where('id', $macro->id)->update([
        'actions' => json_encode([
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'assign_agent', 'value' => $wrongTarget->id],
        ], JSON_THROW_ON_ERROR),
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $macro->fresh()]))
        ->assertRedirect()
        ->assertSessionHas('status', 'automation_macros.flash.failed');

    $execution = AutomationRuleExecution::query()->sole();
    expect($ticket->fresh()->priority)->toBe('normal')
        ->and($ticket->fresh()->assignee_id)->toBeNull()
        ->and($ticket->auditEvents()->where('action', 'ticket.updated')->count())->toBe(0)
        ->and($execution->status)->toBe('failed')
        ->and($execution->metadata['source'])->toBe('macro')
        ->and($execution->action_results)->toBe([])
        ->and($execution->error_message)->toContain('not available to this automation macro');
});

test('the automation log identifies manual macro runs and their agent', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Log me',
        'subject_type' => 'ticket',
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    $this->actingAs($admin)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $macro]))
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('dashboard.account.automation-rules.index'))
        ->assertOk()
        ->assertSee('Log me')
        ->assertSee('Manual Ticket macro')
        ->assertSee('Applied by')
        ->assertSee($admin->name);
});

test('macro notification actions identify themselves as macros in the alert inbox', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Billing follow-up',
    ]);
    $macro = AutomationMacro::factory()->for($account)->enabled()->create([
        'name' => 'Notify billing lead',
        'subject_type' => 'ticket',
        'actions' => [['type' => 'notify_agent', 'value' => $target->id]],
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.tickets.macros.run', [$ticket, $macro]))
        ->assertRedirect();

    $notification = $target->notifications()->sole();
    expect($notification->data)->toMatchArray([
        'kind' => 'automation_rule_matched',
        'automation_kind' => 'macro',
        'rule_name' => 'Notify billing lead',
    ]);

    $this->actingAs($target)
        ->get(route('dashboard.alerts.index', ['alert_search' => 'Notify billing lead']))
        ->assertOk()
        ->assertSee('A macro notified you about this support work')
        ->assertSee('Macro:')
        ->assertSee('Notify billing lead')
        ->assertDontSee('Rule:');
});
