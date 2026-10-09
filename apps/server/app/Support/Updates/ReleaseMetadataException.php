<?php

declare(strict_types=1);

namespace App\Support\Updates;

use RuntimeException;
use Throwable;

final class ReleaseMetadataException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
