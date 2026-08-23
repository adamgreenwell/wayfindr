<?php

namespace App\Support\Mail;

/**
 * The part of a reply the sender actually wrote.
 *
 * Mail clients quote the entire preceding thread beneath every reply, so a
 * transcript that stores what arrived doubles in length on each exchange: by
 * the fourth message an agent is scrolling past three copies of the first. The
 * quoted history is not lost -- it is already in the transcript, as the earlier
 * messages it is a copy of.
 *
 * There is no standard for this and no way to be right every time. The rule
 * here is to cut at the EARLIEST boundary that is unambiguous, and to keep the
 * whole message when nothing is: showing too much is a readability problem,
 * while cutting a sentence somebody wrote is a correctness one.
 */
final class QuotedText
{
    /**
     * Lines that begin quoted history in the clients people actually use.
     *
     * Anchored to the start of a line, because these strings appear in ordinary
     * prose too -- somebody writing "the original message said" should not lose
     * the rest of their paragraph.
     */
    private const BOUNDARIES = [
        // Outlook and its imitators.
        '/^-{2,}\s*Original Message\s*-{2,}$/i',
        '/^-{2,}\s*Forwarded message\s*-{2,}$/i',
        // Gmail, Apple Mail, and most others: "On <date> <someone> wrote:".
        '/^On .{4,120}\bwrote:$/u',
        // The same attribution wrapped by the client onto two lines.
        '/^On .{4,200}$/u',
        // Outlook's header block.
        '/^From:\s.+$/i',
        // Some clients mark it explicitly.
        '/^_{5,}$/',
    ];

    public static function strip(string $body): string
    {
        $lines = preg_split('/\R/', $body) ?: [];
        $cut = count($lines);

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (self::isQuoteBoundary($trimmed, $lines, $index)) {
                $cut = $index;

                break;
            }

            // A run of quoted lines that never had an attribution above it.
            if (str_starts_with($trimmed, '>')) {
                $cut = $index;

                break;
            }
        }

        $kept = array_slice($lines, 0, $cut);

        // The signature goes too, but only on the standard delimiter: "-- " on
        // a line of its own is the one convention clients actually agree on.
        foreach ($kept as $index => $line) {
            if (rtrim($line) === '--') {
                $kept = array_slice($kept, 0, $index);

                break;
            }
        }

        $text = rtrim(implode("\n", $kept));

        // Everything looked like quoted history, which means the guess was
        // wrong. A message that reads oddly beats a message that is empty.
        return trim($text) === '' ? trim($body) : $text;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function isQuoteBoundary(string $trimmed, array $lines, int $index): bool
    {
        foreach (self::BOUNDARIES as $position => $pattern) {
            if (preg_match($pattern, $trimmed) !== 1) {
                continue;
            }

            // The two loose patterns -- a wrapped "On ..." attribution and
            // Outlook's "From:" header -- match ordinary sentences too, so they
            // only count when what follows looks like a quoted block. Requiring
            // corroboration is what stops "On Tuesday we shipped it" ending a
            // message mid-thought.
            if ($position === 3) {
                return self::nextMeaningfulLine($lines, $index) !== null
                    && preg_match('/\bwrote:$/u', (string) self::nextMeaningfulLine($lines, $index)) === 1;
            }

            if ($position === 4) {
                return self::followedByHeaderBlock($lines, $index);
            }

            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function nextMeaningfulLine(array $lines, int $index): ?string
    {
        for ($next = $index + 1; $next < count($lines); $next++) {
            if (trim($lines[$next]) !== '') {
                return trim($lines[$next]);
            }
        }

        return null;
    }

    /**
     * Outlook writes From/Sent/To/Subject together. One "From:" alone is a
     * sentence; three of those four in a row is a quoted header block.
     *
     * @param  list<string>  $lines
     */
    private static function followedByHeaderBlock(array $lines, int $index): bool
    {
        $seen = 0;

        for ($next = $index + 1; $next < min($index + 6, count($lines)); $next++) {
            if (preg_match('/^(Sent|To|Subject|Date|Cc):\s/i', trim($lines[$next])) === 1) {
                $seen++;
            }
        }

        return $seen >= 2;
    }
}
