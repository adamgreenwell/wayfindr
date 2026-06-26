<?php

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\OperatorReadinessConfirmation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('operator console requires authentication', function (): void {
    $this->get('/operator')
        ->assertRedirect('/login');
});

test('account roles do not grant platform operator access', function (AccountRole $accountRole): void {
    $agent = User::factory()->for(Account::factory())->create([
        'account_role' => $accountRole,
    ]);

    $this->actingAs($agent)
        ->get('/operator')
        ->assertForbidden();
})->with([
    'owner' => AccountRole::Owner,
    'admin' => AccountRole::Admin,
    'agent' => AccountRole::Agent,
]);

test('explicit platform operators can inspect the operator console', function (): void {
    $operator = User::factory()->for(Account::factory(['name' => 'Wayfindr Ops']))->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Operator console')
        ->assertSee('Instance readiness')
        ->assertSee('Dogfood readiness')
        ->assertSee('Retention posture')
        ->assertSee('Recommended next step')
        ->assertSee('Post-install smoke path')
        ->assertSee('Send a widget smoke test')
        ->assertSee('Platform operator access does not grant support data access.');
});

test('operator console shows a safe focus summary for the current instance posture', function (): void {
    $this->travelTo(Carbon::parse('2026-06-22 12:00:00'));

    $operator = User::factory()->for(Account::factory(['name' => 'Wayfindr Ops']))->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);
    $site = Site::factory()->create(['name' => 'Private Focus Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-focus-secret',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-OPFOCUS',
        'subject' => 'Private operator focus request',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subDay(),
        'note' => 'Scheduler proof mentioned WF-OPFOCUS.',
    ]);
    OperatorReadinessConfirmation::query()->create([
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subDays(31),
        'note' => 'Restore proof mentioned anon-focus-secret.',
    ]);

    foreach (range(1, 9) as $index) {
        AuditEvent::query()->create([
            'account_id' => $operator->account_id,
            'actor_type' => $operator->getMorphClass(),
            'actor_id' => $operator->id,
            'action' => 'operator_readiness.confirmed',
            'metadata' => [
                'key' => 'scheduler',
                'note' => 'Support code WF-OPFOCUS was checked.',
            ],
            'occurred_at' => now()->subMinutes($index),
        ]);
    }

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Operator focus')
        ->assertSee('Posture')
        ->assertSee('Needs attention')
        ->assertSee('Proof coverage')
        ->assertSee('1 current / 1 stale / 0 missing')
        ->assertSee('Safe activity')
        ->assertSee('9 total safe events')
        ->assertSee('Support data')
        ->assertSee('Hidden here')
        ->assertSee('Use this console to keep the installation healthy without opening customer support data.')
        ->assertSee('Retention posture')
        ->assertSee('Operator-owned retention')
        ->assertSee('Automatic retention controls are not enabled yet.')
        ->assertSee('Logs and backups')
        ->assertDontSee('WF-OPFOCUS')
        ->assertDontSee('Private operator focus request')
        ->assertDontSee('Private Focus Site')
        ->assertDontSee('anon-focus-secret')
        ->assertDontSee('Scheduler proof mentioned WF-OPFOCUS.')
        ->assertDontSee('Restore proof mentioned anon-focus-secret.')
        ->assertDontSee('Support code WF-OPFOCUS was checked.');
});

test('platform operators can confirm manual readiness items from the operator console', function (): void {
    $account = Account::factory()->create(['name' => 'Wayfindr Ops']);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);

    $this->actingAs($operator)
        ->post('/operator/readiness/confirmations', [
            'key' => 'backups_restore',
            'note' => 'Snapshot and database restore tested.',
        ])
        ->assertRedirect('/operator');

    $this->assertDatabaseHas('operator_readiness_confirmations', [
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'note' => 'Snapshot and database restore tested.',
    ]);

    $auditEvent = AuditEvent::query()
        ->where('action', 'operator_readiness.confirmed')
        ->firstOrFail();

    expect($auditEvent->account_id)
        ->toBe($account->id)
        ->and($auditEvent->actor_id)->toBe($operator->id)
        ->and($auditEvent->metadata)->toMatchArray([
            'key' => 'backups_restore',
            'note' => 'Snapshot and database restore tested.',
        ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Backups and restore confirmed.')
        ->assertSee('Confirmed by Olive Operator')
        ->assertSee('Evidence note recorded.')
        ->assertDontSee('Snapshot and database restore tested.');
});

test('platform operators cannot confirm unsupported readiness keys', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->post('/operator/readiness/confirmations', [
            'key' => 'database_connection',
            'note' => 'Trying to override an automatic check.',
        ])
        ->assertInvalid('key');

    $this->assertDatabaseMissing('operator_readiness_confirmations', [
        'key' => 'database_connection',
    ]);
});

