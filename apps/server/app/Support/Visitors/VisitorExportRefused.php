<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use RuntimeException;

/**
 * An export that was not produced, for a reason the agent can act on: the
 * contact went while it was being read, or holds more than one archive can.
 */
final class VisitorExportRefused extends RuntimeException
{
    public const GONE = 'gone';

    public const TOO_LARGE = 'too_large';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("The contact's export was refused: {$reason}.");
    }
}
