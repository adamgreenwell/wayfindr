<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\File;

/**
 * The languages this dashboard can be read in.
 *
 * Deliberately not yet a setting anybody can choose. Extraction is proceeding a
 * surface at a time, and a console that is German in the sidebar and English in
 * every screen behind it is worse than one that is honestly English throughout.
 * A language becomes offerable when {@see self::EXTRACTION_COMPLETE} says the
 * console has finished being extracted -- not when a catalogue merely exists.
 *
 * The widget made the opposite call for the opposite reason: a visitor did not
 * choose this product and cannot be asked to read English, and its 82 strings
 * could be finished in one sitting. The dashboard is roughly twelve thousand
 * words, and an agent chose to work here (ADR 0017).
 */
final class Locales
{
    public const FALLBACK = 'en';

    /**
     * Languages carried, whether or not they are complete.
     *
     * @var array<string, string>
     */
    public const CARRIED = [
        'en' => 'English',
        'de' => 'Deutsch (German)',
    ];

    /**
     * Scripts that run right to left.
     *
     * The same short list the widget carries, and for the same reason:
     * `Intl.Locale.textInfo` is not available everywhere Wayfindr runs, and a
     * dashboard that renders backwards is worse than one carrying a list.
     *
     * @var list<string>
     */
    private const RTL = ['ar', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'];

    public static function direction(?string $locale = null): string
    {
        $language = strtolower(explode('_', str_replace('-', '_', (string) ($locale ?? app()->getLocale())))[0]);

        return in_array($language, self::RTL, true) ? 'rtl' : 'ltr';
    }

    /**
     * The `lang` attribute for a document.
     */
    public static function tag(?string $locale = null): string
    {
        return str_replace('_', '-', (string) ($locale ?? app()->getLocale()));
    }

    /**
     * Whether the dashboard has finished being extracted into these files.
     *
     * Flipping this is what makes a language offerable, and it is deliberately
     * a human decision rather than a computed one. Key parity cannot answer it:
     * `de` matches `en` perfectly today across the handful of surfaces that have
     * been extracted, while the great majority of the console is still English
     * literals in Blade that no catalogue knows about. A language that "matches"
     * is not a language that is ready.
     */
    public const EXTRACTION_COMPLETE = false;

    /**
     * Languages an operator or agent may actually be shown.
     *
     * Empty until extraction finishes, because a console that is German in the
     * sidebar and English in every screen behind it is worse than one that is
     * honestly English throughout.
     *
     * @return array<string, string>
     */
    public static function offerable(): array
    {
        if (! self::EXTRACTION_COMPLETE) {
            return [self::FALLBACK => self::CARRIED[self::FALLBACK]];
        }

        return array_filter(
            self::CARRIED,
            static fn (string $label, string $locale): bool => self::hasFullParity($locale),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Whether a language answers every question English answers *so far*.
     *
     * Read from the files rather than declared in code, because a declaration is
     * a claim somebody has to remember to withdraw. A key added to `en` and not
     * to `de` makes German fall out of parity the moment it is committed,
     * without anyone deciding anything.
     *
     * This is a drift check, not a readiness signal -- see
     * {@see self::EXTRACTION_COMPLETE}.
     */
    public static function hasFullParity(string $locale): bool
    {
        return self::missingKeys($locale) === [];
    }

    /**
     * Keys `en` has that this language does not, as `file.key` paths.
     *
     * @return list<string>
     */
    public static function missingKeys(string $locale): array
    {
        if ($locale === self::FALLBACK) {
            return [];
        }

        $missing = [];

        foreach (self::keys(self::FALLBACK) as $key) {
            if (! in_array($key, self::keys($locale), true)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Every `file.key` path a language defines.
     *
     * @return list<string>
     */
    public static function keys(string $locale): array
    {
        $directory = lang_path($locale);

        if (! File::isDirectory($directory)) {
            return [];
        }

        $keys = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $group = $file->getFilenameWithoutExtension();

            /** @var array<string, mixed> $lines */
            $lines = require $file->getPathname();

            foreach (self::flatten($lines) as $key) {
                $keys[] = $group.'.'.$key;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return list<string>
     */
    private static function flatten(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, self::flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }
}
