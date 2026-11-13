<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

use App\Support\Ai\AiContextSanitizer;
use App\Support\Ai\Network\AiNetworkException;
use SensitiveParameter;

/** Opaque source labels must survive the same whole-payload scrub as source prose. */
final readonly class GroundingSourceReference
{
    public function __construct(private AiContextSanitizer $sanitizer) {}

    public function forDigest(#[SensitiveParameter] string $digest): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
            throw new AiNetworkException('execution_identity_invalid');
        }
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $reference = 'src-'.substr($digest, 0, 24);
            if ($this->sanitizer->sanitize($reference) === $reference) {
                return $reference;
            }
            // The seed is already a scoped server HMAC. Keep all 96 label bits,
            // the existing format and deterministic revalidation; do not weaken scrubbing.
            $digest = hash('sha256', 'grounding-reference-v1:'.$attempt.':'.$digest);
        }
        throw new AiNetworkException('execution_identity_invalid');
    }
}
