<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use Carbon\CarbonImmutable;

/** Content-free receipt; a cached receipt is never permission to dispatch. */
final readonly class AiUsageReservation
{
    public function __construct(
        public string $attemptId,
        public int $accountId,
        public string $source,
        public string $connectionRevision,
        public string $purpose,
        public string $workFingerprint,
        public string $state,
        public string $reservedDay,
        public int $reservedTokens,
        public int $maxOutputTokens,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $retryNotBefore,
        public bool $reused = false,
    ) {}
}
