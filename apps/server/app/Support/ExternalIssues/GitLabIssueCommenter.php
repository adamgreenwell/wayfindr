<?php

namespace App\Support\ExternalIssues;

use App\Models\ExternalIssueProviderConnection;
use App\Models\TicketExternalLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Posts an agent's internal note as a GitLab issue note. Mirrors
 * GitLabIssueCreator's auth (PRIVATE-TOKEN) and base-URL handling, but targets
 * the existing issue (by project path + IID) anchored on the TicketExternalLink.
 */
class GitLabIssueCommenter implements IssueCommenter
{
    /**
     * @return array{url: string|null, id: string|null}
     */
    public function comment(ExternalIssueProviderConnection $connection, TicketExternalLink $link, string $body): array
    {
        $token = data_get($connection->credentials, 'token');

        if (! is_string($token) || trim($token) === '') {
            throw new ExternalIssueCommentFailed('GitLab token is missing.', 'GitLab');
        }

        try {
            $response = Http::withHeaders(['PRIVATE-TOKEN' => $token])
                ->post($this->notesEndpoint($connection, $link), [
                    'body' => $body,
                ]);
        } catch (ConnectionException) {
            throw new ExternalIssueCommentFailed('GitLab request failed before a response was received.', 'GitLab');
        }

        if (! $response->successful()) {
            throw new ExternalIssueCommentFailed('GitLab comment failed.', 'GitLab', $response->status());
        }

        // The note payload has no stable public URL; fall back to the issue link.
        $id = data_get($response->json(), 'id');

        return [
            'url' => null,
            'id' => filled($id) ? (string) $id : null,
        ];
    }

    private function notesEndpoint(ExternalIssueProviderConnection $connection, TicketExternalLink $link): string
    {
        $projectKey = trim((string) $link->project_key);
        $iid = ltrim((string) $link->external_key, '#');

        if ($projectKey === '' || ! ctype_digit($iid)) {
            throw new ExternalIssueCommentFailed('GitLab issue reference is incomplete (need a project and issue IID).', 'GitLab');
        }

        return $this->apiBaseUrl($connection).'/projects/'.rawurlencode($projectKey).'/issues/'.$iid.'/notes';
    }

    private function apiBaseUrl(ExternalIssueProviderConnection $connection): string
    {
        $baseUrl = trim((string) ($connection->base_url ?: 'https://gitlab.com'));

        if ($baseUrl === '') {
            $baseUrl = 'https://gitlab.com';
        }

        $baseUrl = rtrim($baseUrl, '/');

        if (! str_ends_with($baseUrl, '/api/v4')) {
            $baseUrl .= '/api/v4';
        }

        return $baseUrl;
    }
}
