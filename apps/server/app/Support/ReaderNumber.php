<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

/**
 * The one place a number becomes a number someone reads.
 *
 * `number_format()` writes `4,213` in every language the dashboard speaks. A
 * German reader parses that as four-point-two-one-three -- off by three orders
 * of magnitude, and plausible at both readings, so nobody reports it. It is
 * already shipped: `dashboard.conversations.show` is an extracted route, its
 * German catalogue says `:count Knoten`, and the `:count` arrives
 * pre-formatted. The catalogue cannot fix that; the defect is upstream of
 * translation.
 *
 * ## Why this takes no reader, when {@see ReaderClock} does
 *
 * The asymmetry is the point rather than an inconsistency.
 *
 * A timezone has nothing that moves it per request, so `ReaderClock` is handed
 * the reader and mail passes the recipient explicitly. A language DOES move
 * per request: `SetDashboardLocale` has already set `App::setLocale()` from
 * `DashboardLanguage::forRequest()` before a view renders.
 *
 * And it moves with the route's extraction status folded in. An unextracted
 * route resolves to English on purpose, so reading the ambient locale makes
 * this inert there for free -- German numbers never appear on a page that
 * correctly declares `lang="en"`. Taking `DashboardLanguage::for($reader)`
 * instead would produce exactly that leak, which is what the extracted-route
 * list exists to prevent.
 *
 * ## What must not come through here
 *
 * A number that something PARSES BACK is not a number anyone reads, however
 * much it looks like one. `parseInt('1.366', 10)` is 1, and
 * `Number('1.234')` is 1.234. The tells, none of which are about the value:
 *
 * - it sits in a `style` attribute, where it is CSS however it ends -- the
 *   report charts write `height: {{ round(...) }}%`, and `height: 33,33%` is
 *   an invalid declaration the browser drops in silence, flattening the chart;
 * - it sits in a `data-` attribute a script reads back;
 * - it is a CSV cell, which the reader's spreadsheet reparses under its own
 *   locale, or a CSV header a script keys on;
 * - it is on a broadcast, since the widget raises those in English and agents
 *   of every language receive them.
 *
 * The codebase already names the two halves: an array key ending `_value` is
 * the machine side and never comes here, while its formatted twin drops the
 * suffix. {@see CobrowseConsentState} carries eleven such pairs.
 */
final class ReaderNumber
{
    /**
     * A whole count: `4213` reads as `4,213`, `4.213`, `4 213`.
     */
    public static function count(int $value): string
    {
        return (string) Number::format($value, locale: self::locale());
    }

    /**
     * A measured quantity at a fixed precision: `1234.56` at 2.
     */
    public static function decimal(float $value, int $precision): string
    {
        return (string) Number::format($value, precision: $precision, locale: self::locale());
    }

    /**
     * A share, including how its sign is spaced.
     *
     * Not "format it and append a percent sign": the spacing belongs to the
     * locale. English gives `62.5%`, Italian `62,5%`, German `62,5 %` -- and
     * that German space is U+00A0, not U+0020, which is worth knowing before
     * writing an assertion against it.
     */
    public static function percentage(float $value, int $precision = 0): string
    {
        return (string) Number::percentage($value, precision: $precision, locale: self::locale());
    }

    /**
     * The locale this render is already happening in.
     *
     * Passed per call rather than set with `Number::useLocale()`, which is an
     * unscoped process-global defaulting to `en` that nothing in the framework
     * keeps in step with the request. On a persistent worker it would leak
     * between requests the same way `config('app.locale')` does -- the trap
     * {@see DashboardLanguage} already documents.
     */
    private static function locale(): string
    {
        return app()->getLocale();
    }
}
