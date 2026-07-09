<?php

namespace App\Support\ExternalIssues;

use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class JiraIssueCreator
{
    public function __construct(private readonly ExternalIssueExportPreview $exportPreview) {}

    /**
     * @return array{id: string|null, key: string|null, url: string, title: string|null}
     */
    public function create(SiteExternalIssueProject $project, Ticket $ticket): array
    {
        $projectKey = trim($project->project_key);

        if ($projectKey === '') {
            throw new JiraIssueCreationFailed('Jira project key is missing.');
        }

        try {
            $response = $this->request($project)->post($this->issueEndpoint($project), [
                'fields' => [
                    'project' => ['key' => $projectKey],
                    'summary' => $ticket->subject,
                    'issuetype' => ['name' => 'Task'],
                    'description' => $this->adfDocument($this->exportPreview->forTicket($ticket)['body']),
                ],
            ]);
        } catch (ConnectionException) {
            throw new JiraIssueCreationFailed('Jira request failed before a response was received.');
        }

        if (! $response->successful()) {
            throw new JiraIssueCreationFailed('Jira issue creation failed.', $response->status());
        }

        $payload = $response->json();
        $key = data_get($payload, 'key');

        if (! is_string($key) || trim($key) === '') {
            throw new JiraIssueCreationFailed('Jira did not return an issue key.', $response->status());
        }

        return [
            'id' => filled(data_get($payload, 'id')) ? (string) data_get($payload, 'id') : null,
            'key' => $key,
            'url' => $this->tenantBaseUrl($project).'/browse/'.rawurlencode($key),
            'title' => $ticket->subject,
        ];
    }

    /**
     * Jira Cloud authenticates with Basic email:api-token; Jira Server and
     * Data Center use a personal access token as a Bearer. The connection
     * stores one credential string — a colon means email:token (Cloud).
     */
    private function request(SiteExternalIssueProject $project): PendingRequest
    {
        $token = data_get($project->providerConnection?->credentials, 'token');

        if (! is_string($token) || trim($token) === '') {
            throw new JiraIssueCreationFailed('Jira credential is missing. Use email:api-token for Jira Cloud, or a personal access token for Jira Server/Data Center.');
        }

        $token = trim($token);
        $request = Http::withHeaders(['Accept' => 'application/json']);

        if (str_contains($token, ':')) {
            [$email, $apiToken] = explode(':', $token, 2);

            return $request->withBasicAuth(trim($email), trim($apiToken));
        }

        return $request->withToken($token);
    }

    private function issueEndpoint(SiteExternalIssueProject $project): string
    {
        return $this->tenantBaseUrl($project).'/rest/api/3/issue';
    }

    /**
     * Jira has no anonymous default host — every tenant has its own — so the
     * connection's base URL is required (e.g. https://acme.atlassian.net).
     */
    private function tenantBaseUrl(SiteExternalIssueProject $project): string
    {
        $baseUrl = rtrim(trim((string) $project->providerConnection?->base_url), '/');

        if ($baseUrl === '') {
            throw new JiraIssueCreationFailed('Jira base URL is missing. Set the connection base URL to your Jira site, like https://your-team.atlassian.net.');
        }

        if (str_ends_with($baseUrl, '/rest/api/3')) {
            $baseUrl = substr($baseUrl, 0, -strlen('/rest/api/3'));
        }

        return $baseUrl;
    }

    /**
     * Jira Cloud's v3 API takes descriptions as Atlassian Document Format.
     * The export preview body is plain text; each line becomes a paragraph.
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
                'content' => [['type' => 'text', 'text' => 'Created from a Wayfindr ticket.']],
            ];
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $paragraphs,
        ];
    }
}
