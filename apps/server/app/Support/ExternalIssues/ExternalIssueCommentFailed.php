<?php

namespace App\Support\ExternalIssues;

use RuntimeException;

/**
 * Shared failure for outbound comment relay across providers (the per-provider
 * commenters throw this rather than one exception class each). Carries the
 * provider label and any HTTP status so the ticket activity and agent-facing
 * guidance can explain what happened without leaking the note body.
 */
class ExternalIssueCommentFailed extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $providerLabel = 'External issue',
        private readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function providerLabel(): string
    {
        return $this->providerLabel;
    }

    public function status(): ?int
    {
        return $this->status;
    }
}
