<?php

namespace App\Support\ExternalIssues;

use App\Models\ExternalIssueProviderConnection;
use App\Models\TicketExternalLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Posts an agent's internal note as a Jira comment. Mirrors JiraIssueCreator's
 * conventions: the credential shape decides the deployment (email:token = Cloud
 * Basic auth + REST v3 with an ADF body; a bare PAT = Server/DC Bearer + REST
 * v2 with a plain-text body). Targets the issue key on the TicketExternalLink.
 */
class JiraIssueCommenter implements IssueCommenter
{
    /**
     * @return array{url: string|null, id: string|null}
     */
    public function comment(ExternalIssueProviderConnection $connection, TicketExternalLink $link, string $body): array
    {
        $token = $this->credentialToken($connection);
        $isCloud = str_contains($token, ':');

        try {
            $response = $this->request($token)->post($this->commentEndpoint($connection, $link, $isCloud), [
                'body' => $isCloud ? $this->adfDocument($body) : $body,
            ]);
        } catch (ConnectionException) {
            throw new ExternalIssueCommentFailed('Jira request failed before a response was received.', 'Jira');
        }

        if (! $response->successful()) {
            throw new ExternalIssueCommentFailed('Jira comment failed.', 'Jira', $response->status());
        }

        // The comment response carries a REST self-URL, not a browse URL; fall
        // back to the issue link.
        $id = data_get($response->json(), 'id');

        return [
            'url' => null,
            'id' => filled($id) ? (string) $id : null,
        ];
    }

    private function request(string $token): PendingRequest
    {
        $request = Http::withHeaders(['Accept' => 'application/json']);

        if (str_contains($token, ':')) {
            [$email, $apiToken] = explode(':', $token, 2);

            return $request->withBasicAuth(trim($email), trim($apiToken));
        }

        return $request->withToken($token);
    }

    private function credentialToken(ExternalIssueProviderConnection $connection): string
    {
        $token = data_get($connection->credentials, 'token');

        if (! is_string($token) || trim($token) === '') {
            throw new ExternalIssueCommentFailed('Jira credential is missing. Use email:api-token for Jira Cloud, or a personal access token for Jira Server/Data Center.', 'Jira');
        }

        return trim($token);
    }

    private function commentEndpoint(ExternalIssueProviderConnection $connection, TicketExternalLink $link, bool $isCloud): string
    {
        $key = trim((string) $link->external_key);

        if ($key === '') {
            throw new ExternalIssueCommentFailed('Jira issue key is missing on the linked ticket.', 'Jira');
        }

        return $this->tenantBaseUrl($connection).($isCloud ? '/rest/api/3' : '/rest/api/2').'/issue/'.rawurlencode($key).'/comment';
    }

    private function tenantBaseUrl(ExternalIssueProviderConnection $connection): string
    {
        $baseUrl = rtrim(trim((string) $connection->base_url), '/');

        if ($baseUrl === '') {
            throw new ExternalIssueCommentFailed('Jira base URL is missing. Set the connection base URL to your Jira site, like https://your-team.atlassian.net.', 'Jira');
        }

        foreach (['/rest/api/3', '/rest/api/2'] as $apiSuffix) {
            if (str_ends_with($baseUrl, $apiSuffix)) {
                $baseUrl = substr($baseUrl, 0, -strlen($apiSuffix));
            }
        }

        return $baseUrl;
    }

    /**
     * Jira Cloud's v3 API takes comment bodies as Atlassian Document Format;
     * each non-empty line of the note becomes a paragraph.
     *
     * @return array{type: string, version: int, content: array<int, array<string, mixed>>}
     */
    private function adfDocument(string $body): array
    {
        $paragraphs = [];

        foreach (explode("\n", $body) as $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $line]],
            ];
        }

        if ($paragraphs === []) {
            $paragraphs[] = [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Wayfindr note.']],
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $paragraphs,
        ];
    }
}
