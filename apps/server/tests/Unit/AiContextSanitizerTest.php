<?php

declare(strict_types=1);

use App\Support\Ai\AiContextSanitizer;

test('copilot context removes common credentials and identifiers', function (): void {
    $input = <<<'TEXT'
    Contact Ada at ada@example.test, phone: +1 (555) 867-5309.
    Authorization: Bearer abcdefghijklmnop==
    Opaque header Bearer abcdefghijklmnop==
    api_key=sk-examplecredential123456
    Visitor 019c1234-abcd-4abc-8abc-0123456789ab from 192.168.10.24 or 2001:db8::1.
    Visitor IP 2001:db8::5: request failed.
    Internal trace https://[2001:db8:85a3::8a2e:370:7334]/span?token=secret.
    Malformed trace https://operator:odd-password@999.999.999.999:99999/path?session=abc123xyz#private.
    Card 4242 4242 4242 4242.
    See https://support.example.test/ticket/4?token=secret#private.
    -----BEGIN PRIVATE KEY-----
    extremely-secret-material
    -----END PRIVATE KEY-----
    TEXT;

    $sanitized = (new AiContextSanitizer)->sanitize($input);

    expect($sanitized)
        ->toContain('[EMAIL REDACTED]')
        ->toContain('phone=[PHONE REDACTED]')
        ->toContain('Bearer [REDACTED]')
        ->toContain('api_key=[REDACTED]')
        ->toContain('[IDENTIFIER REDACTED]')
        ->toContain('[IP ADDRESS REDACTED]')
        ->toContain('[PAYMENT CARD REDACTED]')
        ->toContain('https://support.example.test/ticket/4')
        ->toContain('https://[IP ADDRESS REDACTED]:99999/path')
        ->toContain('[PRIVATE KEY REDACTED]')
        ->not->toContain('ada@example.test')
        ->not->toContain('2001:db8')
        ->not->toContain('abc123xyz')
        ->not->toContain('odd-password')
        ->not->toContain('operator:')
        ->not->toContain('secret#private')
        ->not->toContain('extremely-secret-material');
});

test('ordinary numeric support context is preserved', function (): void {
    $input = 'The visitor retried order 123456 on step 3 and received status 422.';

    expect((new AiContextSanitizer)->sanitize($input))->toBe($input);
});

test('an unterminated private key block is redacted through the end of the bounded input', function (): void {
    $input = "Visible support context.\n-----BEGIN PRIVATE KEY-----\n".str_repeat('private-key-material', 500);

    expect((new AiContextSanitizer)->sanitize($input))
        ->toBe("Visible support context.\n[PRIVATE KEY REDACTED]")
        ->not->toContain('private-key-material');
});

test('scrubbing an already scrubbed json prompt preserves its structure', function (): void {
    $sanitizer = new AiContextSanitizer;
    $firstPass = $sanitizer->sanitize('Credentials are {"token":"visitor-secret","api_key":"provider-secret"}, password=\'single-secret\', plus secret="provider-\\"quoted\\"-secret".');
    $encoded = json_encode(['body' => $firstPass], JSON_THROW_ON_ERROR);
    $secondPass = $sanitizer->sanitize($encoded);

    expect(json_decode($secondPass, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'body' => 'Credentials are {"token":"[REDACTED]","api_key":"[REDACTED]"}, password=\'[REDACTED]\', plus secret="[REDACTED]".',
    ])
        ->and($secondPass)->not->toContain('visitor-secret')
        ->and($secondPass)->not->toContain('provider-secret')
        ->and($secondPass)->not->toContain('single-secret')
        ->and($secondPass)->not->toContain('quoted');
});

test('quoted json credentials remain valid json after scrubbing', function (): void {
    $sanitized = (new AiContextSanitizer)->sanitize('{"token":"visitor-secret","api_key":"provider-secret"}');

    expect(json_decode($sanitized, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'token' => '[REDACTED]',
        'api_key' => '[REDACTED]',
    ]);
});

