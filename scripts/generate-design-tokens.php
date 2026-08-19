#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Write the design tokens into every consumer that needs them.
 *
 *   generate-design-tokens.php [--check]
 *
 *   --check   report drift and exit non-zero instead of writing. This is what
 *             CI runs; see scripts/test-design-tokens.sh.
 *
 * Wayfindr has two interfaces and only one of them has stylesheets (ADR 0014).
 * The dashboard's styling is an inline <style> block in a Blade layout. The
 * widget's is an array of CSS strings inside a JavaScript file that has no
 * build step and IS the shipped artifact -- nothing compiles it, so nothing can
 * bundle a stylesheet into it.
 *
 * So the token layer cannot be a stylesheet that both sides import. It is this
 * generator instead: one source, two rendered blocks, both committed, and a
 * drift check that fails the build when either is edited by hand. That is the
 * only shape that keeps a single definition of the visual language while
 * leaving the widget a plain classic script.
 *
 * Deliberately standalone -- no autoloader, no framework. This runs in CI
 * before any composer install, and it must keep working if the application
 * cannot boot.
 */

const EXIT_DRIFT = 1;
const EXIT_USAGE = 2;

$root = dirname(__DIR__);
$check = in_array('--check', array_slice($argv, 1), true);

$source = $root.'/packages/design-tokens/tokens.json';
$raw = @file_get_contents($source);

if ($raw === false) {
    fwrite(STDERR, "Cannot read the token source: {$source}\n");
    exit(EXIT_USAGE);
}

try {
    /** @var array<string, mixed> $tokens */
    $tokens = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, "The token source is not valid JSON: {$e->getMessage()}\n");
    exit(EXIT_USAGE);
}

/**
 * Flatten the grouped source into ordered name => value pairs for one mode.
 *
 * Groups exist in the JSON so a human can find things; the emitted custom
 * property names are flat and globally unique, because a reader scanning CSS
 * should not have to remember which group a token was filed under.
 *
 * @return array<string, string>
 */
function modeValues(array $tokens, string $mode): array
{
    $values = [];

    foreach ($tokens as $group => $entries) {
        // `meta` documents the file; `$`-prefixed keys are notes to the reader.
        if ($group === 'meta' || $group === '$schema' || ! is_array($entries)) {
            continue;
        }

        foreach ($entries as $name => $entry) {
            if (str_starts_with((string) $name, '$')) {
                continue;
            }

            if (is_array($entry)) {
                // A themed token. `dark` is optional: a token that reads the
                // same on both grounds declares `light` alone rather than
                // repeating itself. `light` is NOT optional -- without this
                // check a misspelled key rendered `--wf-paper: ;`, both
                // interfaces silently lost the token, and once the outputs were
                // committed `--check` agreed with itself forever.
                $value = $entry[$mode] ?? $entry['light'] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    fwrite(STDERR, "Token '{$name}' has no usable '{$mode}' or 'light' value.\n");
                    exit(EXIT_USAGE);
                }

                $values[$name] = $value;

                continue;
            }

            if (! is_string($entry) && ! is_numeric($entry)) {
                fwrite(STDERR, "Token '{$name}' is neither a value nor a themed pair.\n");
                exit(EXIT_USAGE);
            }

            $values[$name] = (string) $entry;
        }
    }

    return $values;
}

$light = modeValues($tokens, 'light');
$dark = modeValues($tokens, 'dark');

// Only the tokens that actually differ are restated in the dark blocks. A dark
// block that repeats every value hides which ones were genuinely reconsidered.
$overrides = array_filter(
    $dark,
    static fn (string $value, string $name): bool => $value !== $light[$name],
    ARRAY_FILTER_USE_BOTH
);

$banner = 'Generated from packages/design-tokens/tokens.json by scripts/generate-design-tokens.php. Do not edit by hand -- run `make design-tokens`.';

/** @param array<string, string> $values */
function declarations(array $values, string $indent): string
{
    $lines = [];

    foreach ($values as $name => $value) {
        $lines[] = $indent.'--wf-'.$name.': '.$value.';';
    }

    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// The dashboard: CSS custom properties inside the Blade layout's <style>.
//
// Three blocks, not two. The viewer has three states: an explicit choice stamps
// data-wf-theme, and the default "system" setting stamps nothing at all -- so a
// palette defined only behind [data-wf-theme] never applies to most visitors.
// Bare :root carries the complete light palette; the media query handles the
// unstamped dark case; the attribute selector lets an explicit choice win in
// both directions.
//
// Note this block emits custom properties ONLY. `color-scheme` and the theme
// toggle are shell concerns, and declaring them here would collide with the
// legacy :root that still follows this block until the shell is rebuilt.
// ---------------------------------------------------------------------------

// Rendered first so the heredoc below stays a readable picture of the output.
// (PHP interpolates variables in a heredoc but never function calls.)
$lightDeclarations = declarations($light, '            ');
$darkDeclarationsInMedia = declarations($overrides, '                ');
$darkDeclarationsInAttribute = declarations($overrides, '            ');

$blade = <<<CSS
        /* wayfindr:tokens:start */
        /* {$banner} */
        :root {
{$lightDeclarations}
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-wf-theme="light"]) {
{$darkDeclarationsInMedia}
            }
        }

        :root[data-wf-theme="dark"] {
{$darkDeclarationsInAttribute}
        }
        /* wayfindr:tokens:end */
