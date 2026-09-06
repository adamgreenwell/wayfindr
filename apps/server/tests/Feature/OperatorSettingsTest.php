<?php

// Operator settings foundation (ADR 0011 slice 1): DB-backed config that
// overrides env at runtime, with secrets encrypted at rest and only registered
// keys writable. The mail group is the first managed surface.

use App\Models\OperatorSetting;
use App\Support\Settings\OperatorSettings;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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
    expect(config('mail.mailers.smtp.host'))->toBe('operator-host')
        ->and(settings()->valuesAreAuthoritative())->toBeTrue();

    // Operator clears the setting; a later request/job must restore env, not
    // keep the old override the way a plain skip would on a persistent worker.
    settings()->set('mail.host', null);
    settings()->applyOverrides();
    expect(config('mail.mailers.smtp.host'))->toBe('env-host')
        ->and(settings()->valuesAreAuthoritative())->toBeTrue();
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

test('a corrupt secret reverts only its own key to env, not half-applying the set', function (): void {
    config()->set('mail.mailers.smtp.host', 'env-host');
    config()->set('mail.mailers.smtp.password', 'env-pw');
    settings()->captureBaseline();

    settings()->set('mail.host', 'operator-host'); // a good override

    // An undecryptable secret written directly, bypassing set()'s encryption.
    OperatorSetting::query()->create(['key' => 'mail.password', 'value' => 'not-real-ciphertext']);

    settings()->applyOverrides();

    expect(config('mail.mailers.smtp.host'))->toBe('operator-host')  // good override still applies
        ->and(config('mail.mailers.smtp.password'))->toBe('env-pw') // corrupt secret → its env baseline
        ->and(settings()->hasUnreadableValue('mail.password'))->toBeTrue();

    settings()->set('mail.password', 'repaired-password');
    settings()->applyOverrides();

    expect(settings()->hasUnreadableValue('mail.password'))->toBeFalse();
});

test('when the settings store is unreadable, config falls back to the env baseline', function (): void {
    config()->set('mail.mailers.smtp.host', 'env-host');
    settings()->captureBaseline();

    settings()->set('mail.host', 'operator-host');
    settings()->applyOverrides();
    expect(config('mail.mailers.smtp.host'))->toBe('operator-host');

    // The store goes unreachable (DB down / table gone) with a cold cache: a
    // persistent worker must land the env baseline, not keep the stale override.
    Schema::drop('operator_settings');
    Cache::flush();

    settings()->applyOverrides();

    expect(config('mail.mailers.smtp.host'))->toBe('env-host')
        ->and(settings()->valuesAreAuthoritative())->toBeFalse();
});

test('configuring mail nulls the smtp url so an env MAIL_URL cannot supersede it', function (): void {
    config()->set('mail.mailers.smtp.url', 'smtp://env-user:env-pass@env-host:2525');
    config()->set('mail.mailers.smtp.host', 'env-host');
    settings()->captureBaseline();

    settings()->set('mail.host', 'operator-host');
    settings()->applyOverrides();

    // Laravel derives host/port/creds from the url when present; drop it so the
    // operator's individual fields take effect.
    expect(config('mail.mailers.smtp.host'))->toBe('operator-host')
        ->and(config('mail.mailers.smtp.url'))->toBeNull();
});

test('with no mail override, an env MAIL_URL is left intact', function (): void {
    config()->set('mail.mailers.smtp.url', 'smtp://env-host');
    settings()->captureBaseline();

    settings()->applyOverrides();

    expect(config('mail.mailers.smtp.url'))->toBe('smtp://env-host');
});

test('setting only a non-connection mail field leaves an env MAIL_URL intact', function (): void {
    // Only the sender name — not a connection field — so the url must stay, or
    // the SMTP transport would fall back to empty host/port and break.
    config()->set('mail.mailers.smtp.url', 'smtp://env-host');
    settings()->captureBaseline();

    settings()->set('mail.from_name', 'Acme Support');
    settings()->applyOverrides();

    expect(config('mail.mailers.smtp.url'))->toBe('smtp://env-host');
});

test('overriding one connection field on a MAIL_URL install keeps the url-derived values', function (): void {
    // MAIL_URL supplies host/port/credentials; the individual fields are their
    // empty defaults. Overriding just the host must not lose the url's user/pass/port.
    config()->set('mail.mailers.smtp.url', 'smtp://url-user:url-pass@url-host:2525');
    config()->set('mail.mailers.smtp.port', 25);          // differs from the url's port
    config()->set('mail.mailers.smtp.username', null);
    config()->set('mail.mailers.smtp.password', null);
    settings()->captureBaseline();

    settings()->set('mail.host', 'operator-host');
    settings()->applyOverrides();

    expect(config('mail.mailers.smtp.url'))->toBeNull()                 // url dropped
        ->and(config('mail.mailers.smtp.host'))->toBe('operator-host')  // operator's field
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)            // preserved from url
        ->and(config('mail.mailers.smtp.username'))->toBe('url-user')  // preserved from url
        ->and(config('mail.mailers.smtp.password'))->toBe('url-pass'); // preserved from url
});

test('a write inside a transaction defers the cache version bump until commit', function (): void {
    settings()->get('mail.host'); // prime the version-0 (empty) cache

    DB::transaction(function (): void {
        settings()->set('mail.host', 'txn-host');
        // The bump is deferred to commit, so mid-transaction the version still
        // points at the primed empty read — a concurrent reader can't cache the
        // uncommitted row under a bumped version.
        expect(settings()->get('mail.host'))->toBeNull();
    });

    // The deferred bump ran on commit: the value is now visible.
    expect(settings()->get('mail.host'))->toBe('txn-host');
});

test('a console command start applies operator overrides (so mail-test and scheduled mail see them)', function (): void {
    // The provider listens for CommandStarting in console — never firing during
    // config:cache serialization (a bootstrap that runs no command).
    config()->set('mail.mailers.smtp.host', 'env-host');
    settings()->captureBaseline();
    settings()->set('mail.host', 'command-host');

    event(new CommandStarting('some:command', new ArrayInput([]), new NullOutput));

    expect(config('mail.mailers.smtp.host'))->toBe('command-host');
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

test('a write invalidates the cache on a store whose increment does not auto-create', function (): void {
    // On the database/memcached cache stores, increment() on a missing key
    // returns false without creating it. The version must still advance so the
    // change is visible, not pinned to a stale cached read.
    config()->set('cache.default', 'database');

    settings()->get('mail.host');                    // prime the versioned cache
    settings()->set('mail.host', 'db-cache-host');   // must bump the version

    expect(settings()->get('mail.host'))->toBe('db-cache-host');
});

test('the mail group lists its managed keys', function (): void {
    expect(settings()->keysForGroup('mail'))
        ->toContain('mail.host', 'mail.password', 'mail.from_address')
        ->not->toContain('database.default');
});
