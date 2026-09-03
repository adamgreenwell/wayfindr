<?php

use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\User;
use App\Support\OperatorReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Hidden panels still render their markup, so assertSee cannot tell which tab
 * a section landed on -- it passes whether the grouping is right or entirely
 * scrambled. These tests read one panel's markup at a time.
 *
 * @return string the inner markup of a single tab panel
 */
function operatorConsolePanel(string $html, string $panelId): string
{
    $start = strpos($html, 'data-tab-panel="'.$panelId.'"');

    expect($start)->not->toBeFalse();

    // Panels are siblings, so the next panel (or the tablist close) bounds this one.
    $next = strpos($html, 'data-tab-panel="', $start + 1);

    return $next === false
        ? substr($html, $start)
        : substr($html, $start, $next - $start);
}

function operatorConsoleHtml($test): string
{
    $operator = User::factory()->for(Account::factory())->create([
        'platform_role' => PlatformRole::Operator,
    ]);

    return $test->actingAs($operator)->get('/operator')->assertOk()->getContent();
}

test('the console groups sixteen sections into five named tabs', function (): void {
    $html = operatorConsoleHtml($this);

    $tablist = substr($html, strpos($html, 'role="tablist"'), 3000);

    foreach (['Overview', 'Health', 'Go live', 'Data', 'Access'] as $label) {
        expect($tablist)->toContain($label);
    }

    foreach (['overview', 'health', 'golive', 'data', 'access'] as $panel) {
        expect($html)->toContain('data-tab-panel="'.$panel.'"');
    }

    // Overview answers "what is going on"; it opens by default so the summary
    // and the single recommended action are what an operator lands on.
    expect($html)->toContain('id="tab-panel-overview"');
});

test('each section sits on the tab that answers its question', function (): void {
    $html = operatorConsoleHtml($this);

    $expected = [
        'overview' => ['Operator focus', 'Recommended next step', 'System identity'],
        'health' => ['Instance readiness', 'Checks', 'Realtime'],
        'golive' => ['Before real support traffic', 'Prove the install works', 'What you have confirmed'],
        'data' => ['How long data is kept', 'What an operator can see', 'What an operator can do'],
        'access' => ['Operator access', 'Recent operator activity'],
    ];

    foreach ($expected as $panelId => $headings) {
        $panel = operatorConsolePanel($html, $panelId);

        // Match the heading element, not the words: "System identity" also
        // appears as prose inside another panel's copy, and a bare-text match
        // would report that as a grouping error.
        foreach ($headings as $heading) {
            expect($panel)->toContain($heading.'</h2>');
        }

        // Without this the whole test would pass even if operatorConsolePanel()
        // returned the entire document: every heading is somewhere on the page.
        // Each panel must also NOT hold another panel's headings.
        foreach ($expected as $otherPanel => $otherHeadings) {
            if ($otherPanel === $panelId) {
                continue;
            }

            foreach ($otherHeadings as $otherHeading) {
                expect($panel)->not->toContain($otherHeading.'</h2>');
            }
        }
    }
});

test('only the first panel is visible on arrival', function (): void {
    $html = operatorConsoleHtml($this);

    expect(operatorConsolePanel($html, 'overview'))->not->toContain('hidden');

    foreach (['health', 'golive', 'data', 'access'] as $panelId) {
        // A panel that is not hidden means the page never actually collapsed.
        expect(operatorConsolePanel($html, $panelId))->toContain('hidden');
    }
});

test('confirmation labels target unique inputs across every rendered tab', function (): void {
    $html = operatorConsoleHtml($this);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($document);
    $inputs = $xpath->query('//input[@name="note"]');
    $ids = [];

    expect($inputs->length)->toBeGreaterThan(1);

    foreach ($inputs as $input) {
        expect($input)->toBeInstanceOf(DOMElement::class);
        $id = $input->getAttribute('id');
        $ids[] = $id;

        expect($id)->not->toBe('')
            ->and($xpath->query('//label[@for="'.$id.'"]')->length)->toBe(1);
    }

    expect(array_unique($ids))->toHaveCount(count($ids));
});

