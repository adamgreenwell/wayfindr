<?php

// The <x-icon> component (ADR 0014). The unit tests cover the set on disk;
// these cover what actually reaches the page.

use Illuminate\Support\Facades\Blade;

test('it inlines the icon geometry inside a single svg', function (): void {
    $html = Blade::render('<x-icon name="conversations" />');

    expect($html)->toContain('<path d="M3.75 4.75h16.5v11h-9l-4.5 3.5v-3.5h-3z"/>')
        ->and(substr_count($html, '<svg'))->toBe(1)
        ->and($html)->toContain('viewBox="0 0 24 24"');
});

test('it is decorative by default so a labelled nav item is not read twice', function (): void {
    $html = Blade::render('<x-icon name="sites" />');

    expect($html)->toContain('aria-hidden="true"')
        ->and($html)->toContain('focusable="false"')
        ->and($html)->not->toContain('role="img"');
});

test('a label makes it announced, for an icon that carries the meaning alone', function (): void {
    $html = Blade::render('<x-icon name="close" label="Close panel" />');

    expect($html)->toContain('role="img"')
        ->and($html)->toContain('aria-label="Close panel"')
        ->and($html)->not->toContain('aria-hidden');
});

test('it sizes to 16 by default and takes an override', function (): void {
    expect(Blade::render('<x-icon name="check" />'))->toContain('width="16"');
    expect(Blade::render('<x-icon name="check" :size="24" />'))->toContain('width="24"');
});

test('extra attributes merge rather than replace the icon class', function (): void {
    $html = Blade::render('<x-icon name="search" class="nav-icon" />');

    expect($html)->toContain('wf-icon')->toContain('nav-icon');
});

test('a typo in an icon name fails the render instead of leaving a hole', function (): void {
    // Blade wraps it in a ViewException, so the type is not the guarantee --
    // the guarantee is that it stops the render and says which name was wrong
    // and what exists, rather than emitting an empty <svg> nobody notices.
    try {
        Blade::render('<x-icon name="conversation" />');
        $this->fail('An unknown icon rendered without complaint.');
    } catch (Throwable $e) {
        expect($e->getMessage())
            ->toContain("No icon named 'conversation'")
            ->toContain('conversations');
    }
});
