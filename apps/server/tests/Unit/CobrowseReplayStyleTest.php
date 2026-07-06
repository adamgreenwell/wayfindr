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

test('drops url()-bearing declarations and the resource they reference', function (): void {
    $srcdoc = styledPreview('color:red;background-image:url(https://evil.example/x.png)');

    expect($srcdoc)
        ->toContain('color:red')
        ->and($srcdoc)->not->toContain('background-image')
        ->and($srcdoc)->not->toContain('evil.example')
        ->and($srcdoc)->not->toContain('url(');
});

test('drops non-allowlisted properties', function (): void {
    $srcdoc = styledPreview('color:red;position:fixed;z-index:9999;cursor:url(x)');

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

test('allows only color functions inside values', function (): void {
    // calc() is not allowlisted as a function, so the whole declaration drops.
    $srcdoc = styledPreview('width:calc(100% - 10px);color:hsl(200,50%,50%)');

    expect($srcdoc)
        ->not->toContain('calc')
        ->and($srcdoc)->toContain('color:hsl(200,50%,50%)');
});

test('removes the style attribute entirely when nothing survives', function (): void {
    $srcdoc = styledPreview('background-image:url(https://evil.example/x);position:absolute');

    expect($srcdoc)
        ->not->toContain('style=')
        ->and($srcdoc)->not->toContain('evil.example')
        ->and($srcdoc)->toContain('Styled copy.');
});
