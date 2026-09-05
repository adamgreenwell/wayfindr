<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use InvalidArgumentException;

/** A public HTTPS destination could not be classified because DNS returned no answers. */
final class OutboundWebhookResolutionException extends InvalidArgumentException
{
    // Callers that validate configuration may still present this as invalid
    // input, while outbound transports can distinguish it from a deterministic
    // SSRF policy rejection and allow their queue retry policy to recover.
}