test('operator console shows safe system identity and documentation links', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    config([
        'app.debug' => false,
        'broadcasting.default' => 'reverb',
        'queue.default' => 'redis',
        'wayfindr.documentation.forge_url' => 'https://example.test/docs/forge',
        'wayfindr.documentation.runtime_requirements_url' => 'https://example.test/docs/runtime',
        'wayfindr.documentation.self_hosting_url' => 'https://example.test/docs/self-hosting',
        'wayfindr.release.commit' => 'abc1234',
        'wayfindr.release.version' => '0.1.0',
    ]);

    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('System identity')
        ->assertSee('Wayfindr version')
        ->assertSee('0.1.0')
        ->assertSee('Source revision')
        ->assertSee('abc1234')
        ->assertSee('Environment')
        ->assertSeeInOrder(['Environment', 'production', 'Debug mode'])
        ->assertSee('Debug mode')
        ->assertSee('Disabled')
        ->assertSee('PHP version')
        ->assertSee(PHP_VERSION)
        ->assertSee('Laravel version')
        ->assertSee(LaravelApplication::VERSION)
        ->assertSee('Queue driver')
        ->assertSee('redis')
        ->assertSee('Broadcast driver')
        ->assertSee('reverb')
        ->assertSee('Self-hosting docs')
        ->assertSee('https://example.test/docs/self-hosting', false)
        ->assertSee('Runtime requirements')
        ->assertSee('https://example.test/docs/runtime', false)
        ->assertSee('Forge deploy guide')
        ->assertSee('https://example.test/docs/forge', false);
});

test('operator console summarizes readiness proof coverage without evidence notes or support data', function (): void {
    $this->travelTo(Carbon::parse('2026-06-22 12:00:00'));

    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);
    $site = Site::factory()->create(['name' => 'Private Proof Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-proof-secret',
    ]);
    Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PROOFSECRET',
        'subject' => 'Private visitor proof request',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'scheduler',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subDay(),
        'note' => 'Scheduler proof mentioned support code WF-PROOFSECRET.',
    ]);

    OperatorReadinessConfirmation::query()->create([
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subDays(31),
        'note' => 'Restore proof mentioned anon-proof-secret.',
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Readiness proof coverage')
        ->assertSee('Fresh proofs')
        ->assertSee('1 current')
        ->assertSee('Refresh due')
        ->assertSee('1 stale')
        ->assertSee('Missing proofs')
        ->assertSee('0 missing')
        ->assertSee('Scheduler')
        ->assertSee('Backups and restore')
        ->assertSee('Confirmed by Olive Operator 1 day ago')
        ->assertSee('Confirmed by Olive Operator 1 month ago')
        ->assertSee('Manual proof notes stay out of this summary.')
        ->assertDontSee('Scheduler proof mentioned support code WF-PROOFSECRET.')
        ->assertDontSee('Restore proof mentioned anon-proof-secret.')
        ->assertDontSee('WF-PROOFSECRET')
        ->assertDontSee('Private visitor proof request')
        ->assertDontSee('Private Proof Site')
        ->assertDontSee('anon-proof-secret');
});

test('operator console explains the platform support data boundary', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Boundary inventory')
        ->assertSee('Instance health')
        ->assertSee('Safe for operators')
        ->assertSee('Support data')
        ->assertSee('Not available here')
        ->assertSee('Break-glass access')
        ->assertSee('Future scoped workflow')
        ->assertSee('Conversations, tickets, cobrowse snapshots, transcripts, and visitor page data stay out of operator screens.')
        ->assertSee('Any future customer-data access must be explicit, time-bound, and audited.');
});

