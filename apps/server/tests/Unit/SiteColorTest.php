<?php

// The site palette (ADR 0014). The enum and packages/design-tokens/tokens.json
// describe the same six hues in two places, and nothing else would notice if
// they drifted -- a site would simply resolve to a custom property that does
// not exist, and render with no accent at all.

use App\Enums\SiteColor;

test('the enum matches the site palette in the design tokens', function (): void {
    $tokens = json_decode(
        (string) file_get_contents(dirname(__DIR__, 4).'/packages/design-tokens/tokens.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $tokenKeys = array_values(array_filter(
        array_keys($tokens['site']),
        static fn (string $key): bool => ! str_starts_with($key, '$')
    ));

    // tokens.json namespaces them; the enum stores the bare key.
    $tokenKeys = array_map(
        static fn (string $key): string => str_replace('site-', '', $key),
        $tokenKeys
    );

    expect(SiteColor::values())->toBe($tokenKeys);
});

test('every colour resolves through a custom property the tokens define', function (): void {
    $blade = (string) file_get_contents(
        dirname(__DIR__, 2).'/resources/views/components/layouts/app.blade.php'
    );

    $missing = [];

    foreach (SiteColor::cases() as $color) {
        if (! str_contains($blade, $color->cssVariable())) {
            $missing[] = $color->cssVariable();
        }
    }

    expect($missing)->toBe([]);
});

test('a position maps to a stable colour and cycles through the palette', function (): void {
    // Stability is the whole point: a site that changed colour between two page
    // loads would defeat an agent learning it.
    expect(SiteColor::forPosition(0))->toBe(SiteColor::forPosition(0))
        ->and(SiteColor::forPosition(0))->toBe(SiteColor::Red)
        ->and(SiteColor::forPosition(1))->toBe(SiteColor::Blue);

    $palette = count(SiteColor::cases());

    expect(SiteColor::forPosition($palette))->toBe(SiteColor::forPosition(0))
        ->and(SiteColor::forPosition($palette + 3))->toBe(SiteColor::forPosition(3));
});

test('the first six positions are all different, so one desk can tell its sites apart', function (): void {
    $assigned = array_map(
        static fn (int $position): string => SiteColor::forPosition($position)->value,
        range(0, count(SiteColor::cases()) - 1)
    );

    expect($assigned)->toHaveCount(count(array_unique($assigned)));
});

test('a negative position still resolves rather than erroring', function (): void {
    expect(SiteColor::forPosition(-1))->toBeInstanceOf(SiteColor::class);
});
