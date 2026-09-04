<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\CustomRole;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('conversation state and ticket mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $conversationRole = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::ManageConversations->value,
            AccountPermission::ManageTickets->value,
        ],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $conversationRole->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-STALE-STATE',
        'assigned_agent_id' => $action === 'release' ? $agent->id : null,
        'status' => $action === 'reopen' ? 'closed' : 'open',
        'closed_at' => $action === 'reopen' ? now() : null,
    ]);

    $this->actingAs($agent);
    expect($agent->hasAccountPermission(AccountPermission::ManageConversations))->toBeTrue();
    User::query()->whereKey($agent->id)->update(['custom_role_id' => $revokedRole->id]);

    $before = $conversation->fresh()->getRawOriginal();

    $response = match ($action) {
        'close' => $this->post(route('dashboard.conversations.close', $conversation->support_code)),
        'reopen' => $this->post(route('dashboard.conversations.reopen', $conversation->support_code)),
        'claim' => $this->post(route('dashboard.conversations.claim', $conversation->support_code)),
        'release' => $this->post(route('dashboard.conversations.release', $conversation->support_code)),
        'create ticket' => $this->post(route('dashboard.conversations.tickets.store', $conversation->support_code)),
    };

    $response->assertNotFound();

    expect($conversation->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('audit_events', 0);
})->with(['close', 'reopen', 'claim', 'release', 'create ticket']);

test('cobrowse mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $cobrowseRole = CustomRole::factory()->for($account)->create([
        'permissions' => [
            AccountPermission::ViewConversations->value,
            AccountPermission::RequestCobrowse->value,
        ],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $cobrowseRole->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-STALE-COBROWSE',
    ]);
    $cobrowseSession = in_array($action, ['end', 'resync'], true)
        ? CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
            'requested_by_id' => $agent->id,
            'status' => 'granted',
            'consented_at' => now()->subMinute(),
            'ended_at' => null,
        ])
        : null;

    $this->actingAs($agent);
    expect($agent->hasAccountPermission(AccountPermission::RequestCobrowse))->toBeTrue();
    User::query()->whereKey($agent->id)->update(['custom_role_id' => $revokedRole->id]);

    $before = $cobrowseSession?->fresh()->getRawOriginal();
    $beforeCount = CobrowseSession::query()->count();

    $response = $this->post(route("dashboard.conversations.cobrowse.{$action}", $conversation->support_code));

    $response->assertNotFound();

    expect(CobrowseSession::query()->count())->toBe($beforeCount);

    if ($cobrowseSession) {
        expect($cobrowseSession->fresh()->getRawOriginal())->toBe($before);
    }

    $this->assertDatabaseCount('audit_events', 0);
})->with(['request', 'end', 'resync']);
