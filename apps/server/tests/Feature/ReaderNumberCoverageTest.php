<?php

declare(strict_types=1);

/**
 * Where a number is allowed to be formatted for a reader, and where it is not.
 *
 * Modelled on `ReaderClockCoverageTest`, for the same reason: getting this
 * wrong looks like nothing. A separator in the wrong convention is a plausible
 * number at both readings, and a separator in the WRONG PLACE is a value some
 * script silently reparses as something else.
 *
 * Two guards, pulling in opposite directions:
 *
 * 1. Every `number_format()` left in the tree either moves to the seam or is
 *    named below with whose number it is instead.
 * 2. No `style` attribute may contain the seam at all -- the one place in this
 *    dashboard where a number is a layout instruction rather than prose.
 */

/**
 * Files whose numbers deliberately do NOT follow the reader's language.
 *
 * @var array<string, string>
 */
const READER_NUMBER_EXEMPT = [
    // CSV. The cells are reparsed by whatever spreadsheet opens them, under
    // ITS locale, so a localized `1.234` silently becomes one-point-two-three-
    // four; the headers are column names downstream scripts key on.
    'app/Http/Controllers/AgentReportController.php' => 'CSV cells and headers',
    'app/Http/Controllers/AgentAccountAuditController.php' => 'CSV cells and headers',

    // English sentences assembled inside a model, which is a copy problem
    // rather than a number one -- and models hand out state, they do not
    // translate. They belong to the extraction slice, not this one.
    'app/Support/CobrowsePayloadBudget.php' => 'English sentences awaiting extraction',
    // NOTE: this file also held three counts rendered on their own rather than
    // inside a sentence, and a whole-file exemption is what let them keep an
    // en-US separator on a German page. They now hand out `*_value` and the
    // view formats them. An exemption that covers more than it means to is a
    // guard that has stopped checking.
    'app/Support/CobrowseConsentState.php' => 'English sentences awaiting extraction',
    'app/Support/CobrowsePressureSentence.php' => 'English sentences awaiting extraction',
    'app/Support/CobrowseTransportPressure.php' => 'English sentences awaiting extraction',
    'app/Support/CobrowseReplayPreview.php' => 'English sentences awaiting extraction',
    'app/Support/CobrowseReplayDrift.php' => 'English sentences awaiting extraction',

    // Shared with the widget. This service backs both the agent composer and
    // the visitor upload, so localizing it for the agent localizes it for the
    // visitor -- whose language is the widget's own business, and out of scope
    // for #795.
    'app/Support/Attachments/AttachmentUploadService.php' => "shared with the visitor's widget",
];

test('every reader-facing number crosses the seam', function (): void {
    $root = dirname(__DIR__, 2);
    $offenders = [];

    foreach (['app', 'resources/views'] as $tree) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$tree));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = str_replace($root.'/', '', $file->getPathname());

            if (array_key_exists($relative, READER_NUMBER_EXEMPT)) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                if (! str_contains($line, 'number_format(')) {
                    continue;
                }

                // A docblock naming the call is not making it. Without this
                // the seam's own explanation of what it replaces reports
                // itself, and the honest response would be to stop saying it.
                $trimmed = ltrim($line);

                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#')) {
                    continue;
                }

                $offenders[] = $relative.':'.($number + 1).'  '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These format a number without saying whose conventions it follows:',
        ...$offenders,
        '',
        'Either use ReaderNumber, or add the file to READER_NUMBER_EXEMPT with',
        'the reason its numbers are not the reader\'s.',
    ]));
});

/**
 * The crux, and the only failure in this whole slice that is invisible.
 *
 * `resources/views/agent/reports/index.blade.php` writes chart bars as
 * `style="height: {{ round(...) }}%"`, four lines from a percentage that is
 * genuinely prose. Localize the first and it emits `height: 33,33%` -- not an
 * error, an INVALID DECLARATION. The browser drops it, both charts flatten to
 * nothing, and no test in the suite goes red.
 *
 * The tell has nothing to do with the value: a number inside a `style`
 * attribute is CSS, however it ends.
 */
test('no number inside a style attribute follows the reader', function (): void {
    $root = dirname(__DIR__, 2).'/resources/views';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (! str_contains($line, 'style="') || ! str_contains($line, 'ReaderNumber')) {
                continue;
            }

            $offenders[] = str_replace(dirname($root, 2).'/', '', $file->getPathname())
                .':'.($number + 1).'  '.trim($line);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A number inside a `style` attribute is CSS, however it ends:',
        ...$offenders,
        '',
        'A decimal comma makes the declaration invalid, the browser drops it,',
        'and the element silently collapses. Leave these as raw values.',
    ]));
});

/**
 * The client half. `toLocaleString()` with no argument follows the BROWSER.
 *
 * That is two bugs in one shape. A German agent on an en-US browser reads
 * German dates beside American numbers; and because these run on the
 * live-update path, the server paints `4.213` and the first websocket message
 * rewrites the same node as `4,213` with no data change behind it -- which
 * would have silently undone the server-side seam within seconds of anyone
 * looking at the page.
 */
test('no number is formatted for the browser rather than the agent', function (): void {
    $root = dirname(__DIR__, 2).'/resources/views';
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (! str_contains($line, 'toLocaleString()')) {
                continue;
            }

            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                continue;
            }

            $offenders[] = str_replace(dirname($root, 2).'/', '', $file->getPathname())
                .':'.($number + 1).'  '.trim($line);
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These format a number for whatever locale the BROWSER happens to have:',
        ...$offenders,
        '',
        'Pass the agent\'s locale. `realtimeLabels.locale` is already shipped to',
        'the page for Intl.RelativeTimeFormat.',
    ]));
});

/**
 * A guard that has stopped recognising its own defect is worse than none.
 */
test('the guards still recognise what they were written for', function (): void {
    $unformatted = "    'count' => number_format(\$snapshot['node_count_value']),";
    $cssBar = '    <div class="bar" style="height: {{ \App\Support\ReaderNumber::percentage($x) }}%"></div>';

    expect(str_contains($unformatted, 'number_format('))->toBeTrue()
        ->and(str_contains($cssBar, 'style="') && str_contains($cssBar, 'ReaderNumber'))->toBeTrue();

    // And that the CSS guard does not fire on the prose percentage sitting
    // four lines from it in the same view.
    $prose = '    <span class="stat">{{ \App\Support\ReaderNumber::percentage($satisfaction, 1) }}</span>';

    expect(str_contains($prose, 'style="'))->toBeFalse();
});
