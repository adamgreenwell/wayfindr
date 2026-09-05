<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationBulkActionRun;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Conversations\ConversationPriorityLog;
use App\Support\SitePurge;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the conversation queue exposes accessible multi-selection and an exact no-write review', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create(['name' => 'Docs']);
    $first = Conversation::factory()->for($site)->create([
        'subject' => 'First request',
        'priority' => 'normal',
    ]);
    $second = Conversation::factory()->for($site)->create([
        'subject' => 'Second request',
        'priority' => 'high',
    ]);

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertSee('data-conversation-bulk-form', false)
        ->assertSee('data-conversation-select-all', false)
        ->assertSee('name="conversation_ids[]"', false)
        ->assertSee('aria-label="Select conversation: First request"', false)
        ->assertDontSee('value="add_label"', false)
        ->assertSee('Review changes');

    $response = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$first->id, $second->id],
        'action' => 'set_priority',
        'value' => 'high',
        'return_query' => ['conversation_filter' => 'all'],
    ]);

    $response
        ->assertOk()
        ->assertViewIs('agent.conversations.bulk-confirm')
        ->assertViewHas('changedCount', 1)
        ->assertSee('Selected: 2. Changes: 1.')
        ->assertSee('First request')
        ->assertSee('Second request')
        ->assertSee('Will change')
        ->assertSee('No change');

    expect($first->fresh()->priority)->toBe('normal')
        ->and($second->fresh()->priority)->toBe('high')
        ->and(ConversationBulkActionRun::query()->exists())->toBeFalse();
});

test('bulk assignment records one run and undo restores only changed conversations', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create(['name' => 'Taylor']);
    $site = Site::factory()->for($account)->create();
    $first = Conversation::factory()->for($site)->create(['assigned_agent_id' => null]);
    $second = Conversation::factory()->for($site)->create(['assigned_agent_id' => $target->id]);

    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$first->id, $second->id],
        'action' => 'assign_agent',
        'value' => (string) $target->id,
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertRedirect(route('dashboard.conversations.index'))
        ->assertSessionHas('conversation_bulk_status', fn (array $status): bool => $status['changed'] === 1 && $status['selected'] === 2);

    $run = ConversationBulkActionRun::query()->sole();
    $audit = $first->auditEvents()->where('action', 'conversation.assignee_updated')->sole();

    expect($first->fresh()->assigned_agent_id)->toBe($target->id)
        ->and($run->changed_count)->toBe(1)
        ->and($audit->metadata['source'])->toBe('bulk_action')
        ->and($audit->metadata['conversation_bulk_action_run_id'])->toBe($run->id);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.undo', $run))
        ->assertSessionHas('conversation_bulk_status', fn (array $status): bool => $status['reverted'] === 1 && $status['skipped'] === 0);

    expect($first->fresh()->assigned_agent_id)->toBeNull()
        ->and($second->fresh()->assigned_agent_id)->toBe($target->id)
        ->and($run->fresh()->undone_at)->not->toBeNull();
});

test('priority and lifecycle bulk actions write attributable history and can be undone', function (string $action, ?string $value, array $initial, array $expected, string $auditAction): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create($initial);
    $payload = [
        'conversation_ids' => [$conversation->id],
        'action' => $action,
    ];

    if ($value !== null) {
        $payload['value'] = $value;
    }

    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), $payload);
    $preview->assertOk()->assertViewHas('changedCount', 1);
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertRedirect(route('dashboard.conversations.index'));

    $run = ConversationBulkActionRun::query()->sole();
    $audit = $conversation->auditEvents()->where('action', $auditAction)->sole();

    foreach ($expected as $field => $expectedValue) {
        expect($conversation->fresh()->{$field})->toBe($expectedValue);
    }

    expect($audit->metadata['conversation_bulk_action_run_id'])->toBe($run->id);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.undo', $run))
        ->assertSessionHas('conversation_bulk_status', fn (array $status): bool => $status['reverted'] === 1 && $status['skipped'] === 0);

    foreach ($initial as $field => $initialValue) {
        if ($field !== 'closed_at') {
            expect($conversation->fresh()->{$field})->toBe($initialValue);
        }
    }
})->with([
    'priority' => ['set_priority', 'urgent', ['priority' => 'normal'], ['priority' => 'urgent'], ConversationPriorityLog::UPDATED],
    'reopen' => ['set_status', 'open', ['status' => 'closed', 'closed_at' => now()], ['status' => 'open'], ConversationLifecycleLog::REOPENED],
    'close' => ['close', null, ['status' => 'open', 'closed_at' => null], ['status' => 'closed'], ConversationLifecycleLog::CLOSED],
]);

