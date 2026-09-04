<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

test('ticket assignment mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    Notification::fake();
    $account = Account::factory()->create();
    $assignmentRole = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ManageTickets->value,
            AccountPermission::AssignTickets->value,
        ],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $actor = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $assignmentRole->id,
    ]);
    $target = User::factory()->for($account)->create();
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach([$actor->id, $target->id]);
    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($actor, 'assignee')
        ->create();

    $this->actingAs($actor);
    expect($actor->hasAccountPermission(AccountPermission::AssignTickets))->toBeTrue();
    User::query()->whereKey($actor->id)->update(['custom_role_id' => $revokedRole->id]);

    $response = match ($action) {
        'assign' => $this->put(route('dashboard.tickets.assignee.update', $ticket), [
            'assignee_id' => $target->id,
        ]),
        'escalate' => $this->post(route('dashboard.tickets.escalations.store', $ticket), [
            'target_agent_id' => $target->id,
            'reason' => 'Needs another agent.',
        ]),
    };

    $response->assertNotFound();

    expect($ticket->fresh()->assignee_id)->toBe($actor->id);
    $this->assertDatabaseCount('audit_events', 0);
    Notification::assertNothingSent();
})->with(['assign', 'escalate']);
