<?php

arch('application code avoids debugging helpers')
    ->expect('App')
    ->not->toUse(['die', 'dd', 'dump']);

arch('support models remain Eloquent models')
    ->expect('App\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

/**
 * A webhook signature is compared in constant time, or it is not compared.
 *
 * `hash_equals` and `===` return the same boolean for the same inputs, so no
 * functional test can tell them apart -- the difference is only in how long the
 * wrong answer takes, which is exactly the thing an attacker measures to
 * recover a signature one byte at a time.
 *
 * A rule rather than a test because the failure is invisible to testing, and it
 * covers every signed endpoint at once: the three provider webhooks and inbound
 * mail all verify a secret this way.
 */
test('every signature check compares in constant time', function (): void {
    $signed = [
        'app/Http/Controllers/Integrations/GitHubWebhookController.php',
        'app/Http/Controllers/Integrations/GitLabWebhookController.php',
        'app/Http/Controllers/Integrations/JiraWebhookController.php',
        'app/Http/Controllers/Mail/InboundMailController.php',
    ];

    foreach ($signed as $path) {
        // Architecture tests run without the application container, so the
        // path is resolved from this file rather than through base_path().
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        expect($source)->not->toBeFalse();

        // Comments are stripped before looking. The first version of this read
        // raw source, and the docblock in the file under test says the words
        // "hash_equals" -- so the rule was satisfied by prose explaining the
        // rule while the code beneath it used ===.
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        // If it computes an HMAC it is checking a signature, and that check has
        // to be timing-safe.
        if (! str_contains($code, 'hash_hmac')) {
            continue;
        }

        // One needle only. Pest reads further arguments to toContain() as
        // ADDITIONAL strings to find, not as a failure message -- so passing
        // prose here asserted that the file contains that prose, which no file
        // does. The same shape of mistake as toHaveKey($key, $message).
        expect($code)->toContain('hash_equals(');
    }
});
