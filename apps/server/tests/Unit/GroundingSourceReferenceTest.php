<?php

declare(strict_types=1);

use App\Support\Ai\AiContextSanitizer;
use App\Support\Ai\Grounding\GroundingSourceReference;
use App\Support\Ai\Network\AiNetworkException;
use Tests\TestCase;

uses(TestCase::class);

test('source references that resemble payment cards survive whole-payload scrubbing and remain deterministic', function (string $prefix): void {
    $digest = str_pad($prefix.'abcdef', 64, 'a');
    $unsafe = 'src-'.substr($digest, 0, 24);
    $scrubber = app(AiContextSanitizer::class);
    expect($scrubber->sanitize($unsafe))->not->toBe($unsafe);
    $reference = app(GroundingSourceReference::class)->forDigest($digest);
    expect($reference)->toMatch('/\Asrc-[a-f0-9]{24}\z/')->not->toBe($unsafe)
        ->and($scrubber->sanitize($reference))->toBe($reference)
        ->and(app(GroundingSourceReference::class)->forDigest($digest))->toBe($reference);
})->with(['4111111111111111', '5555555555554444', '378282246310005', '4222222222222']);

test('already scrub-stable source references retain their existing identity', function (): void {
    $digest = str_repeat('abcdef', 10).'abcd';
    expect(app(GroundingSourceReference::class)->forDigest($digest))->toBe('src-'.substr($digest, 0, 24));
});

test('source reference construction refuses invalid server digests', function (string $digest): void {
    expect(fn () => app(GroundingSourceReference::class)->forDigest($digest))->toThrow(AiNetworkException::class);
})->with(['', str_repeat('a', 63), str_repeat('g', 64), str_repeat('A', 64)]);
