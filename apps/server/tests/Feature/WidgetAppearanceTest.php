<?php

use App\Models\Site;
use App\Support\Sites\WidgetAppearance;

/**
 * The widget's accent was a fixed Wayfindr teal in 22 rules while the site's
 * own colour painted a single 3px border. This is what an operator may set --
 * and it is deliberately NOT the ADR 0014 site colour, which exists so an agent
 * can tell sites apart in a queue rather than so a visitor recognises a brand.
 */
function appearanceFor(array $config): WidgetAppearance
{
    return WidgetAppearance::for(new Site(['settings' => ['appearance' => $config]]));
}

test('a site that configures nothing looks exactly as it always did', function (): void {
    $appearance = WidgetAppearance::for(new Site(['settings' => []]));

    expect($appearance->accent)->toBeNull()
        ->and($appearance->accentLight)->toBeNull()
        ->and($appearance->greeting)->toBeNull()
        ->and($appearance->position)->toBe('right');
});

test('one brand colour becomes a rendering per theme', function (): void {
    // Wayfindr does this for its own accent -- #0D6F68 light, #3FA69D dark --
    // and an operator gets one field, not two.
    $appearance = appearanceFor(['accent' => '#7C3AED']);

    expect($appearance->accentLight)->toBe('#7C3AED', 'already clear on white, so untouched')
        ->and($appearance->accentDark)->not->toBe($appearance->accentLight)
        ->and($appearance->accentDark)->toStartWith('#');
});

test('a colour too pale for one theme is adjusted for that theme only', function (): void {
    $appearance = appearanceFor(['accent' => '#FFFF00']);

    // Yellow is invisible on white and perfect on near-black.
    expect($appearance->accentDark)->toBe('#FFFF00')
        ->and($appearance->accentLight)->not->toBe('#FFFF00');
});

test('an adjusted colour stays the same hue, rather than becoming a default', function (): void {
    // A deep navy on a dark panel should become a lighter NAVY. Replacing it
    // with a stock blue would be a widget that ignores what it was told.
    $appearance = appearanceFor(['accent' => '#001F5B']);

    $hue = fn (string $hex) => (function (array $c) {
        [$r, $g, $b] = $c;

        return $b > $r && $b > $g;
    })([hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]);

    expect($hue($appearance->accentDark))->toBeTrue('still blue-dominant after lightening');
});

test('white and black do not divide by zero', function (): void {
    // PHP's `/` returns an INT when the division comes out whole, so
    // hexdec('FF') / 255 is int(1) and a `=== 0.0` guard never fired for a
    // greyscale colour. Both extremes crashed.
    foreach (['#FFFFFF', '#000000', '#808080'] as $hex) {
        $appearance = appearanceFor(['accent' => $hex]);

        expect($appearance->accentLight)->toMatch('/^#[0-9A-F]{6}$/')
            ->and($appearance->accentDark)->toMatch('/^#[0-9A-F]{6}$/');
    }
});

test('the text on an accent is derived, never chosen', function (): void {
    // An operator picking both a colour and the text on it is an operator who
    // can pick an unreadable pair.
    expect(appearanceFor(['accent' => '#000080'])->accentInkLight)->toBe('#FFFFFF')
        ->and(appearanceFor(['accent' => '#FFFF00'])->accentInkDark)->toBe('#141517');
});

test('a brand hex is not refused for being a brand', function (): void {
    // The first version of this rejected #7C3AED, and rejected Wayfindr's own
    // #0D6F68 with it. A form that calls an operator's brand invalid is wrong
    // about the operator, not the colour.
    foreach (['#7C3AED', '#0D6F68', '#FFFF00', '#000000', '7C3AED', '#7C3'] as $hex) {
        expect(WidgetAppearance::accentRejection($hex))->toBeNull("{$hex} should be usable");
    }

    expect(WidgetAppearance::accentRejection('nonsense'))->not->toBeNull();
});

test('shorthand and a missing hash are read as what they obviously mean', function (): void {
    expect(appearanceFor(['accent' => '#7C3'])->accent)->toBe('#77CC33')
        ->and(appearanceFor(['accent' => '7c3aed'])->accent)->toBe('#7C3AED');
});

test('an unknown position falls back rather than breaking the layout', function (): void {
    expect(appearanceFor(['position' => 'left'])->position)->toBe('left')
        ->and(appearanceFor(['position' => 'upside-down'])->position)->toBe('right')
        ->and(appearanceFor(['position' => ['left']])->position)->toBe('right');
});

test('copy is trimmed, bounded, and empty means unset', function (): void {
    expect(appearanceFor(['greeting' => '  Hello there  '])->greeting)->toBe('Hello there')
        ->and(appearanceFor(['greeting' => '   '])->greeting)->toBeNull()
        ->and(mb_strlen(appearanceFor(['placeholder' => str_repeat('x', 400)])->placeholder))->toBe(120);
});