test('platform operator authority does not bypass dashboard support access', function (): void {
    $operator = User::factory()->for(Account::factory(['name' => 'Wayfindr Operators']))->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
    ]);
    $customerAccount = Account::factory()->create(['name' => 'Private Customer Account']);
    $site = Site::factory()->for($customerAccount)->create(['name' => 'Private Customer Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-private-operator-boundary',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-OPPRIVATE',
        'subject' => 'Private platform support request',
    ]);
    $ticket = Ticket::factory()->for($customerAccount)->for($site)->for($visitor, 'requester')->for($conversation)->create([
        'subject' => 'Private ticket details',
        'description' => 'Private ticket body that should stay behind support access.',
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Dashboard support routes remain account and site scoped.')
        ->assertSee('Platform operator access does not make someone a support agent for customer conversations, tickets, visitors, or sites.')
        ->assertDontSee('Private Customer Account')
        ->assertDontSee('Private Customer Site')
        ->assertDontSee('anon-private-operator-boundary')
        ->assertDontSee('WF-OPPRIVATE')
        ->assertDontSee('Private platform support request')
        ->assertDontSee('Private ticket details')
        ->assertDontSee('Private ticket body that should stay behind support access.');

    $this->actingAs($operator)
        ->get("/dashboard/conversations/{$conversation->support_code}")
        ->assertNotFound();

    $this->actingAs($operator)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertNotFound();

    $this->actingAs($operator)
        ->get("/dashboard/visitors/{$visitor->id}")
        ->assertNotFound();

    $this->actingAs($operator)
        ->get("/dashboard/sites/{$site->id}")
        ->assertNotFound();
});

test('operator console shows a platform action inventory without support data', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);
    $site = Site::factory()->create(['name' => 'Sensitive Action Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-action-boundary',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-ACTIONSECRET',
        'subject' => 'Private account audit request',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'snapshot' => [
                'title' => 'Private action page',
                'page_url' => 'https://customer.example.test/private-action',
                'html' => '<main>Private operator action detail</main>',
                'text' => 'Private operator action detail',
            ],
        ],
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Platform action inventory')
        ->assertSee('Current safe actions')
        ->assertSee('System identity and release checks')
        ->assertSee('Read-only')
        ->assertSee('Instance readiness confirmations')
        ->assertSee('Audited manual proof')
        ->assertSee('Future break-glass actions')
        ->assertSee('Not enabled')
        ->assertSee('Customer-data access requires explicit scope, expiry, approval, and audit before it exists.')
        ->assertDontSee('WF-ACTIONSECRET')
        ->assertDontSee('Private account audit request')
        ->assertDontSee('Sensitive Action Site')
        ->assertDontSee('anon-action-boundary')
        ->assertDontSee('Private action page')
        ->assertDontSee('customer.example.test')
        ->assertDontSee('Private operator action detail');
});

test('operator console shows recent safe operator activity', function (): void {
    $account = Account::factory()->create(['name' => 'Wayfindr Ops']);
    $operator = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Olive Operator',
    ]);
    $otherOperator = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Morgan Maintainer',
    ]);
    $confirmation = OperatorReadinessConfirmation::query()->create([
        'key' => 'backups_restore',
        'confirmed_by_id' => $otherOperator->id,
        'confirmed_at' => now()->subMinutes(5),
        'note' => 'Current restore proof is recorded.',
    ]);

    AuditEvent::query()->create([
        'account_id' => $otherOperator->account_id,
        'actor_type' => $otherOperator->getMorphClass(),
        'actor_id' => $otherOperator->id,
        'subject_type' => $confirmation->getMorphClass(),
        'subject_id' => $confirmation->id,
        'action' => 'operator_readiness.confirmed',
        'metadata' => [
            'key' => 'backups_restore',
            'note' => 'Ticket WF-SENSITIVE had visitor billing details in the note.',
        ],
        'occurred_at' => now()->subMinutes(5),
    ]);

    AuditEvent::factory()->create([
        'account_id' => $account->id,
        'actor_type' => $operator->getMorphClass(),
        'actor_id' => $operator->id,
        'action' => 'conversation.created',
        'metadata' => [
            'message' => 'Sensitive visitor transcript should stay out of the operator console.',
        ],
        'occurred_at' => now()->subMinute(),
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Recent operator activity')
        ->assertSee('1 safe event')
        ->assertSee('Morgan Maintainer')
        ->assertSee('Backups and restore confirmation')
        ->assertSee('Backups and restore readiness proof was recorded.')
        ->assertSee('Only safe instance-level operator actions are shown here.')
        ->assertDontSee('Ticket WF-SENSITIVE had visitor billing details in the note.')
        ->assertDontSee('Sensitive visitor transcript should stay out of the operator console.');
});

