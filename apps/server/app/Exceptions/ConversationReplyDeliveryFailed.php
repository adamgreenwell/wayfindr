<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;
use Throwable;

/**
 * What a reply delivery leaves in failed_jobs once its retries run out. A mail
 * server's refusal quotes the address it refused, and the row can land after
 * an erasure has swept the table, so only the error's class and code go there.
 * The job reports the original to the log first, which erasure does not reach
 * (ADR 0026 §6), so this one is not reported again.
 */
final class ConversationReplyDeliveryFailed extends RuntimeException implements ShouldntReport
{
    public static function after(int $deliveryId, Throwable $error): self
    {
        return new self(sprintf(
            'Conversation reply delivery %d failed with %s (code %s); the full error is in the application log.',
            $deliveryId,
            $error::class,
            $error->getCode(),
        ));
    }
}
