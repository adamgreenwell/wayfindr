<?php

namespace App\Support\ExternalIssues;

use App\Models\ExternalIssueProviderConnection;
use App\Models\TicketExternalLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts an agent's internal note to the GitHub issue a ticket is linked to.
 * Mirrors GitHubIssueCreator's auth/endpoint conventions, but targets the
 * existing issue anchored by the TicketExternalLink rather than a project.
 */
class GitHubIssueCommenter implements IssueCommenter
{
    /**
     * @return array{url: string|null, id: string|null}
     */
    public function comment(ExternalIssueProviderConnection $connection, TicketExternalLink $link, string $body): array
    {
        $token = data_get($connection->credentials, 'token');

        if (! is_string($token) || trim($token) === '') {
            throw new ExternalIssueCommentFailed('GitHub token is missing.', 'GitHub');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post($this->commentsEndpoint($connection, $link), [
                    'body' => $body,
                ]);
        } catch (ConnectionException) {
            throw new ExternalIssueCommentFailed('GitHub request failed before a response was received.', 'GitHub');
        }

        if (! $response->successful()) {
            throw new ExternalIssueCommentFailed('GitHub comment failed.', 'GitHub', $response->status());
        }

        $url = data_get($response->json(), 'html_url');
        $id = data_get($response->json(), 'id');

        return [
            'url' => is_string($url) && trim($url) !== '' ? $url : null,
            'id' => filled($id) ? (string) $id : null,
        ];
    }

    private function commentsEndpoint(ExternalIssueProviderConnection $connection, TicketExternalLink $link): string
    {
        $parts = explode('/', (string) $link->project_key);
        $number = ltrim((string) $link->external_key, '#');

        if (count($parts) !== 2 || blank($parts[0]) || blank($parts[1]) || ! ctype_digit($number)) {
            throw new ExternalIssueCommentFailed('GitHub issue reference is incomplete (need owner/repository and an issue number).', 'GitHub');
        }

        $baseUrl = rtrim($connection->base_url ?: 'https://api.github.com', '/');

        return $baseUrl.'/repos/'.rawurlencode($parts[0]).'/'.rawurlencode($parts[1]).'/issues/'.$number.'/comments';
    }
}
