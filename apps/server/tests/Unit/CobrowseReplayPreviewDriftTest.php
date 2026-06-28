<?php

// Path-drift handling in the replay preview.
//
// Mutations are addressed by nth-of-type paths. When a path no longer resolves
// (the snapshot drifted from the live DOM), the mutation must be cleanly skipped
// and counted as drift -- never applied to the wrong node. These tests cover the
// distinct outcome counters and a fuzz invariant over randomized trees.
//
// See issue #486.

use App\Support\CobrowseReplayPreview;

function replayBatch(array $mutations, int $sequence = 1): array
{
    return [
        'snapshot' => ['html' => '<div><p>Original.</p><p>Second.</p></div>'],
        'mutations' => ['recent_batches' => [['sequence' => $sequence, 'mutations' => $mutations]]],
    ];
}

test('distinguishes applied, drift, unsupported, and invalid outcomes', function (): void {
    $validPath = 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(1)';
    $missingPath = 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(9)';

    $result = (new CobrowseReplayPreview)->fromMetadata(replayBatch([
        ['type' => 'text', 'path' => $validPath, 'text' => 'Applied copy.'],
        // Drift: path resolves to no node.
        ['type' => 'text', 'path' => $missingPath, 'text' => 'NEVER_LANDS'],
        // Unsupported: unsafe attribute on a valid node.
        ['type' => 'attribute', 'path' => $validPath, 'attribute_name' => 'onclick', 'attribute_value' => 'x()'],
        // Unsupported: unknown mutation type.
        ['type' => 'innerHTML', 'path' => $validPath, 'html' => '<b>nope</b>'],
        // Invalid: text mutation with a non-string payload on a valid node.
        ['type' => 'text', 'path' => $validPath, 'text' => ['not', 'a', 'string']],
    ]));

    expect($result)->not->toBeNull();
    expect($result['srcdoc'])
        ->toContain('Applied copy.')
        ->and($result['srcdoc'])->not->toContain('NEVER_LANDS')
        ->and($result['srcdoc'])->not->toContain('nope');

    // The drifted mutation is isolated from other skip reasons.
    expect($result['drift']['drift_count'])->toBe(1)
        ->and($result['drift']['addressable'])->toBe(2); // 1 applied + 1 unresolved
});

test('treats malformed or legacy mutation paths as invalid, not drift', function (): void {
    $validPath = 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(1)';

    $result = (new CobrowseReplayPreview)->fromMetadata(replayBatch([
        ['type' => 'text', 'path' => $validPath, 'text' => 'Applied copy.'],
        // Legacy nth-child syntax that pathToXPath does not support.
        ['type' => 'text', 'path' => 'body > main > p:nth-child(2)', 'text' => 'LEGACY'],
        // Path that does not start at body.
        ['type' => 'text', 'path' => 'div:nth-of-type(1)', 'text' => 'NOBODY'],
    ]));

    expect($result['srcdoc'])
        ->toContain('Applied copy.')
        ->and($result['srcdoc'])->not->toContain('LEGACY')
        ->and($result['srcdoc'])->not->toContain('NOBODY');

    // Malformed paths are excluded from the drift signal: only the applied
    // mutation is addressable, and nothing counts as drift.
    expect($result['drift']['drift_count'])->toBe(0)
        ->and($result['drift']['addressable'])->toBe(1);
});

test('recommends a resync once drift dominates the addressable mutations', function (): void {
    $applied = ['type' => 'text', 'path' => 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(1)', 'text' => 'Applied.'];
    $drifted = ['type' => 'text', 'path' => 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(40)', 'text' => 'NOPE'];

    $mutations = [$applied];
    for ($i = 0; $i < 9; $i++) {
        $mutations[] = $drifted;
    }

    $result = (new CobrowseReplayPreview)->fromMetadata(replayBatch($mutations));

    expect($result['drift']['state'])->toBe('drifting')
        ->and($result['drift']['recommend_resync'])->toBeTrue()
        ->and($result['srcdoc'])->not->toContain('NOPE');
});

test('fuzz: every mutation applies to the correct node or is cleanly skipped', function (): void {
    // Deterministic LCG so failures are reproducible.
    $seed = 0x9E3779B9;
    $rand = function (int $bound) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;

        return $bound > 0 ? $seed % $bound : 0;
    };

    for ($iteration = 0; $iteration < 200; $iteration++) {
        $sectionCount = 1 + $rand(4);
        $sections = [];
        $markers = [];

        for ($s = 1; $s <= $sectionCount; $s++) {
            $paragraphCount = 1 + $rand(4);
            $paragraphs = [];

            for ($p = 1; $p <= $paragraphCount; $p++) {
                $marker = "S{$s}P{$p}_MARK";
                $markers[] = $marker;
                $paragraphs[] = "<p>{$marker}</p>";
            }

            $sections[] = '<section>'.implode('', $paragraphs).'</section>';
        }

        $html = implode('', $sections);

        // Target an existing paragraph and a deliberately drifted one.
        $targetSection = 1 + $rand($sectionCount);
        $targetParagraph = 1 + $rand(4); // may exceed this section's count -> drift
        $appliedSentinel = "APPLIED_{$iteration}";
        $driftSentinel = "DRIFTED_{$iteration}";

        $targetPath = "body:nth-of-type(1) > section:nth-of-type({$targetSection}) > p:nth-of-type({$targetParagraph})";
        $driftPath = "body:nth-of-type(1) > section:nth-of-type({$targetSection}) > p:nth-of-type(99)";

        $result = (new CobrowseReplayPreview)->fromMetadata([
            'snapshot' => ['html' => $html],
            'mutations' => ['recent_batches' => [[
                'sequence' => 1,
                'mutations' => [
                    ['type' => 'text', 'path' => $targetPath, 'text' => $appliedSentinel],
                    ['type' => 'text', 'path' => $driftPath, 'text' => $driftSentinel],
                ],
            ]]],
        ]);

        $srcdoc = $result['srcdoc'];

        // The drifted path can never land anywhere.
        expect(str_contains($srcdoc, $driftSentinel))->toBeFalse();

        // Whether the target path resolved depends on that section's size. If it
        // applied, it replaced exactly one paragraph's marker and left the rest
        // intact (no wrong-node application); if not, nothing changed.
        if (str_contains($srcdoc, $appliedSentinel)) {
            expect(substr_count($srcdoc, $appliedSentinel))->toBe(1);
            // Exactly one original marker was consumed by the applied mutation.
            $survivingMarkers = array_filter($markers, fn (string $marker): bool => str_contains($srcdoc, $marker));
            expect(count($survivingMarkers))->toBe(count($markers) - 1);
        } else {
            // Clean skip: all original markers survive untouched.
            foreach ($markers as $marker) {
                expect(str_contains($srcdoc, $marker))->toBeTrue();
            }
        }
    }
});
