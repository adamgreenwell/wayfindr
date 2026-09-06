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
    $firstPass = $sanitizer->sanitize('The final value is token=visitor-secret');
    $encoded = json_encode(['body' => $firstPass], JSON_THROW_ON_ERROR);
    $secondPass = $sanitizer->sanitize($encoded);

    expect(json_decode($secondPass, true, flags: JSON_THROW_ON_ERROR))->toBe([
        'body' => 'The final value is token=[REDACTED]',
    ]);
});
