<?php

// Snapshot provenance + masking-ruleset proof (#524, second slice).
//
// Every reported snapshot leaves an immutable cobrowse.snapshot_received audit
// event recording when it was captured, how much was masked, and which masking
// ruleset was actually in force at capture time. The widget masks with the
// ruleset it cached at bootstrap, so provenance records what the widget
// reports it applied — not the site's current settings, which an admin may
// have edited mid-session — and flags any divergence. Metadata is provenance
// only, never snapshot content.

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

function reportProvenanceSnapshot($test, Conversation $conversation, array $extra = []): void
{
    $token = $test->postJson('/api/widget/bootstrap', [
        'site_public_key' => 'site_public_docs',
        'anonymous_id' => 'anon-prov',
        'page_url' => 'https://docs.example.test/install',
    ])->assertSuccessful()->json('data.visitor.token');

    $test->postJson("/api/conversations/{$conversation->support_code}/cobrowse-snapshot", array_merge([
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
    ], $extra))->assertOk();
}

function provenanceEvent(): AuditEvent
{
    return AuditEvent::query()->where('action', 'cobrowse.snapshot_received')->firstOrFail();
}

test('provenance records the ruleset the widget reports it actually masked with', function (): void {
    // The widget bootstrapped before an admin edited the site rules, so the
    // ruleset it applied differs from the site's current settings — exactly
    // the case the proof exists for.
    $bootstrapSelectors = ['.checkout-card'];
    $bootstrapTerms = ['contraseña'];
    $fixture = snapshotProvenanceFixture([
        'mask_selectors' => ['.checkout-card', '.newly-added-region'],
        'mask_terms' => ['contraseña', 'NHS number'],
    ]);

    reportProvenanceSnapshot($this, $fixture['conversation'], [
        'mask_selectors' => $bootstrapSelectors,
        'sensitive_terms' => $bootstrapTerms,
    ]);

    $event = provenanceEvent();

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
        ->and($event->metadata['masking_ruleset'])->toMatchArray([
            'source' => 'widget_reported',
            'hash' => hash('sha256', (string) json_encode([$bootstrapSelectors, $bootstrapTerms])),
            'mask_selectors' => $bootstrapSelectors,
            'mask_selector_count' => 1,
            'sensitive_terms' => $bootstrapTerms,
            'sensitive_term_count' => 1,
            'truncated' => false,
            'matches_site_settings' => false,
        ]);
});

test('provenance confirms when the applied ruleset matches current site settings', function (): void {
    $selectors = ['.checkout-card'];
    $terms = ['NHS number'];
    $fixture = snapshotProvenanceFixture([
        'mask_selectors' => $selectors,
        'mask_terms' => $terms,
    ]);

    reportProvenanceSnapshot($this, $fixture['conversation'], [
        'mask_selectors' => $selectors,
        'sensitive_terms' => $terms,
    ]);

    expect(provenanceEvent()->metadata['masking_ruleset'])->toMatchArray([
        'source' => 'widget_reported',
        'matches_site_settings' => true,
    ]);
});

test('older widgets fall back to the site settings at receipt time', function (): void {
    $selectors = ['.legacy-region'];
    $fixture = snapshotProvenanceFixture(['mask_selectors' => $selectors]);

    reportProvenanceSnapshot($this, $fixture['conversation']);

    expect(provenanceEvent()->metadata['masking_ruleset'])->toMatchArray([
        'source' => 'site_settings_at_receipt',
        'mask_selectors' => $selectors,
        'matches_site_settings' => true,
    ]);
});

test('snapshot provenance metadata never contains snapshot content', function (): void {
    $fixture = snapshotProvenanceFixture();

    reportProvenanceSnapshot($this, $fixture['conversation'], [
        'mask_selectors' => ['.card'],
        'sensitive_terms' => [],
    ]);

    expect(json_encode(provenanceEvent()->metadata))
        ->not->toContain('Install Guide')
        ->not->toContain('Secret-adjacent')
        ->not->toContain('<main');
});

test('oversized masking rulesets are bounded with a truncation marker', function (): void {
    $selectors = array_map(fn (int $i): string => ".sensitive-region-{$i}", range(1, 60));
    $fixture = snapshotProvenanceFixture();

    reportProvenanceSnapshot($this, $fixture['conversation'], [
        'mask_selectors' => $selectors,
        'sensitive_terms' => [],
    ]);

    $ruleset = provenanceEvent()->metadata['masking_ruleset'];

    expect($ruleset['mask_selectors'])->toHaveCount(50)
        ->and($ruleset['mask_selector_count'])->toBe(60)
        ->and($ruleset['truncated'])->toBeTrue()
        // The hash still pins the full, untruncated ruleset.
        ->and($ruleset['hash'])->toBe(hash('sha256', (string) json_encode([$selectors, []])));
});
