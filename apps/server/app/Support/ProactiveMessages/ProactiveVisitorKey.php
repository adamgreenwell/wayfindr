<?php

declare(strict_types=1);

namespace App\Support\ProactiveMessages;

use LogicException;

/** A stable, non-public cap key that does not retain the browser's raw ID. */
final class ProactiveVisitorKey
{
    public static function for(int $siteId, string $anonymousId): string
    {
        $secret = config('app.key');

        if (! is_string($secret) || $secret === '') {
            throw new LogicException('An application key is required to derive proactive-message visitor keys.');
        }

        return hash_hmac('sha256', $siteId."\0".$anonymousId, $secret);
    }
}
