<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Values that will not execute when a spreadsheet opens them.
 *
 * Excel, Numbers and Sheets treat a leading `=`, `+`, `-` or `@` as the start of
 * a formula, so a visitor-supplied name of `=HYPERLINK(...)` becomes a live
 * link in an exported file. Prefixing an apostrophe forces the cell to text; the
 * apostrophe is not displayed.
 *
 * This lives here rather than beside one exporter because there are now two,
 * and the second copy of a security rule is where the two start disagreeing.
 */
final class SpreadsheetSafeCsv
{
    public static function value(string $value): string
    {
        if (preg_match('/^\s*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * @param  array<int|string, string>  $values
     * @return list<string>
     */
    public static function row(array $values): array
    {
        return array_values(array_map(self::value(...), $values));
    }
}
