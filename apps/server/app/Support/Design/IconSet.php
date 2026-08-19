<?php

declare(strict_types=1);

namespace App\Support\Design;

use InvalidArgumentException;
use RuntimeException;

/**
 * The dashboard's icon set (ADR 0014).
 *
 * Icons are inlined into the markup rather than served as a sprite or a font.
 * A sprite needs a second request that must succeed before navigation is
 * readable, and an icon font renders as tofu when it does not load -- both fail
 * quietly on exactly the self-hosted installs least able to notice, which is the
 * same reasoning that vendors the typefaces and the realtime client.
 *
 * Only the inner geometry lives in `resources/icons`. The <svg> wrapper and its
 * stroke conventions live in the `<x-icon>` component, so the set cannot drift
 * into sixteen slightly different stroke widths.
 */
final class IconSet
{
    /**
     * Name characters are constrained rather than trusted. `name` is
     * developer-supplied today, but this reads from disk by interpolation, and
     * a path is one refactor away from being user-influenced.
     */
    private const NAME = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * The inner geometry of one icon, ready to inline.
     */
    public static function body(string $name): string
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException("Not a valid icon name: {$name}");
        }

        $path = self::directory().'/'.$name.'.svg';

        // Loudly, on purpose. A missing icon that renders as nothing is a hole
        // in the navigation that looks like a styling bug and survives review.
        if (! is_file($path)) {
            throw new RuntimeException(
                "No icon named '{$name}' in resources/icons. Available: ".implode(', ', self::names())
            );
        }

        $svg = (string) file_get_contents($path);

        // Strip the wrapper the file carries so it can be previewed on its own.
        // The component supplies the authoritative one.
        $inner = preg_replace('/^.*?<svg\b[^>]*>(.*)<\/svg>\s*$/s', '$1', $svg);

        return self::$cache[$name] = trim((string) $inner);
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];

        foreach (glob(self::directory().'/*.svg') ?: [] as $path) {
            $names[] = basename($path, '.svg');
        }

        sort($names);

        return $names;
    }

    /**
     * Resolved from this file rather than through `resource_path()`.
     *
     * `tests/Pest.php` extends the application TestCase in `Feature` only, so
     * unit tests run with no booted container and the helper is unavailable.
     * Keeping this framework-free is what lets the set be tested without a
     * booted application, the same way the release tooling is.
     */
    public static function directory(): string
    {
        return dirname(__DIR__, 3).'/resources/icons';
    }
}