test('undoing a bulk reopen settles the new SLA episode before restoring the historical close time', function (): void {
    $this->freezeTime();
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    SlaPolicy::factory()->for($account)->create([
        'priority' => 'normal',
        'first_response_minutes' => 30,
        'resolution_minutes' => 60,
        'effective_at' => now()->subDay(),
    ]);
    $historicalClosedAt = now()->subDay();
    $conversation = Conversation::factory()->for($site)->create([
        'status' => 'closed',
        'closed_at' => $historicalClosedAt,
        'priority' => 'normal',
    ]);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$conversation->id],
        'action' => 'set_status',
        'value' => 'open',
    ]);

    $this->actingAs($agent)->post(route('dashboard.conversations.bulk.store'), [
        'preview_token' => $preview->viewData('token'),
    ]);
    $run = ConversationBulkActionRun::query()->sole();
    $resolution = $conversation->slaClocks()
        ->where('metric', SlaClock::METRIC_RESOLUTION)
        ->sole();

    $this->travel(5)->minutes();
    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.undo', $run))
        ->assertSessionHas('conversation_bulk_status', fn (array $status): bool => $status['reverted'] === 1);

    $resolution->refresh();
    expect($conversation->fresh()->closed_at?->getTimestamp())->toBe($historicalClosedAt->getTimestamp())
        ->and($resolution->satisfied_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($resolution->satisfied_at?->greaterThanOrEqualTo($resolution->started_at))->toBeTrue();
});

test('a stale conversation review applies nothing and cannot be reused', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['priority' => 'normal']);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$conversation->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);
    $token = $preview->viewData('token');
    $conversation->forceFill(['priority' => 'urgent'])->save();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.store'), ['preview_token' => $token])
        ->assertRedirect(route('dashboard.conversations.index'))
        ->assertSessionHas('conversation_bulk_error', 'conversations.bulk.errors.preview_stale');

    expect($conversation->fresh()->priority)->toBe('urgent')
        ->and(ConversationBulkActionRun::query()->exists())->toBeFalse();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.store'), ['preview_token' => $token])
        ->assertSessionHas('conversation_bulk_error', 'conversations.bulk.errors.preview_expired');
});

test('a renamed conversation assignment target expires the exact review', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create(['name' => 'Taylor']);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['assigned_agent_id' => null]);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$conversation->id],
        'action' => 'assign_agent',
        'value' => (string) $target->id,
    ]);
    $target->forceFill(['name' => 'Morgan'])->save();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertSessionHas('conversation_bulk_error', 'conversations.bulk.errors.preview_stale');

    expect($conversation->fresh()->assigned_agent_id)->toBeNull()
        ->and(ConversationBulkActionRun::query()->exists())->toBeFalse();
});

test('an all-noop conversation review cannot be submitted around its disabled confirmation', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['priority' => 'high']);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$conversation->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);

    $preview
        ->assertOk()
        ->assertViewHas('changedCount', 0)
        ->assertDontSee('name="preview_token"', false);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.store'), ['preview_token' => $preview->viewData('token')])
        ->assertSessionHas('conversation_bulk_error', 'conversations.bulk.errors.nothing_to_change');

    expect(ConversationBulkActionRun::query()->exists())->toBeFalse();
});

test('the conversation bulk review follows the agents dashboard language', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['locale' => 'de']);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['status' => 'open']);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.preview'), [
            'conversation_ids' => [$conversation->id],
            'action' => 'close',
        ])
        ->assertOk()
        ->assertSee('<html lang="de">', false)
        ->assertSee('Sammeländerungen prüfen')
        ->assertSee('Ausgewählt: 1. Änderungen: 1.')
        ->assertDontSee('Review bulk changes');
});

test('undo skips a conversation when later priority work returned to the same value', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['priority' => 'normal']);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$conversation->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);
    $this->actingAs($agent)->post(route('dashboard.conversations.bulk.store'), [
        'preview_token' => $preview->viewData('token'),
    ]);
    $run = ConversationBulkActionRun::query()->sole();

    $this->actingAs($agent)->put(route('dashboard.conversations.priority.update', $conversation->support_code), [
        'priority' => 'urgent',
    ]);
    $this->actingAs($agent)->put(route('dashboard.conversations.priority.update', $conversation->support_code), [
        'priority' => 'high',
    ]);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.undo', $run))
        ->assertSessionHas('conversation_bulk_status', fn (array $status): bool => $status['reverted'] === 0 && $status['skipped'] === 1);

    expect($conversation->fresh()->priority)->toBe('high')
        ->and($run->fresh()->undo_result)->toMatchArray(['reverted' => 0, 'skipped' => 1]);
});

