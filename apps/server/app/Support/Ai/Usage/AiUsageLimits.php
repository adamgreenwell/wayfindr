<?php

declare(strict_types=1);

namespace App\Support\Ai\Usage;

use App\Support\Ai\Network\AiNetworkException;

/** Resolved numeric ceilings, not authority to make an external request. */
final readonly class AiUsageLimits
{
    /**
     * @param  array{requests: int, tokens: int, concurrency: int}  $installation
     * @param  array{requests: int, tokens: int, concurrency: int}  $account
     */
    public function __construct(
        public array $installation,
        public array $account,
        public int $maxOutputTokens,
        public int $queueTtlSeconds = 300,
        public int $claimTtlSeconds = 30,
        public int $dispatchTtlSeconds = 30,
        public int $retryCooldownSeconds = 60,
    ) {
        foreach ([$installation, $account] as $scope) {
            if (count($scope) !== 3 || array_diff(['requests', 'tokens', 'concurrency'], array_keys($scope)) !== []) {
                throw new AiNetworkException('usage_policy_unavailable');
            }
            foreach ($scope as $value) {
                if (! is_int($value) || $value < 0) {
                    throw new AiNetworkException('usage_policy_unavailable');
                }
            }
        }
        if ($maxOutputTokens < 0 || $queueTtlSeconds < 1 || $claimTtlSeconds < 21 || $dispatchTtlSeconds < 21 || $retryCooldownSeconds < 60) {
            throw new AiNetworkException('usage_policy_unavailable');
        }
    }
}
