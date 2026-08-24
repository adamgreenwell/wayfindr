<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The language the dashboard speaks to one agent.
 *
 * A per-agent choice rather than an account or install setting, because the
 * dashboard is read by people rather than by an organisation: a support team
 * spread across countries has agents who each want their own tools in their own
 * language, and none of them should have to argue with a colleague about it.
 *
 * Distinct from `WidgetLanguage`, which is the operator's guess at who VISITS.
 * The two lists happen to match today and are not required to: a desk can
 * perfectly well answer German visitors from an English-speaking dashboard.
 */
final class DashboardLanguage
{
    /**
     * Languages the dashboard carries a catalogue for.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'en' => 'English',
        'de' => 'Deutsch (German)',
    ];

    public const FALLBACK = 'en';

    /**
     * The locale to render for this agent, always something we can render.
     *
     * Null preference means the install default rather than a broken page --
     * every agent predates this setting, so null is the common case and has to
     * be the safe one.
     */
    public static function for(?User $agent): string
    {
        return self::normalise($agent?->locale)
            // "Use the install default" has to mean the install's default. An
            // operator who set APP_LOCALE=de and left every agent unset -- which
            // is every agent on an upgraded install -- got English from a
            // hard-coded fallback, so the option did the one thing it names.
            //
            // Read from our own config key, NOT from `app.locale`:
            // `App::setLocale()` mutates that one, so after a request rendered
            // for a German agent it says "de", and the next agent with no
            // preference silently inherits a language they never chose.
            ?? self::normalise(config('wayfindr.dashboard_locale'))
            ?? self::FALLBACK;
    }

    /**
     * A supported locale, or null when it is not one we can render.
     */
    public static function normalise(mixed $locale): ?string
    {
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        // `de-DE` and `de_DE` both mean German here. The dashboard does not
        // carry regional variants, and refusing one because of its suffix
        // would be pedantry rather than accuracy.
        $base = strtolower(str_replace('_', '-', trim($locale)));
        $base = explode('-', $base)[0];

        return array_key_exists($base, self::SUPPORTED) ? $base : null;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::SUPPORTED;
    }
}