test('conversation bulk actions reject conversations and targets outside the current scope', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create(['priority' => 'normal']);
    $foreignSite = Site::factory()->for($otherAccount)->create();
    $foreignConversation = Conversation::factory()->for($foreignSite)->create();
    $foreignAgent = User::factory()->for($otherAccount)->create();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.preview'), [
            'conversation_ids' => [$conversation->id, $foreignConversation->id],
            'action' => 'set_priority',
            'value' => 'high',
        ])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.preview'), [
            'conversation_ids' => [$conversation->id],
            'action' => 'assign_agent',
            'value' => (string) $foreignAgent->id,
        ])
        ->assertSessionHasErrors('value');

    expect($conversation->fresh()->priority)->toBe('normal')
        ->and($conversation->fresh()->assigned_agent_id)->toBeNull();
});

test('conversation viewers without management permission cannot see or invoke bulk actions', function (): void {
    $account = Account::factory()->create();
    $role = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ViewConversations->value],
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $role->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $conversation = Conversation::factory()->for($site)->create();

    $this->actingAs($agent)
        ->get(route('dashboard.conversations.index'))
        ->assertOk()
        ->assertDontSee('data-conversation-bulk-form', false)
        ->assertDontSee('name="conversation_ids[]"', false);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.preview'), [
            'conversation_ids' => [$conversation->id],
            'action' => 'close',
        ])
        ->assertForbidden();
});

test('a conversation assignment target must support every selected site', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $target = User::factory()->for($account)->create();
    $firstSite = Site::factory()->for($account)->create();
    $secondSite = Site::factory()->for($account)->create();
    $firstSite->supportAgents()->attach([$agent->id, $target->id]);
    $secondSite->supportAgents()->attach($agent);
    $first = Conversation::factory()->for($firstSite)->create();
    $second = Conversation::factory()->for($secondSite)->create();

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.preview'), [
            'conversation_ids' => [$first->id, $second->id],
            'action' => 'assign_agent',
            'value' => (string) $target->id,
        ])
        ->assertSessionHasErrors('value');

    expect($first->fresh()->assigned_agent_id)->toBeNull()
        ->and($second->fresh()->assigned_agent_id)->toBeNull();
});

test('undo authorizes every surviving conversation before reverting any of them', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $otherAgent = User::factory()->for($account)->create();
    $openSite = Site::factory()->for($account)->create();
    $restrictedSite = Site::factory()->for($account)->create();
    $restrictedSite->supportAgents()->attach([$agent->id, $otherAgent->id]);
    $first = Conversation::factory()->for($openSite)->create(['priority' => 'normal']);
    $second = Conversation::factory()->for($restrictedSite)->create(['priority' => 'normal']);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$first->id, $second->id],
        'action' => 'set_priority',
        'value' => 'high',
    ]);
    $this->actingAs($agent)->post(route('dashboard.conversations.bulk.store'), [
        'preview_token' => $preview->viewData('token'),
    ]);
    $run = ConversationBulkActionRun::query()->sole();
    $restrictedSite->supportAgents()->detach($agent);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.undo', $run))
        ->assertNotFound();

    expect($first->fresh()->priority)->toBe('high')
        ->and($second->fresh()->priority)->toBe('high')
        ->and($run->fresh()->undone_at)->toBeNull();
});

test('site purge leaves no copied conversation content and undo restores survivors', function (): void {
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create();
    $purgedSite = Site::factory()->for($account)->create(['name' => 'Secret workspace']);
    $survivingSite = Site::factory()->for($account)->create();
    $purged = Conversation::factory()->for($purgedSite)->create([
        'subject' => 'Private customer incident',
        'priority' => 'normal',
    ]);
    $surviving = Conversation::factory()->for($survivingSite)->create(['priority' => 'normal']);
    $preview = $this->actingAs($agent)->post(route('dashboard.conversations.bulk.preview'), [
        'conversation_ids' => [$purged->id, $surviving->id],
        'action' => 'set_priority',
        'value' => 'urgent',
    ]);
    $this->actingAs($agent)->post(route('dashboard.conversations.bulk.store'), [
        'preview_token' => $preview->viewData('token'),
    ]);
    $run = ConversationBulkActionRun::query()->sole();

    expect(json_encode($run->changes))
        ->not->toContain('Private customer incident')
        ->not->toContain('Secret workspace');

    app(SitePurge::class)->purge($purgedSite, $agent);

    $this->actingAs($agent)
        ->post(route('dashboard.conversations.bulk.undo', $run))
        ->assertSessionHas('conversation_bulk_status', fn (array $status): bool => $status['reverted'] === 1 && $status['skipped'] === 1);

    expect($surviving->fresh()->priority)->toBe('normal')
        ->and($run->fresh()->undone_at)->not->toBeNull();
});
