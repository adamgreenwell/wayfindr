<?php

// Visitor viewport width for the replay preview (#522).
//
// The preview renders at the visitor's reported viewport width so captured
// layout keeps its real proportions. The width comes from the page-state
// report; anything non-numeric or outside a sane range is treated as
// unreported so a hostile widget cannot force absurd preview geometry.

use App\Support\CobrowseReplayPreview;

function previewWithViewport(mixed $viewportWidth): ?array
{
    $metadata = [
        'snapshot' => ['html' => '<p>Copy.</p>'],
    ];

    if ($viewportWidth !== 'omit') {
        $metadata['page_state'] = ['viewport_width' => $viewportWidth];
    }

    return (new CobrowseReplayPreview)->fromMetadata($metadata);
}

test('reports the visitor viewport width from page state', function (): void {
    expect(previewWithViewport(1456)['viewport_width'])->toBe(1456)
        ->and(previewWithViewport('1456')['viewport_width'])->toBe(1456)
        ->and(previewWithViewport(320)['viewport_width'])->toBe(320)
        ->and(previewWithViewport(3840)['viewport_width'])->toBe(3840);
});

test('treats missing or out-of-range viewport widths as unreported', function (): void {
    expect(previewWithViewport('omit')['viewport_width'])->toBeNull()
        ->and(previewWithViewport(null)['viewport_width'])->toBeNull()
        ->and(previewWithViewport('wide')['viewport_width'])->toBeNull()
        ->and(previewWithViewport(0)['viewport_width'])->toBeNull()
        ->and(previewWithViewport(319)['viewport_width'])->toBeNull()
        ->and(previewWithViewport(999999)['viewport_width'])->toBeNull();
});
