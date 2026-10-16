<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use Carbon\CarbonImmutable;
use JsonSerializable;
use LogicException;

/** Exact server-only dispatch receipt, never a browser or queue payload. */
final readonly class AiUsageDispatch implements JsonSerializable
{
    public function __construct(
        public string $attemptId,
        public string $dispatchNonce,
        public int $accountId,
        public string $source,
        public string $connectionRevision,
        public string $purpose,
        public string $dispatchDay,
        public int $reservedTokens,
        public int $maxOutputTokens,
        public CarbonImmutable $dispatchedAt,
        public CarbonImmutable $expiresAt,
    ) {}

    public function __debugInfo(): array
    {
        return ['attempt_id' => $this->attemptId, 'dispatch_day' => $this->dispatchDay];
    }

    public function __serialize(): array
    {
        throw new LogicException('AI dispatch receipts cannot be serialized.');
    }

    public function jsonSerialize(): array
    {
        throw new LogicException('AI dispatch receipts cannot be serialized.');
    }
}
