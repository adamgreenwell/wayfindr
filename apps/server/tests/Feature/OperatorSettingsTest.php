<?php

// Operator settings foundation (ADR 0011 slice 1): DB-backed config that
// overrides env at runtime, with secrets encrypted at rest and only registered
// keys writable. The mail group is the first managed surface.

use App\Models\OperatorSetting;
use App\Support\Settings\OperatorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function settings(): OperatorSettings
{
    return app(OperatorSettings::class);
}

test('applyOverrides overrides config from stored settings', function (): void {
    config()->set('mail.default', 'log');
    config()->set('mail.mailers.smtp.host', '127.0.0.1');
    settings()->captureBaseline();

    settings()->set('mail.mailer', 'smtp');
    settings()->set('mail.host', 'smtp.example.com');

    settings()->applyOverrides();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com');
});

test('with no override stored, the env/config default stands', function (): void {
    config()->set('mail.mailers.smtp.host', '127.0.0.1');
    settings()->captureBaseline();

    settings()->applyOverrides();

    expect(config('mail.mailers.smtp.host'))->toBe('127.0.0.1');
});

test('a cleared override reverts config to the env baseline (not left stale on a worker)', function (): void {
    config()->set('mail.mailers.smtp.host', 'env-host');
    settings()->captureBaseline();

    settings()->set('mail.host', 'operator-host');
    settings()->applyOverrides();
    expect(config('mail.mailers.smtp.host'))->toBe('operator-host');

    // Operator clears the setting; a later request/job must restore env, not
    // keep the old override the way a plain skip would on a persistent worker.
    settings()->set('mail.host', null);
    settings()->applyOverrides();
    expect(config('mail.mailers.smtp.host'))->toBe('env-host');
});

test('applying settings forgets cached mailers so a mail config change takes effect', function (): void {
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp.host', 'old-host');
    settings()->captureBaseline();

    $first = Mail::mailer('smtp'); // built + cached with old-host

    settings()->set('mail.host', 'new-host');
    settings()->applyOverrides();

    // A freshly-built mailer (the manager was purged), so the next send uses the
    // new config rather than the stale cached transport.
    expect(Mail::mailer('smtp'))->not->toBe($first);
});

test('secret settings are encrypted at rest and decrypted on apply', function (): void {
    settings()->set('mail.password', 'super-secret');

    // Stored ciphertext, not the plaintext.
    $row = OperatorSetting::query()->where('key', 'mail.password')->firstOrFail();
    expect($row->value)->not->toBe('super-secret')
        ->and(Crypt::decryptString($row->value))->toBe('super-secret');

    // Applied as plaintext onto config.
    config()->set('mail.mailers.smtp.password', null);
    settings()->applyOverrides();
    expect(config('mail.mailers.smtp.password'))->toBe('super-secret');
});

test('isSet distinguishes an operator value from the env default; a secret is never echoed', function (): void {
    expect(settings()->isSet('mail.password'))->toBeFalse();

    settings()->set('mail.password', 'pw');

    expect(settings()->isSet('mail.password'))->toBeTrue()
        ->and(settings()->isSecret('mail.password'))->toBeTrue()
        ->and(settings()->isSecret('mail.host'))->toBeFalse();
});

test('effective returns the stored value when set, else the config default', function (): void {
    config()->set('mail.from.address', 'env@example.com');

    expect(settings()->effective('mail.from_address'))->toBe('env@example.com');

    settings()->set('mail.from_address', 'operator@example.com');

    expect(settings()->effective('mail.from_address'))->toBe('operator@example.com');
});

test('setting null clears the stored override', function (): void {
    settings()->set('mail.host', 'operator-host');
    expect(settings()->isSet('mail.host'))->toBeTrue();

    settings()->set('mail.host', null);

    expect(settings()->isSet('mail.host'))->toBeFalse();
});

test('an unregistered key cannot be read or written', function (): void {
    expect(fn () => settings()->set('app.debug', 'true'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => settings()->get('database.default'))
        ->toThrow(InvalidArgumentException::class);
});

test('writing a setting busts the cache so the change is live immediately', function (): void {
    // Prime the cache with the empty state.
    settings()->get('mail.host');

    settings()->set('mail.host', 'fresh-host');

    // No stale cache: the new value is visible without a manual cache clear.
    expect(settings()->get('mail.host'))->toBe('fresh-host');
});

test('the mail group lists its managed keys', function (): void {
    expect(settings()->keysForGroup('mail'))
        ->toContain('mail.host', 'mail.password', 'mail.from_address')
        ->not->toContain('database.default');
});
