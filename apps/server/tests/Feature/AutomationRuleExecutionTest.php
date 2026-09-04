<?php

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketLabel;
use App\Models\User;
use App\Models\Visitor;
use App\Notifications\AutomationRuleMatched;
use App\Notifications\TicketAssigned;
use App\Support\AlertDigestCandidateCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('a matching ticket rule executes the six bounded actions in order and records the run', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $assignee = User::factory()->for($account)->create();
    $notifiedAgent = User::factory()->for($account)->create();
    $label = TicketLabel::factory()->for($account)->create();
    $rule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Urgent billing intake',
        'event' => AutomationRuleEvent::TicketCreated,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'billing'],
        ],
        'actions' => [
            ['type' => 'assign_agent', 'value' => $assignee->id],
            ['type' => 'add_label', 'value' => $label->id],
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'set_status', 'value' => 'pending'],
            ['type' => 'notify_agent', 'value' => $notifiedAgent->id],
            ['type' => 'post_internal_note', 'value' => 'Check the duplicate invoice privately.'],
        ],
    ]);

    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Billing charged us twice',
        'priority' => 'normal',
        'status' => 'open',
    ])->fresh();

    expect($ticket->assignee_id)->toBe($assignee->id)
        ->and($ticket->priority)->toBe('urgent')
        ->and($ticket->status)->toBe('pending')
        ->and($ticket->labels()->whereKey($label->id)->exists())->toBeTrue();

    $assignment = $ticket->auditEvents()->where('action', 'ticket.assignee_updated')->sole();
    $note = $ticket->auditEvents()->where('action', 'ticket.note_added')->sole();

    expect($assignment->actor_type)->toBeNull()
        ->and($assignment->metadata['source'])->toBe('automation')
        ->and($note->actor_type)->toBeNull()
        ->and($note->metadata['body'])->toBe('Check the duplicate invoice privately.')
        ->and($note->metadata['automation_rule_id'])->toBe($rule->id)
        ->and($ticket->auditEvents()->where('action', 'ticket.pending')->count())->toBe(1);

    $execution = AutomationRuleExecution::query()->sole();

    expect($execution->status)->toBe('succeeded')
        ->and($execution->statusEnum())->toBe(AutomationExecutionStatus::Succeeded)
        ->and($execution->rule->is($rule))->toBeTrue()
        ->and($execution->subject->is($ticket))->toBeTrue()
        ->and($execution->conditions)->toBe($rule->conditions)
        ->and($execution->actions)->toBe($rule->actions)
        ->and(array_column($execution->action_results, 'type'))->toBe([
            'assign_agent',
            'add_label',
            'set_priority',
            'set_status',
            'notify_agent',
            'post_internal_note',
        ])
        ->and(array_column($execution->action_results, 'status'))->toBe([
            'applied',
            'applied',
            'applied',
            'applied',
            'queued',
            'applied',
        ]);

    Notification::assertSentTo($assignee, TicketAssigned::class);
    Notification::assertSentTo($notifiedAgent, AutomationRuleMatched::class);
});

test('visitor messages can trigger conversation actions without creating a visitor-facing message', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'priority' => 'normal',
        'status' => 'open',
    ]);
    $assignee = User::factory()->for($account)->create();
    $notifiedAgent = User::factory()->for($account)->create();
    $rule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Refund escalation',
        'event' => AutomationRuleEvent::VisitorMessageCreated,
        'conditions' => [
            ['field' => 'message_body', 'operator' => 'contains', 'value' => 'refund'],
        ],
        'actions' => [
            ['type' => 'assign_agent', 'value' => $assignee->id],
            ['type' => 'set_priority', 'value' => 'high'],
            ['type' => 'set_status', 'value' => 'closed'],
            ['type' => 'notify_agent', 'value' => $notifiedAgent->id],
        ],
    ]);

    $message = ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'I need a refund for the duplicate charge.',
    ]);
    $conversation->refresh();

    expect($conversation->assigned_agent_id)->toBe($assignee->id)
        ->and($conversation->priority)->toBe('high')
        ->and($conversation->status)->toBe('closed')
        ->and($conversation->messages()->count())->toBe(1)
        ->and($conversation->messages()->sole()->is($message))->toBeTrue()
        ->and($conversation->auditEvents()->where('action', 'conversation.closed')->count())->toBe(1)
        ->and($conversation->auditEvents()->where('action', 'conversation.assignee_updated')->sole()->metadata['source'])->toBe('automation');

    $execution = AutomationRuleExecution::query()->sole();

    expect($execution->rule_name)->toBe($rule->name)
        ->and($execution->metadata['message_id'])->toBe($message->id)
        ->and(array_column($execution->action_results, 'type'))->toBe([
            'assign_agent',
            'set_priority',
            'set_status',
            'notify_agent',
        ]);

    Notification::assertSentTo($notifiedAgent, AutomationRuleMatched::class);
});

