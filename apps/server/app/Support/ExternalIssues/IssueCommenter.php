<?php

namespace App\Support\ExternalIssues;

use App\Models\ExternalIssueProviderConnection;
use App\Models\TicketExternalLink;

/**
 * Posts an agent's internal note as a comment on the external issue a ticket is
 * linked to. One implementation per provider; the controller resolves the right
 * one by the link's provider.
 */
interface IssueCommenter
{
    /**
     * @return array{url: string|null}
     *
     * @throws ExternalIssueCommentFailed
     */
    public function comment(ExternalIssueProviderConnection $connection, TicketExternalLink $link, string $body): array;
}
