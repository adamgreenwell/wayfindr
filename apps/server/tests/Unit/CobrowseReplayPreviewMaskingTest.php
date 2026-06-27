<?php

// Defense-in-depth masking for the agent-side replay preview.
//
// The widget masks before sending, but the server cannot trust older widgets,
// custom integrations, or hostile clients to have done so. CobrowseReplayPreview
// is the last masking layer before an agent sees reconstructed page state, so it
// must independently strip executable, network-bearing, and form-value content.
//
// See issue #487 and docs/privacy/cobrowse-data-boundaries.md.

use App\Support\CobrowseReplayPreview;

test('re-sanitizes hostile snapshot html the widget should have masked', function (): void {
    $preview = new CobrowseReplayPreview;

    $result = $preview->fromMetadata([
        'snapshot' => [
            'html' => implode('', [
                '<p onclick="steal()">Visible copy.</p>',
                '<script>window.stolen = document.cookie;</script>',
                '<style>body{background:url(https://evil.example/x)}</style>',
                '<a href="javascript:steal()">link</a>',
                '<iframe src="https://evil.example"></iframe>',
                '<img src="https://evil.example/track.gif" alt="tracker">',
                '<input value="should-not-survive">',
                '<textarea>secret-textarea</textarea>',
            ]),
        ],
    ]);

    expect($result)->not->toBeNull();

    $srcdoc = $result['srcdoc'];

    expect($srcdoc)
        // Executable / structural threats removed.
        ->not->toContain('<script')
        ->and($srcdoc)->not->toContain('window.stolen')
        // The visitor stylesheet is dropped (the preview injects only its own
        // wrapper styles, so we assert on the visitor payload, not on <style>).
        ->and($srcdoc)->not->toContain('background:url')
        ->and($srcdoc)->not->toContain('<iframe')
        ->and($srcdoc)->not->toContain('evil.example')
        // Event handlers and url-bearing attributes stripped.
        ->and($srcdoc)->not->toContain('onclick')
        ->and($srcdoc)->not->toContain('javascript:')
        // Form values masked server-side regardless of what the widget sent.
        ->and($srcdoc)->not->toContain('should-not-survive')
        ->and($srcdoc)->not->toContain('secret-textarea')
        // Safe visible content is preserved so the preview stays useful.
        ->and($srcdoc)->toContain('Visible copy.');
});

test('only applies safe mutation types and attributes during replay', function (): void {
    $preview = new CobrowseReplayPreview;

    $paragraphPath = 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(1)';
    $divPath = 'body:nth-of-type(1) > div:nth-of-type(1)';

    $result = $preview->fromMetadata([
        'snapshot' => [
            'html' => '<div><p>Original.</p></div>',
        ],
        'mutations' => [
            'recent_batches' => [
                [
                    'sequence' => 1,
                    'mutations' => [
                        ['type' => 'text', 'path' => $paragraphPath, 'text' => 'Updated copy.'],
                        // Unsafe attribute mutation must be ignored.
                        ['type' => 'attribute', 'path' => $paragraphPath, 'attribute_name' => 'onclick', 'attribute_value' => 'steal()'],
                        // Safe aria attribute mutation is applied.
                        ['type' => 'attribute', 'path' => $paragraphPath, 'attribute_name' => 'aria-expanded', 'attribute_value' => 'true'],
                        // Added subtree is sanitized before insertion.
                        ['type' => 'added', 'path' => $divPath, 'html' => '<span onclick="x()">Added.</span><script>evil()</script>'],
                        // Unknown mutation type is skipped, not applied.
                        ['type' => 'innerHTML', 'path' => $divPath, 'html' => '<b>nope</b>'],
                    ],
                ],
            ],
        ],
    ]);

    expect($result)->not->toBeNull();

    $srcdoc = $result['srcdoc'];

    expect($srcdoc)
        ->toContain('Updated copy.')
        ->and($srcdoc)->toContain('aria-expanded')
        ->and($srcdoc)->toContain('Added.')
        ->and($srcdoc)->not->toContain('onclick')
        ->and($srcdoc)->not->toContain('steal()')
        ->and($srcdoc)->not->toContain('<script')
        ->and($srcdoc)->not->toContain('evil()')
        ->and($srcdoc)->not->toContain('nope');
});

test('ignores mutation paths that do not resolve to a snapshot node', function (): void {
    $preview = new CobrowseReplayPreview;

    $result = $preview->fromMetadata([
        'snapshot' => [
            'html' => '<div><p>Original.</p></div>',
        ],
        'mutations' => [
            'recent_batches' => [
                [
                    'sequence' => 1,
                    'mutations' => [
                        // Path points at a sibling index that does not exist.
                        ['type' => 'text', 'path' => 'body:nth-of-type(1) > div:nth-of-type(1) > p:nth-of-type(5)', 'text' => 'Drifted update.'],
                    ],
                ],
            ],
        ],
    ]);

    expect($result)->not->toBeNull();

    // A drifted path is cleanly skipped: the original content is untouched and
    // the bogus update never lands on the wrong node.
    expect($result['srcdoc'])
        ->toContain('Original.')
        ->and($result['srcdoc'])->not->toContain('Drifted update.')
        ->and($result['skipped_mutations'])->toContain('skipped');
});

test('returns null when no snapshot html exists yet', function (): void {
    $preview = new CobrowseReplayPreview;

    expect($preview->fromMetadata([]))->toBeNull();
    expect($preview->fromMetadata(['snapshot' => ['html' => '']]))->toBeNull();
});
