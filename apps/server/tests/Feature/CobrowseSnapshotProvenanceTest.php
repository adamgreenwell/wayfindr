<?php

// Snapshot provenance + masking-ruleset proof (#524, second slice).
//
// Every reported snapshot leaves an immutable cobrowse.snapshot_received audit
// event recording when it was captured, how much was masked, and which site
// masking ruleset (selectors + terms) was in force at capture time — so
// masking is provable later even after the site's rules change. Metadata is
// provenance only, never snapshot content.

use App\Models\AuditEvent;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function snapshotProvenanceFixture(array $siteSettings = []): array
{
    $site = Site::factory()->create([
        'public_key' => 'site_public_docs',
        'settings' => $siteSettings,
    ]);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-prov']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-PROV',
    ]);
    CobrowseSession::factory()->for($conversation)->for($site)->for($visitor)->create([
        'status' => 'granted',
        'consented_at' => now()->subMinute(),
        'ended_at' => null,
        'metadata' => [],
    ]);

    return compact('site', 'visitor', 'conversation');
}

function reportProvenanceSnapshot($test, Conversation $conversation): void
{
    $token = $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-prov',
        'page_url' => 'https://docs.example.test/install',
    ])->assertSuccessful()->json('data.visitor.token');

    $test->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-prov',
        'visitor_token' => $token,
        'page_url' => 'https://docs.example.test/install?step=2',
        'title' => 'Install Guide',
        'html' => '<main><h1>Install Guide</h1><p>Secret-adjacent visitor copy.</p></main>',
        'text' => 'Install Guide Secret-adjacent visitor copy.',
        'node_count' => 3,
        'masked_count' => 2,
        'mutation_sequence' => 7,
    ])->assertOk();
}

test('reporting a snapshot records its provenance and the masking ruleset in force', function (): void {
    $selectors = ['.checkout-card', '[data-order-secret]'];
    $terms = ['contraseña', 'NHS number'];
    $fixture = snapshotProvenanceFixture([
        'mask_selectors' => $selectors,
        'mask_terms' => $terms,
    ]);

    reportProvenanceSnapshot($this, $fixture['conversation']);

    $event = AuditEvent::query()->where('action', 'cobrowse.snapshot_received')->firstOrFail();

    expect($event->actor_type)->toBe(Visitor::class)
        ->and($event->actor_id)->toBe($fixture['visitor']->id)
        ->and($event->metadata)->toMatchArray([
            'support_code' => 'WF-PROV',
            'page_url' => 'https://docs.example.test/install?step=2',
            'node_count' => 3,
            'masked_count' => 2,
            'mutation_sequence' => 7,
        ])
        ->and($event->metadata['reported_at'])->not->toBeNull()
        ->and($event->metadata['html_length'])->toBeGreaterThan(0)
        ->and($event->metadata['masking_ruleset'])->toMatchArray([
            'hash' => hash('sha256', (string) json_encode([$selectors, $terms])),
            'site_mask_selectors' => $selectors,
            'site_mask_selector_count' => 2,
            'site_sensitive_terms' => $terms,
            'site_sensitive_term_count' => 2,
            'truncated' => false,
        ]);
});

test('snapshot provenance metadata never contains snapshot content', function (): void {
    $fixture = snapshotProvenanceFixture();

    reportProvenanceSnapshot($this, $fixture['conversation']);

    $event = AuditEvent::query()->where('action', 'cobrowse.snapshot_received')->firstOrFail();

    expect(json_encode($event->metadata))
        ->not->toContain('Install Guide')
        ->not->toContain('Secret-adjacent')
        ->not->toContain('<main');
});

test('oversized masking rulesets are bounded with a truncation marker', function (): void {
    $selectors = array_map(fn (int $i): string => ".sensitive-region-{$i}", range(1, 60));
    $fixture = snapshotProvenanceFixture(['mask_selectors' => $selectors]);

    reportProvenanceSnapshot($this, $fixture['conversation']);

    $ruleset = AuditEvent::query()
        ->where('action', 'cobrowse.snapshot_received')
        ->firstOrFail()
        ->metadata['masking_ruleset'];

    expect($ruleset['site_mask_selectors'])->toHaveCount(50)
        ->and($ruleset['site_mask_selector_count'])->toBe(60)
        ->and($ruleset['truncated'])->toBeTrue()
        // The hash still pins the full, untruncated ruleset.
        ->and($ruleset['hash'])->toBe(hash('sha256', (string) json_encode([$selectors, []])));
});
