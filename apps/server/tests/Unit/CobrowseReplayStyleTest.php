<?php

// Server-side inline-style sanitizer for the replay preview (#491 phase 1).
//
// The server is the source of truth: it keeps only an allowlist of layout,
// color, and typography declarations whose values pass a conservative grammar,
// and drops everything else (url(), @import, expression, non-color functions,
// markup breakouts, unknown properties) regardless of what a widget sends.

use App\Support\CobrowseReplayPreview;

function styledPreview(string $style): string
{
    $result = (new CobrowseReplayPreview)->fromMetadata([
        'snapshot' => ['html' => '<p style="'.$style.'">Styled copy.</p>'],
    ]);

    return $result['srcdoc'];
}

test('keeps allowlisted layout, color, and typography declarations', function (): void {
    $srcdoc = styledPreview('color:rgb(10,20,30);font-size:14px;font-weight:600;text-align:center;display:flex;border-radius:4px');

    expect($srcdoc)
        ->toContain('color:rgb(10,20,30)')
        ->and($srcdoc)->toContain('font-size:14px')
        ->and($srcdoc)->toContain('font-weight:600')
        ->and($srcdoc)->toContain('text-align:center')
        ->and($srcdoc)->toContain('display:flex')
        ->and($srcdoc)->toContain('border-radius:4px')
        ->and($srcdoc)->toContain('Styled copy.');
});

test('keeps container layout declarations captured by the widget', function (): void {
    // #520: the widget captures layout on flex/grid containers; the sanitizer
    // must let those declarations through while the value grammar still holds.
    $srcdoc = styledPreview('display:grid;grid-template-columns:480.5px 480.5px;gap:24px;padding:16px 24px;max-width:1120px;justify-content:space-between');

    expect($srcdoc)
        ->toContain('display:grid')
        ->and($srcdoc)->toContain('grid-template-columns:480.5px 480.5px')
        ->and($srcdoc)->toContain('gap:24px')
        ->and($srcdoc)->toContain('padding:16px 24px')
        ->and($srcdoc)->toContain('max-width:1120px')
        ->and($srcdoc)->toContain('justify-content:space-between');
});

test('keeps gradient backgrounds, shadows, and box definition (#521)', function (): void {
    $srcdoc = styledPreview('background-image:linear-gradient(135deg, rgb(13,111,104) 0%, rgba(9,79,75,0.9) 100%);box-shadow:rgba(8,37,34,0.18) 0px 12px 30px 0px;border:1px solid rgb(216,223,220);opacity:0.85');

    expect($srcdoc)
        ->toContain('background-image:linear-gradient(135deg, rgb(13,111,104) 0%, rgba(9,79,75,0.9) 100%)')
        ->and($srcdoc)->toContain('box-shadow:rgba(8,37,34,0.18) 0px 12px 30px 0px')
        ->and($srcdoc)->toContain('border:1px solid rgb(216,223,220)')
        ->and($srcdoc)->toContain('opacity:0.85');
});

test('keeps long gradients within the aligned per-property cap', function (): void {
    // The widget captures gradients up to 500 chars; the server cap must match
    // or values between 257 and 500 chars serialize client-side and silently
    // drop here. Build a many-stop gradient between the old 256 default and
    // the aligned 500 cap.
    $stops = [];

    for ($i = 0; $i <= 14; $i++) {
        $stops[] = sprintf('rgb(%d, %d, %d) %d%%', 10 + $i, 20 + $i, 30 + $i, $i * 7);
    }

    $gradient = 'linear-gradient(135deg, '.implode(', ', $stops).')';

    expect(mb_strlen($gradient))->toBeGreaterThan(256)->toBeLessThanOrEqual(500)
        ->and(styledPreview('background-image:'.$gradient))->toContain('background-image:'.$gradient);

    // Values beyond the aligned cap still drop.
    $oversized = 'linear-gradient(135deg, '.implode(', ', array_merge($stops, $stops, $stops)).')';

    expect(mb_strlen($oversized))->toBeGreaterThan(500)
        ->and(styledPreview('background-image:'.$oversized))->not->toContain('background-image');
});

test('drops gradients whose stops are not color functions', function (): void {
    // Every function inside a value must be allowlisted, so a gradient cannot
    // smuggle a resource fetch through its stops.
    $srcdoc = styledPreview('background-image:linear-gradient(rgb(1,2,3), url(https://evil.example/x.png));color:red');

    expect($srcdoc)
        ->not->toContain('background-image')
        ->and($srcdoc)->not->toContain('evil.example')
        ->and($srcdoc)->toContain('color:red');
});

test('drops url()-bearing declarations and the resource they reference', function (): void {
    $srcdoc = styledPreview('color:red;background-image:url(https://evil.example/x.png)');

    expect($srcdoc)
        ->toContain('color:red')
        ->and($srcdoc)->not->toContain('background-image')
        ->and($srcdoc)->not->toContain('evil.example')
        ->and($srcdoc)->not->toContain('url(');
});

test('drops non-allowlisted properties and out-of-policy values', function (): void {
    // position and z-index are allowlisted since #540, but fixed and
    // five-digit stacking values fail their targeted value rules.
    $srcdoc = styledPreview('color:red;position:fixed;z-index:99999;cursor:url(x)');

    expect($srcdoc)
        ->toContain('color:red')
        ->and($srcdoc)->not->toContain('position')
        ->and($srcdoc)->not->toContain('z-index')
        ->and($srcdoc)->not->toContain('cursor');
});

