<?php

declare(strict_types=1);
use App\Support\ReaderClock;

/**
 * Every absolute time a person reads crosses {@see ReaderClock}.
 *
 * This guard exists because the same mistake was made twice in one change.
 * The first sweep searched `resources/views/` and missed the controllers that
 * format a timestamp into a string before the view ever sees it -- an audit
 * list and two flash messages, all rendering UTC to an agent who had chosen
 * Berlin. There is no way to see that from a view.
 *
 * So the rule is stated once, here, and every new call site has to answer it:
 * either the moment goes through the seam, or it is listed below with the
 * reason it must not. Both are fine answers. Silence is not, because silently
 * rendering storage's clock is exactly what this looks like when it goes wrong
 * -- a plausible time, in the wrong zone, that nobody thinks to report.
 */

/**
 * Call sites that deliberately do NOT use the reader's clock.
 *
 * Each entry says whose clock it is instead. A time that belongs to a subject
 * rather than a reader, a value on its way into the database, or a string that
 * is not being read as a time at all.
 *
 * @var array<string, string>
 */
const READER_CLOCK_EXEMPT = [
    // The site's own zone. "Visitors are told support is back at 09:00" is a
    // statement about the site's schedule and stays true whichever clock the
    // agent reading it is on.
    'app/Http/Controllers/AgentSiteController.php' => "the site's support hours",
    'resources/views/agent/sites/show.blade.php' => "the site's support hours",

    // Values being WRITTEN, not read. Storage is UTC and must stay UTC.
    'app/Http/Controllers/Widget/ConversationRatingController.php' => 'columns, not copy',

    // Filenames. A downloaded artifact outlives the session that made it and
    // may be read by a team spread across zones, so its stamp stays
    // unambiguous. The BackupService stamp is also parsed back out of the
    // filename, so moving it would break the round-trip.
    'app/Support/Backup/BackupService.php' => 'an archive filename, parsed back',

    // A sort key (`U.u`), which is an instant and reads the same everywhere.
    'app/Http/Controllers/AgentTicketController.php' => 'a sort key, not a rendering',

    // The seam's own machinery, and days that ReportingWindow already handed
    // back on the reader's clock.
    'app/Support/Reporting/ReportingWindow.php' => 'the conversion itself',

    // `$day->format('j M')` on days `ReportingWindow::days()` already handed
    // back on the reader's clock. Passing them through the seam again would be
    // a no-op that reads as a conversion.
    'app/Http/Controllers/AgentReportController.php' => 'days ReportingWindow already converted',

    // The seam's own implementation. The line-level skip below looks for the
    // word `ReaderClock`, which these lines do not contain because they call
    // `self::`.
    'app/Support/ReaderClock.php' => 'the conversion itself',

    // ISO strings written into notification data and parsed back out of it.
    // Machine keys: `waiting_since` and `last_attempted_at` reach a reader
    // only through `diffForHumans()`, which is an interval and already follows
    // the page's language.
    'app/Models/User.php' => 'a stored delivery stamp, compared not read',
    'app/Support/UnattendedConversationAlertCollector.php' => 'episode keys, compared not read',
    'app/Listeners/NotifyAgentsOfVisitorMessage.php' => 'episode keys, compared not read',
];

/**
 * Line shapes that are not a moment being read.
 *
 * Narrower than exempting a whole file, so a file can hold one of these and
 * still be checked everywhere else.
 *
 * @var array<string, string>
 */
const READER_CLOCK_EXEMPT_LINES = [
    // A sortable stamp in a download's filename. The artifact outlives the
    // session that made it and may be opened by a team spread across zones, so
    // it stays unambiguous rather than local.
    'Ymd-His' => 'a filename stamp',

    // `AlertDigestCandidateCollector::timestamp()` -- the machine half of a
    // display/machine pair, parsed back to decide whether a digest entry is
    // stale. Named by shape rather than by file, because its display twin
    // `label()` lives beside it and must stay checked.
    'return $timestamp?->toISOString();' => 'the machine half of a labelled pair',

    // Stored on notification data and parsed back as a delivery watermark;
    // unlike the candidate label beside it, this value is never rendered.
    'self::DIGEST_QUEUED_AT_KEY => $queuedAt->toISOString(),' => 'a stored digest watermark',
];

