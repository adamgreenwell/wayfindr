<?php

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admins can search filtered account audit activity without leaking restricted site events', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Olive Owner',
    ]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'name' => 'Bea Builder',
        'email' => 'bea@example.test',
    ]);
    $visibleSite = Site::factory()->for($account)->create(['name' => 'VIP Portal']);
    $visibleSite->supportAgents()->attach($admin);
    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($owner);
    $otherAccount = Account::factory()->create(['name' => 'Other Support']);
    $outsideAgent = User::factory()->for($otherAccount)->create(['name' => 'Mallory Elsewhere']);

    AuditEvent::factory()->for($account)->for($visibleSite)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $visibleSite->getMorphClass(),
        'subject_id' => $visibleSite->id,
        'action' => 'site_access.updated',
        'metadata' => ['token' => 'should-not-render'],
        'occurred_at' => now()->subMinutes(3),
    ]);

    AuditEvent::factory()->for($account)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $agent->getMorphClass(),
        'subject_id' => $agent->id,
        'action' => 'agent.created',
        'metadata' => ['temporary_password' => 'should-not-render'],
        'occurred_at' => now()->subMinutes(2),
    ]);

    AuditEvent::factory()->for($account)->for($restrictedSite)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $restrictedSite->getMorphClass(),
        'subject_id' => $restrictedSite->id,
        'action' => 'site_access.updated',
        'metadata' => [],
        'occurred_at' => now()->subMinute(),
    ]);

    AuditEvent::factory()->for($otherAccount)->create([
        'actor_type' => $outsideAgent->getMorphClass(),
        'actor_id' => $outsideAgent->id,
        'subject_type' => $outsideAgent->getMorphClass(),
        'subject_id' => $outsideAgent->id,
        'action' => 'agent.created',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/audit?audit_action=site_access.updated&audit_search=VIP')
        ->assertOk()
        ->assertSee('Account audit')
        ->assertSee('1 shown')
        ->assertSee('Site access updated')
        ->assertSee('VIP Portal')
        ->assertSee('Olive Owner')
        ->assertSee('Export CSV')
        ->assertDontSee('Bea Builder')
        ->assertDontSee('Restricted Store')
        ->assertDontSee('Other Support')
        ->assertDontSee('Mallory Elsewhere')
        ->assertDontSee('should-not-render');
});

test('regular agents cannot view or export account audit activity', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
    ]);

    $this->actingAs($agent)
        ->get('/dashboard/account/audit')
        ->assertForbidden();

    $this->actingAs($agent)
        ->get('/dashboard/account/audit/export')
        ->assertForbidden();
});

test('account audit export includes scoped safe fields without raw metadata', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $owner = User::factory()->for($account)->create([
        'account_role' => AccountRole::Owner,
        'name' => 'Olive Owner',
    ]);
    $visibleSite = Site::factory()->for($account)->create(['name' => 'VIP Portal']);
    $visibleSite->supportAgents()->attach($admin);
    $restrictedSite = Site::factory()->for($account)->create(['name' => 'Restricted Store']);
    $restrictedSite->supportAgents()->attach($owner);

    AuditEvent::factory()->for($account)->for($visibleSite)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $visibleSite->getMorphClass(),
        'subject_id' => $visibleSite->id,
        'action' => 'site_access.updated',
        'metadata' => ['token' => 'should-not-render'],
        'occurred_at' => now()->subMinute(),
    ]);

    AuditEvent::factory()->for($account)->for($restrictedSite)->create([
        'actor_type' => $owner->getMorphClass(),
        'actor_id' => $owner->id,
        'subject_type' => $restrictedSite->getMorphClass(),
        'subject_id' => $restrictedSite->id,
        'action' => 'site_access.updated',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    $response = $this->actingAs($admin)
        ->get('/dashboard/account/audit/export?audit_action=site_access.updated');

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)
        ->toContain('occurred_at,action,label,actor,subject,site')
        ->toContain('site_access.updated')
        ->toContain('Site access updated')
        ->toContain('Olive Owner')
        ->toContain('VIP Portal')
        ->not->toContain('Restricted Store')
        ->not->toContain('should-not-render');
});

