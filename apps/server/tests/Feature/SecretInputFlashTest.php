<?php

// On a validation failure Laravel flashes the request's input into the session
// as old input: in plaintext, into whatever the session driver writes, which is
// the database by default. A secret typed into a form must never go there, so
// the exception handler's `dontFlash` list has to name every one.
//
// That list is kept by hand, and it drifted. The provider connection form's
// `credential_token` and `webhook_secret` were missing while the comment above
// the list said integration secrets were covered, so a typo in a connection's
// base URL wrote the provider token to the sessions table. The property is held
// here instead of the list: every password field a view renders is on it.

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

/** @return list<string> */
function secretInputFlashExcluded(): array
{
    $handler = app(ExceptionHandler::class);

    return (new ReflectionProperty($handler, 'dontFlash'))->getValue($handler);
}

/**
 * Every password input in the views, by name, with the files that render it.
 *
 * A Blade echo is matched as one unit: `{{ $connection->id }}` carries a `>`,
 * and a plain `[^>]*` stops there -- before the name of the per-connection
 * secret field, which is how a grep for this missed it.
 *
 * @return array<string, list<string>>
 */
function secretInputFlashPasswordFields(): array
{
    $fields = [];

    foreach ((new Finder)->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
        preg_match_all('/<input\b(?:\{\{.*?\}\}|\{!!.*?!!\}|[^>])*>/s', $file->getContents(), $inputs);

        foreach ($inputs[0] as $input) {
            if (preg_match('/\btype="password"/', $input) !== 1) {
                continue;
            }

            expect(preg_match('/\bname="([a-z0-9_]+)"/', $input, $name))
                ->toBe(1, "a password field in {$file->getRelativePathname()} has no literal name, so nothing can check it is kept out of the flash: {$input}");

            $fields[$name[1]][] = $file->getRelativePathname();
        }
    }

    return $fields;
}

test('every password field a view renders is kept out of the old-input flash', function (): void {
    $fields = secretInputFlashPasswordFields();

    // Known fields, so a pattern that stopped matching fails here rather than
    // passing with nothing checked. The second is the one behind a Blade echo.
    expect(array_key_exists('client_secret', $fields))->toBeTrue('the sweep no longer finds the security page secret; it is checking nothing')
        ->and(count($fields['webhook_secret'] ?? []))->toBe(2, 'the sweep no longer finds both webhook secret fields on the integrations page');

    $excluded = secretInputFlashExcluded();

    foreach ($fields as $name => $files) {
        expect(in_array($name, $excluded, true))
            ->toBeTrue("`{$name}` (".implode(', ', array_unique($files)).') is a password field, but a failed form would flash it into the session in plaintext -- add it to dontFlash in bootstrap/app.php');
    }
});

test('a failed provider connection form does not flash its token or webhook secret', function (): void {
    $admin = User::factory()->for(Account::factory())->create(['account_role' => AccountRole::Admin]);

    $this->actingAs($admin)
        ->from(route('dashboard.account.integrations'))
        ->post(route('dashboard.external-issue-provider-connections.store'), [
            'return_to' => 'integrations',
            'provider' => 'github',
            'name' => 'Engineering',
            'base_url' => 'not a url',
            'credential_token' => 'ghp_must_never_reach_the_session',
            'webhook_secret' => 'whsec_must_never_reach_the_session',
        ])
        ->assertRedirect(route('dashboard.account.integrations'));

    $old = session('_old_input');

    // The rest of the form still comes back, so the fix is not "flash nothing".
    expect($old['name'] ?? null)->toBe('Engineering', 'the form no longer keeps what was typed')
        ->and(array_key_exists('credential_token', $old))->toBeFalse('the provider token was flashed into the session')
        ->and(array_key_exists('webhook_secret', $old))->toBeFalse('the webhook secret was flashed into the session');
});
