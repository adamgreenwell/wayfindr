<?php

// Server-side SVG sanitization and image placeholders for the replay (#523).
//
// The server is the enforcement boundary: whatever a widget sends, inline SVG
// survives only through the element/attribute allowlist (subtrees of
// disallowed elements drop whole), url(#...) is the only allowed url() form,
// and every <img> becomes a same-size placeholder — no image URL or pixel
// data can reach the agent.

use App\Support\CobrowseReplayPreview;

function svgImagePreview(string $html): string
{
    $result = (new CobrowseReplayPreview)->fromMetadata([
        'snapshot' => ['html' => $html],
    ]);

    return $result['srcdoc'];
}

test('keeps a sanitized inline SVG from a hostile snapshot', function (): void {
    $srcdoc = svgImagePreview(
        '<svg viewbox="0 0 24 24" onload="alert(1)">'
        .'<defs><lineargradient id="brand"><stop offset="0" stop-color="rgb(13,111,104)"></stop></lineargradient></defs>'
        .'<path d="M4 4h16v16H4z" fill="url(#brand)"></path>'
        .'<use href="#brand"></use>'
        .'<foreignobject><div>smuggled</div></foreignobject>'
        .'<image href="https://evil.example/x.png"></image>'
        .'</svg>'
    );

    expect($srcdoc)
        ->toContain('<svg')
        ->and($srcdoc)->toContain('d="M4 4h16v16H4z"')
        ->and($srcdoc)->toContain('fill="url(#brand)"')
        ->and($srcdoc)->toContain('href="#brand"')
        ->and($srcdoc)->not->toContain('onload')
        ->and($srcdoc)->not->toContain('smuggled')
        ->and($srcdoc)->not->toContain('foreignobject')
        ->and($srcdoc)->not->toContain('<image')
        ->and($srcdoc)->not->toContain('evil.example');
});

test('drops external references inside SVG while keeping the shapes', function (): void {
    $srcdoc = svgImagePreview(
        '<svg viewbox="0 0 10 10">'
        .'<path d="M0 0h10" fill="url(https://evil.example/paint)"></path>'
        .'<use href="https://evil.example/sprite.svg#icon" xlink:href="https://evil.example/s.svg#i"></use>'
        .'</svg>'
    );

    expect($srcdoc)
        ->toContain('d="M0 0h10"')
        ->and($srcdoc)->not->toContain('evil.example')
        ->and($srcdoc)->not->toContain('url(https');
});

test('drops oversized SVGs entirely', function (): void {
    $shapes = str_repeat('<rect width="1" height="1"></rect>', 220);

    $srcdoc = svgImagePreview('<p>Copy stays.</p><svg viewbox="0 0 10 10">'.$shapes.'</svg>');

    expect($srcdoc)
        ->toContain('Copy stays.')
        ->and($srcdoc)->not->toContain('<svg')
        ->and($srcdoc)->not->toContain('<rect');
});

test('replaces images with same-size placeholders and styles them in the wrapper', function (): void {
    $srcdoc = svgImagePreview(
        '<img src="https://cdn.example.test/product.png" alt="Blue running shoe" width="320" height="240">'
    );

    expect($srcdoc)
        ->not->toContain('<img')
        ->and($srcdoc)->not->toContain('cdn.example.test')
        ->and($srcdoc)->toContain('<wayfindr-img-placeholder')
        ->and($srcdoc)->toContain('width:320px;height:240px')
        ->and($srcdoc)->toContain('Blue running shoe')
        ->and($srcdoc)->toContain('wayfindr-img-placeholder{display:inline-block');
});

test('masked images keep their placeholder but never their alt text', function (): void {
    $srcdoc = svgImagePreview(
        '<img src="https://cdn.example.test/id.png" alt="Passport scan for Jane Doe" width="300" height="200" data-secret>'
    );

    expect($srcdoc)
        ->not->toContain('<img')
        ->and($srcdoc)->not->toContain('Passport scan')
        ->and($srcdoc)->not->toContain('Jane Doe')
        ->and($srcdoc)->toContain('[masked]');
});
