<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

class CobrowseResyncRequestPolicy
{
    public const DELAYED_AFTER_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $request
     */
    public function isFreshPending(array $request): bool
    {
        $requestedAt = $this->requestedAt($request);

        return $this->isPending($request)
            && $requestedAt
            && $requestedAt->gte(now()->subSeconds(self::DELAYED_AFTER_SECONDS));
    }

    /**
     * @param  array<string, mixed>  $request
     */
    public function isDelayedPending(array $request): bool
    {
        $requestedAt = $this->requestedAt($request);

        return $this->isPending($request)
            && $requestedAt
            && $requestedAt->lt(now()->subSeconds(self::DELAYED_AFTER_SECONDS));
    }

    /**
     * @param  array<string, mixed>  $request
     */
    public function isPending(array $request): bool
    {
        return blank($request['fulfilled_at'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $request
     */
    public function requestedAt(array $request): ?Carbon
    {
        if (! filled($request['requested_at'] ?? null)) {
            return null;
        }

        try {
            return Carbon::parse((string) $request['requested_at']);
        } catch (Throwable) {
            return null;
        }
    }
}
