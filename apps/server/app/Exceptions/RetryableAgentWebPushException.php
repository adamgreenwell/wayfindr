<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Queue retry signal emitted only after all Web Push reports are handled. */
final class RetryableAgentWebPushException extends RuntimeException
{
    // Distinct from an unexpected delivery exception so the listener can
    // commit expired-subscription cleanup before surfacing this to the queue.
}