/**
 * Formatting calls that produce a moment for someone to read.
 *
 * `isoFormat` is here because it is now the seam's own spelling, and a call
 * site using it directly would be invisible to the `->format('` matcher -- the
 * shape a guard is worst at, one it cannot see by construction.
 *
 * `toISOString` is here because it was already being rendered as prose. The
 * alert digest printed `2026-08-24T15:05:00.000000Z` into an agent's email, in
 * storage's clock, and this guard walked past it: the call was in neither
 * matcher, the collector was in no exemption list, and the ISO escape hatch
 * below named only `toIso8601String`. A guard with a hole shaped exactly like
 * the defect reads as proof the defect is absent.
 */
const READER_CLOCK_CALLS = [
    'toDateTimeString', 'toDayDateTimeString', 'toFormattedDayDateString',
    'toDateString', 'toTimeString', 'toFormattedDateString',
    'isoFormat', 'toISOString',
];

test('every reader-facing timestamp crosses the seam', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (['app', 'resources/views'] as $tree) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$tree));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = str_replace($root.'/', '', $file->getPathname());

            if (array_key_exists($relative, READER_CLOCK_EXEMPT)) {
                continue;
            }

            // Console commands and queued jobs render for nobody in
            // particular; a job that mails a named person passes them to the
            // seam explicitly, which this pattern already accepts.
            if (str_starts_with($relative, 'app/Console/') || str_starts_with($relative, 'app/Jobs/')) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                // `startsOn()`/`endsOn()` are ReportingWindow's own reader-clock
                // accessors, so a line using one has already answered the question.
                if (str_contains($line, 'ReaderClock')
                    || str_contains($line, 'startsOn()')
                    || str_contains($line, 'endsOn()')) {
                    continue;
                }

                foreach (array_keys(READER_CLOCK_EXEMPT_LINES) as $shape) {
                    if (str_contains($line, $shape)) {
                        continue 2;
                    }
                }

                // `->format('...')` with a real format, or a named Carbon
                // presenter. `format($variable)` is excluded: several of this
                // codebase's own formatter objects take that shape and have
                // nothing to do with dates.
                $formats = (bool) preg_match("/->format\(\s*'/", $line);
                $named = (bool) preg_match('/->('.implode('|', READER_CLOCK_CALLS).')\(/', $line);

                if (! $formats && ! $named) {
                    continue;
                }

                // An ISO string carries its own offset, so it is unambiguous
                // to a MACHINE. That is not a reason to print one at a person,
                // which is how `toISOString()` reached an agent's inbox. Only
                // the explicitly machine-bound spelling is waved through.
                if (str_contains($line, 'toIso8601String') || str_contains($line, 'Y-m-d\\TH')) {
                    continue;
                }

                $offenders[] = $relative.':'.($number + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These format a moment without saying whose clock it is on:',
        ...$offenders,
        '',
        'Either put it through ReaderClock::moment(), or add the file to',
        'READER_CLOCK_EXEMPT with the reason it belongs to someone else.',
    ]));
});

/**
 * A guard that has quietly stopped looking is worse than none, because it
 * reads as proof. This holds it to catching the shape it was written for.
 */
test('the guard still recognises an unconverted timestamp', function (): void {
    $line = "                'occurred_at' => \$event->occurred_at?->toDateTimeString() ?? '',";

    $named = (bool) preg_match('/->('.implode('|', READER_CLOCK_CALLS).')\(/', $line);

    expect($named)->toBeTrue('the real defect this guard was written for no longer matches')
        ->and(str_contains($line, 'ReaderClock'))->toBeFalse();

    // And that it does not fire on the codebase's own formatter objects, whose
    // `format($argument)` shape has nothing to do with dates.
    $formatter = '        $pressure = $this->transportPressure->format($metadata, $latestReport);';

    expect((bool) preg_match("/->format\(\s*'/", $formatter))->toBeFalse();

    // The two shapes the matcher was WIDENED for. Neither is checked by the
    // sweep above once the tree is clean -- a clean tree passes whether or not
    // the matcher still recognises them -- so they are asserted here or not at
    // all. Removing either from READER_CLOCK_CALLS must fail something.
    $widened = [
        // The seam's own spelling. A call site using it directly is invisible
        // to the `->format('` matcher by construction.
        "        return \$moment->isoFormat('ll LT');" => 'isoFormat',
        // What the alert digest printed into an agent's inbox: storage's
        // clock, in a machine format, in the middle of a sentence.
        '        return $timestamp?->toISOString();' => 'toISOString',
    ];

    foreach ($widened as $line => $call) {
        expect((bool) preg_match('/->('.implode('|', READER_CLOCK_CALLS).')\(/', $line))
            ->toBeTrue("the guard no longer recognises {$call}");
    }
});
