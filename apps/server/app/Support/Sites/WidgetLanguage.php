<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * The language a site's widget speaks by default.
 *
 * A default rather than a setting the visitor is stuck with. The widget puts
 * this behind both the host page's explicit choice and the visitor's own
 * browser, because it is the operator's guess at who visits and the browser is
 * the visitor answering for themselves. It decides the language only when
 * nobody better has spoken -- which is most anonymous traffic, so it still
 * matters.
 *
 * The list of languages lives in two places because the widget has no build
 * step and cannot read PHP. A test compares them, so a catalogue added to one
 * and not the other fails rather than quietly offering an operator a language
 * the widget does not speak.
 */
final class WidgetLanguage
{
    /**
     * Languages the shipped widget carries a catalogue for.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'en' => 'English',
        'de' => 'Deutsch (German)',
    ];

    /**
     * What this site tells the widget, or null to let the visitor decide.
     *
     * Null is the default and the honest one: with nothing configured the
     * widget follows the visitor's browser and falls back to English, which is
     * what every install did before this existed.
     */
    public static function for(Site $site): ?string
    {
        $configured = $site->settings['locale'] ?? null;

        return self::sanitize(is_string($configured) ? $configured : null);
    }

    /**
     * Accept only a language actually shipped.
     *
     * A stored value that is no longer supported -- a catalogue removed, a
     * hand-edited settings blob -- reads as "not configured" rather than being
     * passed to the widget, which would fall back to English anyway but after
     * telling the visitor's browser it had been overruled.
     */
    public static function sanitize(?string $locale): ?string
    {
        $normalised = strtolower(trim((string) $locale));

        return array_key_exists($normalised, self::SUPPORTED) ? $normalised : null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::SUPPORTED;
    }
}
