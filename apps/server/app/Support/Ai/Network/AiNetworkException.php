<?php

declare(strict_types=1);

namespace App\Support\Ai\Network;

use RuntimeException;
use SensitiveParameter;

/** Stable reason only; never chain a request, provider error or credential. */
final class AiNetworkException extends RuntimeException
{
    public function __construct(#[SensitiveParameter] public readonly string $reason, public readonly ?int $httpStatus = null, public readonly ?int $retryAfterSeconds = null, public readonly ?int $retryAtUnix = null)
    {
        parent::__construct('The AI connection request is unavailable.');
    }
}
