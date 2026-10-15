<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use RuntimeException;

/** Stable reason only; never chain a request, provider error or credential. */
final class AiNetworkException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The AI connection request is unavailable.');
    }
}
