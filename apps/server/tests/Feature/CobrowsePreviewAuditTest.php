<?php

// Preview access audit trail (#524, first slice).
//
// When an agent actually sees a rendered cobrowse replay preview — on the
// conversation page or through the live refresh endpoint — Wayfindr records a
// cobrowse.preview_viewed audit event so "who watched the visitor's screen,
// and when" is provable. Events are throttled per agent + session so the live
// auto-refresh loop cannot flood audit_events, and metadata carries provenance
// only, never preview content.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function previewAuditFixture(bool $withSnapshot = true): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-acme']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-AUDITME',
        'subject' => 'Checkout trouble',
        'status' => 'open',
    ]);

    $session = CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => $withSnapshot ? 'granted' : 'requested',
        'consented_at' => $withSnapshot ? now()->subMinute() : null,
        'ended_at' => null,
        'metadata' => $withSnapshot ? [
            'snapshot' => [
                'page_url' => 'https://docs.example.test/install',
                'title' => 'Install Guide',
                'html' => '<main><h1>Install Guide</h1><p>Private-looking install copy.</p></main>',
                'text' => 'Install Guide.',
                'node_count' => 3,
                'masked_count' => 0,
                'reported_at' => '2026-06-30T12:00:00.000000Z',
            ],
        ] : [],
    ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation', 'session');
}

function previewViewedEvents(): Collection
{
    return AuditEvent::query()->where('action', 'cobrowse.preview_viewed')->get();
}

test('viewing a conversation with a rendered preview records an audited page view', function (): void {
    $fixture = previewAuditFixture();

    $this->actingAs($fixture['agent'])
        ->get('/dashboard/conversations/WF-AUDITME')
        ->assertOk();

    $events = previewViewedEvents();

    expect($events)->toHaveCount(1)
        ->and($events->first()->actor_id)->toBe($fixture['agent']->id)
        ->and($events->first()->metadata)->toMatchArray([
            'support_code' => 'WF-AUDITME',
            'trigger' => 'page_view',
            'snapshot_reported_at' => '2026-06-30T12:00:00.000000Z',
            'applied_mutations' => '0 applied',
            'skipped_mutations' => '0 skipped',
            'drift_state' => 'steady',
        ]);
});

test('the live preview endpoint records an audited view without flooding on refresh', function (): void {
    $fixture = previewAuditFixture();

    $this->actingAs($fixture['agent'])
        ->getJson('/dashboard/conversations/WF-AUDITME/cobrowse/preview')
        ->assertOk();

    // The auto-refresh loop can fire on every mutation batch; repeated fetches
    // inside the throttle window must not create duplicate audit events.
    $this->actingAs($fixture['agent'])
        ->getJson('/dashboard/conversations/WF-AUDITME/cobrowse/preview')
        ->assertOk();
    $this->actingAs($fixture['agent'])
        ->get('/dashboard/conversations/WF-AUDITME')
        ->assertOk();

    expect(previewViewedEvents())->toHaveCount(1)
        ->and(previewViewedEvents()->first()->metadata['trigger'])->toBe('live_refresh');

    // After the throttle window, a fresh view is recorded again.
    $this->travel(16)->minutes();

    $this->actingAs($fixture['agent'])
        ->getJson('/dashboard/conversations/WF-AUDITME/cobrowse/preview')
        ->assertOk();

    expect(previewViewedEvents())->toHaveCount(2);
});

test('no preview view is recorded when there is no rendered preview', function (): void {
    $fixture = previewAuditFixture(withSnapshot: false);

    $this->actingAs($fixture['agent'])
        ->get('/dashboard/conversations/WF-AUDITME')
        ->assertOk();
    $this->actingAs($fixture['agent'])
        ->getJson('/dashboard/conversations/WF-AUDITME/cobrowse/preview')
        ->assertOk();

    expect(previewViewedEvents())->toHaveCount(0);
});

test('the throttle window is claimed atomically so racing requests cannot double-record', function (): void {
    $fixture = previewAuditFixture();

    $this->actingAs($fixture['agent'])
        ->getJson('/dashboard/conversations/WF-AUDITME/cobrowse/preview')
        ->assertOk();

    expect(previewViewedEvents())->toHaveCount(1);

    // Simulate a concurrent racer: it passed the exists() check before the
    // first request's row was visible. Deleting the row reproduces that state —
    // the database check now passes, so only the atomic cache claim stands
    // between the racer and a duplicate event.
    AuditEvent::query()->where('action', 'cobrowse.preview_viewed')->delete();

    $this->actingAs($fixture['agent'])
        ->getJson('/dashboard/conversations/WF-AUDITME/cobrowse/preview')
        ->assertOk();

    expect(previewViewedEvents())->toHaveCount(0);
});

test('preview view audit metadata never contains preview content', function (): void {
    $fixture = previewAuditFixture();

    $this->actingAs($fixture['agent'])
        ->get('/dashboard/conversations/WF-AUDITME')
        ->assertOk();

    $event = previewViewedEvents()->firstOrFail();

    expect(json_encode($event->metadata))
        ->not->toContain('Install Guide')
        ->not->toContain('Private-looking install copy')
        ->not->toContain('<');
});
