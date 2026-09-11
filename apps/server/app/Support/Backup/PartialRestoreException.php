<?php

declare(strict_types=1);

namespace App\Support\Backup;

use RuntimeException;
use Throwable;

/**
 * Thrown when a restore fails AFTER destructive work has begun.
 *
 * The distinction exists because the advice differs completely. A restore that
 * refuses before touching anything — the ordinary "the target already holds
 * data, pass --force" case — leaves the install exactly as it was, and telling
 * that operator to go and verify their database contradicts the refusal they
 * just read. A restore that fails once the database load has started, or once
 * the attachment disks have been purged, may have applied only partially, and
 * that operator has real work to do before serving traffic.
 *
 * Only the second is this exception. It wraps the original rather than
 * replacing it, so the underlying cause is still reported.
 */
final class PartialRestoreException extends RuntimeException
{
    public static function from(Throwable $previous): self
    {
        return new self($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
