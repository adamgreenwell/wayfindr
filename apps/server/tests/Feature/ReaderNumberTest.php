<?php

declare(strict_types=1);

use App\Support\DashboardLanguage;
use App\Support\ReaderNumber;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;

/**
 * Assertions here name the SEPARATOR and the digit order, never a whole
 * rendered string containing a space.
 *
 * German percent is `62,5` + U+00A0 + `%` -- a non-breaking space, not the one
 * on the keyboard -- and the ICU version is not pinned across CI, the
 * self-hosting image and a Forge host. An assertion typed with U+0020 fails on
 * a machine that is behaving correctly, and the next person loosens it until
 * it tests nothing.
 */
it('groups and points a number the way the reader reads it', function () {
    App::setLocale('en');
    expect(ReaderNumber::count(4213))->toBe('4,213')
        ->and(ReaderNumber::decimal(1234.56, 2))->toBe('1,234.56');

    App::setLocale('de');
    expect(ReaderNumber::count(4213))->toBe('4.213')
        ->and(ReaderNumber::decimal(1234.56, 2))->toBe('1.234,56');

    App::setLocale('it');
    expect(ReaderNumber::count(4213))->toBe('4.213')
        ->and(ReaderNumber::decimal(1234.56, 2))->toBe('1.234,56');
});

it('spaces the percent sign the way the locale does', function () {
    App::setLocale('en');
    expect(ReaderNumber::percentage(62.5, 1))->toBe('62.5%');

    App::setLocale('it');
    expect(ReaderNumber::percentage(62.5, 1))->toBe('62,5%');

    // German puts a NARROW gap before the sign. Asserted on the codepoint,
    // because it is invisible in a diff and a plain space would be wrong.
    App::setLocale('de');
    $german = ReaderNumber::percentage(62.5, 1);

    expect(str_starts_with($german, '62,5'))->toBeTrue()
        ->and(str_ends_with($german, '%'))->toBeTrue()
        ->and(str_contains($german, "\u{00A0}"))->toBeTrue('German spaces the percent sign, and not with U+0020');
});

/**
 * The seam takes no reader, and that is what makes it inert where the page is
 * deliberately English. An unextracted route resolves to the fallback locale,
 * so numbers there stay English without any call site knowing it.
 */
it('follows the page, so an English page keeps English numbers', function () {
    App::setLocale('de');
    expect(ReaderNumber::count(4213))->toBe('4.213');

    App::setLocale(DashboardLanguage::FALLBACK);
    expect(ReaderNumber::count(4213))->toBe('4,213');
});

it('leaves the process locale alone', function () {
    // `Number::useLocale()` would be a process-global that nothing keeps in
    // step with the request, and it would leak between requests on a
    // persistent worker. Per-call is the whole reason this is safe.
    App::setLocale('de');

    expect(ReaderNumber::count(1))->toBe('1')
        ->and(App::getLocale())->toBe('de')
        ->and(Number::format(4213))->toBe('4,213', 'the framework default must not have moved');
});