CSS;

// ---------------------------------------------------------------------------
// The widget: entries in the CSS string array it injects at runtime.
//
// Scoped to .wayfindr-widget rather than :root, because these rules land in a
// page Wayfindr does not own. Leaking custom properties onto the host's :root
// would let our token names collide with the customer's.
// ---------------------------------------------------------------------------

/**
 * Render one declaration block as the payload of a single-quoted JS string.
 *
 * The escaping is not decorative. Font stacks carry quoted family names, and an
 * unescaped `'` closes the JS string literal mid-rule -- which does not degrade
 * the widget, it stops the whole file parsing. Caught the first time this ran.
 *
 * @param array<string, string> $values
 */
function inline(array $values): string
{
    $parts = [];

    foreach ($values as $name => $value) {
        $parts[] = '--wf-'.$name.':'.str_replace(', ', ',', $value);
    }

    return addcslashes(implode(';', $parts), "'\\\\");
}

$widgetLines = [
    "      // wayfindr:tokens:start",
    "      // {$banner}",
    "      '.wayfindr-widget{".inline($light)."}',",
];

if ($overrides !== []) {
    $widgetLines[] = "      '@media (prefers-color-scheme:dark){.wayfindr-widget:not([data-wf-theme=\"light\"]){".inline($overrides)."}}',";
    $widgetLines[] = "      '.wayfindr-widget[data-wf-theme=\"dark\"]{".inline($overrides)."}',";
}

$widgetLines[] = '      // wayfindr:tokens:end';
$widget = implode("\n", $widgetLines);

// ---------------------------------------------------------------------------

/**
 * Replace everything between a start and end marker, keeping the markers.
 */
function splice(string $contents, string $start, string $end, string $replacement, string $path): string
{
    $from = strpos($contents, $start);
    $to = strpos($contents, $end);

    if ($from === false || $to === false || $to < $from) {
        fwrite(STDERR, "Cannot find the token markers in {$path}.\nExpected a block delimited by:\n  {$start}\n  {$end}\n");
        exit(EXIT_USAGE);
    }

    // Extend to the start of the marker's own line so the generated block owns
    // its indentation, and past the end marker's line so a rerun is idempotent.
    $lineStart = strrpos(substr($contents, 0, $from), "\n");
    $from = $lineStart === false ? 0 : $lineStart + 1;
    $to += strlen($end);

    return substr($contents, 0, $from).$replacement.substr($contents, $to);
}

$targets = [
    $root.'/apps/server/resources/views/components/layouts/app.blade.php' => [
        '/* wayfindr:tokens:start */', '/* wayfindr:tokens:end */', $blade,
    ],
    $root.'/packages/widget-js/src/wayfindr-widget.js' => [
        '// wayfindr:tokens:start', '// wayfindr:tokens:end', $widget,
    ],
];

$drifted = [];

foreach ($targets as $path => [$start, $end, $replacement]) {
    $contents = @file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "Cannot read a token consumer: {$path}\n");
        exit(EXIT_USAGE);
    }

    $updated = splice($contents, $start, $end, $replacement, $path);

    if ($updated === $contents) {
        continue;
    }

    if ($check) {
        $drifted[] = $path;

        continue;
    }

    file_put_contents($path, $updated);
    echo 'Updated '.substr($path, strlen($root) + 1)."\n";
}

if ($check && $drifted !== []) {
    fwrite(STDERR, "Design tokens are out of date in:\n");

    foreach ($drifted as $path) {
        fwrite(STDERR, '  '.substr($path, strlen($root) + 1)."\n");
    }

    fwrite(STDERR, "\nEdit packages/design-tokens/tokens.json, then run `make design-tokens` and commit the result.\n");
    exit(EXIT_DRIFT);
}

echo $check
    ? "Design tokens are in sync with packages/design-tokens/tokens.json.\n"
    : "Design tokens written.\n";