test('account audit export can be scoped to an exact visible site', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
        'name' => 'Ada Admin',
    ]);
    $targetSite = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $similarSite = Site::factory()->for($account)->create(['name' => 'Acme Docs Canada']);
    $targetSite->supportAgents()->attach($admin);
    $similarSite->supportAgents()->attach($admin);

    AuditEvent::factory()->for($account)->for($targetSite)->create([
        'actor_type' => $admin->getMorphClass(),
        'actor_id' => $admin->id,
        'subject_type' => $targetSite->getMorphClass(),
        'subject_id' => $targetSite->id,
        'action' => 'site_access.updated',
        'metadata' => [],
        'occurred_at' => now()->subMinute(),
    ]);

    AuditEvent::factory()->for($account)->for($similarSite)->create([
        'actor_type' => $admin->getMorphClass(),
        'actor_id' => $admin->id,
        'subject_type' => $similarSite->getMorphClass(),
        'subject_id' => $similarSite->id,
        'action' => 'site_access.updated',
        'metadata' => [],
        'occurred_at' => now(),
    ]);

    $content = $this->actingAs($admin)
        ->get('/dashboard/account/audit/export?audit_action=site_access.updated&audit_site='.$targetSite->id)
        ->streamedContent();

    expect($content)
        ->toContain('Acme Docs')
        ->not->toContain('Acme Docs Canada');
});

test('account audit export neutralizes spreadsheet formula values', function (): void {
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $admin = User::factory()->for($account)->create([
        'account_role' => AccountRole::Admin,
    ]);
    $actor = User::factory()->for($account)->create(['name' => '=HYPERLINK("https://bad.test","click")']);
    $subject = User::factory()->for($account)->create(['name' => '+SUM(1,1)']);
    $site = Site::factory()->for($account)->create(['name' => '@Bad Site']);
    $site->supportAgents()->attach($admin);

    AuditEvent::factory()->for($account)->for($site)->create([
        'actor_type' => $actor->getMorphClass(),
        'actor_id' => $actor->id,
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->id,
        'action' => 'agent.created',
        'metadata' => [],
    ]);

    $content = $this->actingAs($admin)
        ->get('/dashboard/account/audit/export')
        ->streamedContent();

    $rows = collect(explode("\n", trim($content)))
        ->map(fn (string $row): array => str_getcsv($row))
        ->all();

    expect($rows[1][3])->toBe('\'=HYPERLINK("https://bad.test","click")')
        ->and($rows[1][4])->toBe('\'+SUM(1,1)')
        ->and($rows[1][5])->toBe('\'@Bad Site');
});

test('admins can find cobrowse audit activity by support code without exposing metadata', function (): void {
    $account = Account::factory()->create();
    $admin = User::factory()->for($account)->create(['account_role' => AccountRole::Admin]);
    $site = Site::factory()->for($account)->create(['name' => 'Docs Site']);
    $site->supportAgents()->attach($admin);
    $visitor = Visitor::factory()->for($site)->create([
        'anonymous_id' => 'anon-cobrowse',
        'name' => null,
        'email' => null,
    ]);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-AUDITUI',
    ]);
    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
    ]);

    $session->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => Visitor::class,
        'actor_id' => $visitor->id,
        'action' => 'cobrowse.resync_fulfilled',
        'metadata' => [
            'support_code' => 'WF-AUDITUI',
            'request_id' => 'resync_visible',
            'html' => '<main>Do not render me.</main>',
            'page_url' => 'https://docs.example.test/private',
        ],
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get('/dashboard/account/audit?audit_search=WF-AUDITUI')
        ->assertOk()
        ->assertSee('Cobrowse Resync Fulfilled')
        ->assertSee('Visitor anon-cobrowse')
        ->assertSee('Cobrowse WF-AUDITUI')
        ->assertSee('Docs Site')
        ->assertDontSee('Do not render me')
        ->assertDontSee('https://docs.example.test/private');
});
