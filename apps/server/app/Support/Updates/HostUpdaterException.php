<?php

declare(strict_types=1);

namespace App\Support\Updates;

use RuntimeException;

/** Only a stable reason is exposed; transport paths and credentials stay private. */
final class HostUpdaterException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The host updater could not be verified ('.$reason.').');
    }
}
