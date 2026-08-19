<?php

// The icon set (ADR 0014). Two failure modes drive these tests: an icon that
// renders as nothing looks like a styling bug and survives review, and a set
// whose files drift apart in stroke weight looks wrong without ever failing.

use App\Support\Design\IconSet;

test('every icon in the set inlines a non-empty body', function (): void {
    expect(IconSet::names())->not->toBeEmpty();

    foreach (IconSet::names() as $name) {
        expect(IconSet::body($name))->not->toBe('');
    }
});

test('the body excludes the svg wrapper so the component owns presentation', function (): void {
    // The files carry a wrapper so a designer can open one on its own; the
    // component supplies the authoritative one. Inlining both would nest
    // <svg> inside <svg>.
    foreach (IconSet::names() as $name) {
        expect(IconSet::body($name))->not->toContain('<svg');
    }
});

test('an unknown icon fails loudly rather than rendering nothing', function (): void {
    expect(fn () => IconSet::body('no-such-icon'))
        ->toThrow(RuntimeException::class);
});

test('an icon name cannot escape the icon directory', function (): void {
    foreach (['../../../etc/passwd', 'foo/bar', 'Foo', 'foo_bar', ''] as $name) {
        expect(fn () => IconSet::body($name))
            ->toThrow(InvalidArgumentException::class);
    }
});

test('every source file declares the same geometry and stroke conventions', function (): void {
    $required = [
        'viewBox="0 0 24 24"' => 'off-grid',
        'stroke-width="1.5"' => 'different stroke weight',
        'stroke="currentColor"' => 'does not inherit its colour',
        'stroke-linecap="butt"' => 'soft terminals',
        'stroke-linejoin="miter"' => 'soft joins',
    ];

    // Collected rather than asserted one at a time, so a failure names every
    // offending icon instead of stopping at the first.
    $violations = [];

    foreach (IconSet::names() as $name) {
        $svg = (string) file_get_contents(IconSet::directory()."/{$name}.svg");

        foreach ($required as $needle => $problem) {
            if (! str_contains($svg, $needle)) {
                $violations[] = "{$name}: {$problem}";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('no icon hardcodes a colour', function (): void {
    // An icon that paints itself cannot sit on the dark ground, and cannot take
    // a site's colour when the queues start using one.
    foreach (IconSet::names() as $name) {
        $body = IconSet::body($name);

        expect($body)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
            ->and($body)->not->toContain('rgb(');
    }
});

test('the icons the shell navigates with are all present', function (): void {
    // Named explicitly: step 3 builds the sidebar against these, and a rename
    // should break here rather than leave a hole in the navigation.
    expect(IconSet::names())->toContain(
        'dashboard', 'conversations', 'tickets', 'alerts',
        'readiness', 'sites', 'account', 'operator',
    );
});