test('an unterminated quoted credential is redacted through the end of the bounded input', function (): void {
    $input = '{"token":"'.str_repeat("bounded-secret-material\n", 500);

    expect((new AiContextSanitizer)->sanitize($input))
        ->toBe('{"token":"[REDACTED]"')
        ->not->toContain('bounded-secret-material');
});

test('quoted credentials redact escaped newlines and a trailing escape', function (): void {
    $input = "token=\"first-secret\\\nsecond-secret\"\n".'password="trailing-secret'.'\\';

    expect((new AiContextSanitizer)->sanitize($input))
        ->toBe("token=\"[REDACTED]\"\npassword=\"[REDACTED]\"")
        ->not->toContain('first-secret')
        ->not->toContain('second-secret')
        ->not->toContain('trailing-secret');
});

test('serialized json credential fragments are scrubbed without breaking their escaping', function (): void {
    $sanitizer = new AiContextSanitizer;
    $input = <<<'TEXT'
    {\"token\":\"first-secret\\\"second-secret\",\"api_key\":\"provider-secret\"}
    TEXT;
    $firstPass = $sanitizer->sanitize($input);
    $encoded = json_encode(['body' => $firstPass], JSON_THROW_ON_ERROR);
    $secondPass = $sanitizer->sanitize($encoded);
    $truncated = $sanitizer->sanitize('{\\"token\\":\\"'.str_repeat('bounded-secret', 500));

    expect($firstPass)->toBe('{\"token\":\"[REDACTED]\",\"api_key\":\"[REDACTED]\"}')
        ->and(json_decode($secondPass, true, flags: JSON_THROW_ON_ERROR))->toBe(['body' => $firstPass])
        ->and($truncated)->toBe('{\\"token\\":\\"[REDACTED]\\"')
        ->and($secondPass)->not->toContain('first-secret')
        ->and($secondPass)->not->toContain('second-secret')
        ->and($secondPass)->not->toContain('provider-secret')
        ->and($truncated)->not->toContain('bounded-secret');
});

test('nested serialized credentials ending in backslashes preserve following fields', function (): void {
    $serialized = json_encode(['token' => 'first"second\\', 'message' => 'keep'], JSON_THROW_ON_ERROR);
    $expectedRaw = json_encode(['token' => '[REDACTED]', 'message' => 'keep'], JSON_THROW_ON_ERROR);
    $expected = substr(json_encode($expectedRaw, JSON_THROW_ON_ERROR), 1, -1);

    for ($depth = 1; $depth <= 3; $depth++) {
        $serialized = substr(json_encode($serialized, JSON_THROW_ON_ERROR), 1, -1);
        $sanitized = (new AiContextSanitizer)->sanitize($serialized);

        expect($sanitized)
            ->toBe($expected)
            ->toContain('message')
            ->toContain('keep')
            ->not->toContain('first')
            ->not->toContain('second');

        $expected = substr(json_encode($expected, JSON_THROW_ON_ERROR), 1, -1);
    }
});

test('serialized single-quoted credentials use their enclosing escape depth', function (): void {
    $serialized = json_encode(['body' => "token='first\\'second-secret'", 'message' => 'keep'], JSON_THROW_ON_ERROR);
    $expected = json_encode(['body' => "token='[REDACTED]'", 'message' => 'keep'], JSON_THROW_ON_ERROR);

    for ($depth = 1; $depth <= 3; $depth++) {
        $serialized = substr(json_encode($serialized, JSON_THROW_ON_ERROR), 1, -1);
        $expected = substr(json_encode($expected, JSON_THROW_ON_ERROR), 1, -1);
        $sanitized = (new AiContextSanitizer)->sanitize($serialized);

        expect($sanitized)
            ->toBe($expected)
            ->toContain('message')
            ->toContain('keep')
            ->not->toContain('first')
            ->not->toContain('second-secret');
    }
});

test('a completed earlier quote does not change later single-quote escaping', function (): void {
    $input = "\"past quote\" token='secret\\\\', message=keep";

    expect((new AiContextSanitizer)->sanitize($input))
        ->toBe('"past quote" token=\'[REDACTED]\', message=keep');
});