test('a failed action rolls back its sequence records the failure and lets the next rule run', function (): void {
    Notification::fake();

    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $notifiedAgent = User::factory()->for($account)->create();
    $wrongAccountAgent = User::factory()->for($otherAccount)->create();
    $failedRule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Broken target',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 0,
        'actions' => [
            ['type' => 'set_priority', 'value' => 'urgent'],
            ['type' => 'notify_agent', 'value' => $notifiedAgent->id],
            ['type' => 'assign_agent', 'value' => $wrongAccountAgent->id],
        ],
    ]);
    $survivingRule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Still runs',
        'event' => AutomationRuleEvent::TicketCreated,
        'position' => 10,
        'actions' => [
            ['type' => 'post_internal_note', 'value' => 'The later rule still ran.'],
        ],
    ]);

    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'priority' => 'normal',
    ])->fresh();

    expect($ticket->priority)->toBe('normal')
        ->and($ticket->assignee_id)->toBeNull()
        ->and($ticket->auditEvents()->where('action', 'ticket.updated')->count())->toBe(0)
        ->and($ticket->auditEvents()->where('action', 'ticket.note_added')->sole()->metadata['body'])
        ->toBe('The later rule still ran.');

    $executions = AutomationRuleExecution::query()->orderBy('id')->get();

    expect($executions)->toHaveCount(2)
        ->and($executions[0]->automation_rule_id)->toBe($failedRule->id)
        ->and($executions[0]->status)->toBe('failed')
        ->and($executions[0]->action_results)->toBe([])
        ->and($executions[0]->error_message)->toContain('cannot access this automation subject')
        ->and($executions[1]->automation_rule_id)->toBe($survivingRule->id)
        ->and($executions[1]->status)->toBe('succeeded');

    Notification::assertNotSentTo($notifiedAgent, AutomationRuleMatched::class);
});

test('a ticket updated rule cannot recursively trigger itself', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'priority' => 'normal',
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'event' => AutomationRuleEvent::TicketUpdated,
        'conditions' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'changed'],
        ],
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    $ticket->forceFill(['subject' => 'Changed by an agent'])->save();

    expect($ticket->fresh()->priority)->toBe('high')
        ->and(AutomationRuleExecution::query()->count())->toBe(1)
        ->and(AutomationRuleExecution::query()->sole()->status)->toBe('succeeded');
});

test('execution snapshots survive rule deletion', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $rule = AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Disposable rule',
        'event' => AutomationRuleEvent::TicketCreated,
        'actions' => [['type' => 'set_priority', 'value' => 'high']],
    ]);

    Ticket::factory()->for($account)->for($site)->create();
    $rule->delete();
    $execution = AutomationRuleExecution::query()->sole();

    expect($execution->automation_rule_id)->toBeNull()
        ->and($execution->rule_name)->toBe('Disposable rule')
        ->and($execution->actions)->toBe([['type' => 'set_priority', 'value' => 'high']]);
});

test('automation notifications are visible searchable digestible and hidden after access is revoked', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $agent = User::factory()->for($account)->create([
        'alert_preferences' => [
            'mode' => User::ALERT_MODE_ALL,
            'email' => true,
            'cadence' => User::ALERT_CADENCE_DIGEST,
        ],
    ]);
    $remainingAgent = User::factory()->for($account)->create();
    AutomationRule::factory()->for($account)->enabled()->create([
        'name' => 'Billing beacon',
        'event' => AutomationRuleEvent::TicketCreated,
        'actions' => [['type' => 'notify_agent', 'value' => $agent->id]],
    ]);
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Invoice needs a second look',
    ]);
    $notification = $agent->notifications()->sole();

    expect($notification->type)->toBe(AutomationRuleMatched::class)
        ->and($notification->data)->toMatchArray([
            'kind' => 'automation_rule_matched',
            'rule_name' => 'Billing beacon',
            'subject_kind' => 'ticket',
            'subject_id' => $ticket->id,
            'ticket_id' => $ticket->id,
        ])
        ->and(Gate::forUser($agent)->allows('view', $notification))->toBeTrue()
        ->and(app(AlertDigestCandidateCollector::class)->forAgent($agent)->sole()['kind'])
        ->toBe('automation_rule_matched');

    $this->actingAs($agent)
        ->get(route('dashboard.alerts.index', [
            'alert_kind' => 'ticket',
            'alert_search' => 'Billing beacon',
        ]))
        ->assertOk()
        ->assertSee('Automation matched this support work')
        ->assertSee('Billing beacon')
        ->assertSee('Ticket #'.$ticket->id);

    $site->supportAgents()->sync([$remainingAgent->id]);

    expect(Gate::forUser($agent->fresh())->allows('view', $notification->fresh()))->toBeFalse();

    $this->actingAs($agent->fresh())
        ->get(route('dashboard.alerts.index'))
        ->assertOk()
        ->assertDontSee('Billing beacon');
});

test('a notify action honors quiet mode without failing the rule', function (): void {
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $quietAgent = User::factory()->for($account)->create([
        'alert_preferences' => ['mode' => User::ALERT_MODE_QUIET],
    ]);
    AutomationRule::factory()->for($account)->enabled()->create([
        'event' => AutomationRuleEvent::TicketCreated,
        'actions' => [['type' => 'notify_agent', 'value' => $quietAgent->id]],
    ]);

    Ticket::factory()->for($account)->for($site)->create();
    $execution = AutomationRuleExecution::query()->sole();

    expect($execution->status)->toBe('succeeded')
        ->and($execution->action_results)->toBe([[
            'type' => 'notify_agent',
            'status' => 'noop',
            'detail' => 'quiet_mode',
        ]])
        ->and($quietAgent->notifications()->count())->toBe(0);
});
