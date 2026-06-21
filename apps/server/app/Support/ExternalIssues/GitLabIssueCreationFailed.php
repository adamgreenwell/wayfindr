<?php

namespace App\Support\ExternalIssues;

use RuntimeException;

class GitLabIssueCreationFailed extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }

    public function status(): ?int
    {
        return $this->status;
    }
}