test('operator activity shows safe evidence details without rendering evidence notes', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'account_role' => AccountRole::Agent,
        'platform_role' => PlatformRole::Operator,
        'name' => 'Morgan Maintainer',
    ]);
    $confirmation = OperatorReadinessConfirmation::query()->create([
        'key' => 'backups_restore',
        'confirmed_by_id' => $operator->id,
        'confirmed_at' => now()->subMinutes(2),
        'note' => null,
    ]);

    AuditEvent::query()->create([
        'account_id' => $operator->account_id,
        'actor_type' => $operator->getMorphClass(),
        'actor_id' => $operator->id,
        'subject_type' => $confirmation->getMorphClass(),
        'subject_id' => $confirmation->id,
        'action' => 'operator_readiness.confirmed',
        'metadata' => [
            'key' => 'backups_restore',
            'note' => 'Support code WF-PRIVATE and visitor email person@example.test were reviewed.',
        ],
        'occurred_at' => now()->subMinutes(2),
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Safe evidence details')
        ->assertSee('Readiness item')
        ->assertSee('Backups and restore')
        ->assertSee('Evidence note')
        ->assertSee('Evidence note recorded')
        ->assertSee('Event type')
        ->assertSee('Readiness confirmation')
        ->assertDontSee('Support code WF-PRIVATE and visitor email person@example.test were reviewed.');
});

test('operator console shows aggregate cobrowse transport readiness without support data', function (): void {
    $this->travelTo(Carbon::parse('2026-06-20 12:00:00'));

    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);
    $site = Site::factory()->create(['name' => 'Sensitive Customer Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-operator-cobrowse',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PRIVATE',
        'subject' => 'Checkout account number is wrong',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'telemetry' => [
                'reported_at' => now()->toJSON(),
                'dropped_batches' => 2,
                'reconnects' => 0,
            ],
            'snapshot' => [
                'reported_at' => now()->toJSON(),
                'title' => 'Private checkout',
                'page_url' => 'https://customer.example.test/account',
                'html' => '<main>Hidden account number</main>',
                'text' => 'Hidden account number',
            ],
        ],
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Cobrowse transport')
        ->assertSee('1 active cobrowse session needs transport attention.')
        ->assertSee('1 degraded')
        ->assertDontSee('WF-PRIVATE')
        ->assertDontSee('Checkout account number is wrong')
        ->assertDontSee('Sensitive Customer Site')
        ->assertDontSee('anon-operator-cobrowse')
        ->assertDontSee('Private checkout')
        ->assertDontSee('customer.example.test')
        ->assertDontSee('Hidden account number');
});

test('operator console shows cobrowse budget defaults without support data', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);
    $site = Site::factory()->create(['name' => 'Sensitive Budget Site']);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-budget-operator',
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-BUDGETSECRET',
        'subject' => 'Private payment form is changing quickly',
    ]);

    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'metadata' => [
            'snapshot' => [
                'title' => 'Private payment page',
                'page_url' => 'https://customer.example.test/private-payment',
                'html' => '<main>Cardholder details</main>',
                'text' => 'Cardholder details',
            ],
        ],
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Cobrowse budget defaults')
        ->assertSee('Safe default limits for stock widget payloads and server intake.')
        ->assertSee('Snapshot HTML')
        ->assertSee('65,535 characters')
        ->assertSee('Server mutation batch')
        ->assertSee('50 items')
        ->assertSee('Server telemetry payload')
        ->assertSee('10,485,760 bytes')
        ->assertSee('Stock widget batch payload')
        ->assertSee('60,000 bytes')
        ->assertSee('Stock widget queue')
        ->assertSee('250 pending')
        ->assertSee('Mutation flush')
        ->assertSee('50 ms')
        ->assertSee('Pressure resync')
        ->assertSee('30,000 ms')
        ->assertSee('Status poll')
        ->assertSee('5,000 ms')
        ->assertSee('Resync attempts')
        ->assertSee('3 attempts')
        ->assertDontSee('WF-BUDGETSECRET')
        ->assertDontSee('Private payment form is changing quickly')
        ->assertDontSee('Sensitive Budget Site')
        ->assertDontSee('anon-budget-operator')
        ->assertDontSee('Private payment page')
        ->assertDontSee('customer.example.test')
        ->assertDontSee('Cardholder details');
});

test('operator console shows an empty operator activity state', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertOk()
        ->assertSee('Recent operator activity')
        ->assertSee('No operator activity yet.');
});

test('deactivated platform operators cannot inspect the operator console', function (): void {
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
        'deactivated_at' => now(),
    ]);

    $this->actingAs($operator)
        ->get('/operator')
        ->assertRedirect('/login');
});