test('tabs badge problems and stay silent about pending work', function (): void {
    // The scheduler check is permanently 'manual' and several go-live gates
    // wait on a person. Badging those would put a number on two tabs forever
    // and train operators to ignore every badge, which is the failure mode
    // amber pills on resting states already caused once (ADR 0014).
    $html = operatorConsoleHtml($this);

    $tablist = substr($html, strpos($html, 'role="tablist"'), 2000);

    expect($tablist)->toContain('tabs__badge');

    // Data and Access report no failure state, so neither carries a badge.
    $dataTab = substr($tablist, strpos($tablist, 'data-tab="data"'), 200);
    expect($dataTab)->not->toContain('tabs__badge');

    $accessTab = substr($tablist, strpos($tablist, 'data-tab="access"'), 200);
    expect($accessTab)->not->toContain('tabs__badge');
});

test('a retention failure badges Data, not Health', function (): void {
    // attention_count folds a retention failure into the install-wide total,
    // but the checks live on Health and the retention summary lives on Data.
    // Badging the total on Health would send an operator to a panel of green
    // checks while the tab holding the actual failure showed nothing.
    config([
        'wayfindr.retention' => [
            'status' => 'attention',
            'summary' => 'Retention review is overdue for this install.',
            'description' => 'The operator flagged retention as needing attention before traffic.',
        ],
    ]);

    $readiness = app(OperatorReadiness::class)->summary();

    expect($readiness['retention_needs_attention'])->toBeTrue()
        ->and($readiness['attention_count'])->toBe($readiness['check_attention_count'] + 1);

    $html = operatorConsoleHtml($this);
    $tablist = substr($html, strpos($html, 'role="tablist"'), 3000);

    $dataTab = substr($tablist, strpos($tablist, 'data-tab="data"'), 220);
    expect($dataTab)->toContain('tabs__badge');

    // Health may still badge its own failing checks -- it must never inherit
    // the retention one.
    $healthTab = substr($tablist, strpos($tablist, 'data-tab="health"'), 220);
    preg_match('/tabs__badge">(\d+)</', $healthTab, $m);
    $healthBadge = isset($m[1]) ? (int) $m[1] : 0;

    expect($healthBadge)->toBe($readiness['check_attention_count']);
});

test('the next step card never prints the same sentence twice', function (): void {
    readinessLanguageAndRegionConfirmed();

    // Smoke steps carry one sentence. The card renders summary then detail, so
    // a fabricated detail showed the identical line twice -- visible on stage
    // the moment a smoke step became the next step.
    // With nothing failing, the next step falls through to the smoke path --
    // which is the only branch that had the bug. Without this the assertion
    // passes trivially against a check() item that has a real detail.
    config([
        'app.url' => 'https://support.example.test',
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.app_id' => 'wayfindr-production',
        'broadcasting.connections.reverb.key' => 'wayfindr-key',
        'broadcasting.connections.reverb.secret' => 'wayfindr-secret',
        'broadcasting.connections.reverb.options.host' => 'support.example.test',
        'broadcasting.connections.reverb.options.port' => 443,
        'broadcasting.connections.reverb.options.scheme' => 'https',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.from.address' => 'support@example.test',
        'queue.default' => 'database',
        'wayfindr.retention' => ['status' => 'ready'],
    ]);

    $readiness = app(OperatorReadiness::class)->summary();
    $next = $readiness['next_step'];

    expect($next['status'])->toBe('manual')
        ->and($next['detail'])->not->toBe($next['summary']);

    $html = operatorConsoleHtml($this);
    $panel = operatorConsolePanel($html, 'overview');
    $card = substr($panel, strpos($panel, 'Recommended next step'));

    expect(substr_count($card, e($next['summary'])))->toBe(1);
});