test('rejects dangerous values even on allowlisted properties', function (): void {
    $srcdoc = styledPreview('font-family:expression(alert(1));background-color:rgb(0,0,0)');

    // expression() is rejected; the safe color is kept.
    expect($srcdoc)
        ->not->toContain('expression')
        ->and($srcdoc)->toContain('background-color:rgb(0,0,0)');
});

test('keeps the pixel boxes captured for content-empty decorative elements', function (): void {
    // #536: the widget sizes content-empty elements (skeletons, panels, dots)
    // with their rendered pixel box so they keep their footprint in the replay.
    $srcdoc = styledPreview('background-color:rgb(240,240,240);width:480px;height:120.5px');

    expect($srcdoc)
        ->toContain('width:480px')
        ->and($srcdoc)->toContain('height:120.5px');
});

test('allows only color functions inside values', function (): void {
    // calc() is not allowlisted as a function, so the whole declaration drops.
    $srcdoc = styledPreview('width:calc(100% - 10px);color:hsl(200,50%,50%)');

    expect($srcdoc)
        ->not->toContain('calc')
        ->and($srcdoc)->toContain('color:hsl(200,50%,50%)');
});

test('removes the style attribute entirely when nothing survives', function (): void {
    $srcdoc = styledPreview('background-image:url(https://evil.example/x);position:fixed');

    expect($srcdoc)
        ->not->toContain('style=')
        ->and($srcdoc)->not->toContain('evil.example')
        ->and($srcdoc)->toContain('Styled copy.');
});

// Page-level background (#535): the widget reports the page's background
// family as snapshot.body_style; the preview emits it as a body override
// after the wrapper defaults, restricted to the background family so the
// override can never restyle the preview shell.

function backgroundPreview(string $bodyStyle): string
{
    $result = (new CobrowseReplayPreview)->fromMetadata([
        'snapshot' => ['html' => '<p>Copy.</p>', 'body_style' => $bodyStyle],
    ]);

    return $result['srcdoc'];
}

test('renders the reported page background as a body override', function (): void {
    $srcdoc = backgroundPreview(
        'background-color:rgb(250,247,242);'
        .'background-image:repeating-linear-gradient(rgba(29,37,35,0.05) 0px, rgba(29,37,35,0.05) 1px, rgba(0,0,0,0) 1px, rgba(0,0,0,0) 24px);'
        .'background-size:24px 24px'
    );

    expect($srcdoc)
        ->toContain('body{background-color:rgb(250,247,242);background-image:repeating-linear-gradient(')
        ->and($srcdoc)->toContain('background-size:24px 24px}');
});

test('body override honors only the background family', function (): void {
    // display/pointer-events/color pass the general element allowlist, but the
    // body override must not restyle the preview shell.
    $srcdoc = backgroundPreview('display:none;pointer-events:auto;color:rgb(9,9,9);background-color:rgb(1,2,3)');

    expect($srcdoc)
        ->toContain('body{background-color:rgb(1,2,3)}')
        ->and($srcdoc)->not->toContain('color:rgb(9,9,9)')
        ->and($srcdoc)->not->toContain('pointer-events:auto');
});

test('hostile page backgrounds are dropped whole', function (): void {
    // url() images and brace breakouts both fail the value grammar; nothing
    // survives, so no body override is emitted at all.
    $srcdoc = backgroundPreview('background-image:url(https://evil.example/bg.png);background-color:rgb(0,0,0)}body{color:red');

    expect($srcdoc)
        ->not->toContain('evil.example')
        ->and($srcdoc)->not->toContain('color:red')
        ->and(substr_count($srcdoc, 'body{'))->toBe(1);
});

test('no body override is emitted without a reported page background', function (): void {
    $result = (new CobrowseReplayPreview)->fromMetadata([
        'snapshot' => ['html' => '<p>Copy.</p>'],
    ]);

    expect(substr_count($result['srcdoc'], 'body{'))->toBe(1);
});

// Positioned overlays (#540): relative/absolute composition replays with px
// offsets and a bounded z-index; fixed and sticky are rejected by a targeted
// value rule so a hostile widget cannot pin content over the preview shell.

test('keeps positioned-overlay declarations', function (): void {
    $srcdoc = styledPreview('position:absolute;top:48px;right:40px;bottom:32px;left:531px;z-index:1');

    expect($srcdoc)
        ->toContain('position:absolute')
        ->and($srcdoc)->toContain('top:48px')
        ->and($srcdoc)->toContain('right:40px')
        ->and($srcdoc)->toContain('bottom:32px')
        ->and($srcdoc)->toContain('left:531px')
        ->and($srcdoc)->toContain('z-index:1');
});

test('keeps relative positioning and negative offsets', function (): void {
    $srcdoc = styledPreview('position:relative;top:-12.5px;z-index:-1');

    expect($srcdoc)
        ->toContain('position:relative')
        ->and($srcdoc)->toContain('top:-12.5px')
        ->and($srcdoc)->toContain('z-index:-1');
});

test('drops fixed and sticky positioning whole', function (): void {
    expect(styledPreview('position:fixed;color:red'))
        ->not->toContain('position')
        ->toContain('color:red')
        ->and(styledPreview('position:sticky;color:red'))->not->toContain('position');
});

test('drops unbounded or non-integer z-index values', function (): void {
    expect(styledPreview('z-index:99999;color:red'))->not->toContain('z-index')
        ->and(styledPreview('z-index:auto;color:red'))->not->toContain('z-index')
        ->and(styledPreview('z-index:calc(1);color:red'))->not->toContain('z-index');
});
