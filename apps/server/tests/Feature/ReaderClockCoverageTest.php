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
];

/** Formatting calls that produce a moment for someone to read. */
const READER_CLOCK_CALLS = [
    'toDateTimeString', 'toDayDateTimeString', 'toFormattedDayDateString',
    'toDateString', 'toTimeString', 'toFormattedDateString',
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

                // An ISO string is machine-readable and carries its own offset.
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
});
