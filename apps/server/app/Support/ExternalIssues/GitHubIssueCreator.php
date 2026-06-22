<?php

namespace App\Support\ExternalIssues;

use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GitHubIssueCreator
{
    public function __construct(private readonly ExternalIssueExportPreview $exportPreview) {}

    /**
     * @return array{id: string|null, number: string|null, url: string, title: string|null}
     */
    public function create(SiteExternalIssueProject $project, Ticket $ticket): array
    {
        $connection = $project->providerConnection;
        $token = data_get($connection?->credentials, 'token');

        if (! is_string($token) || trim($token) === '') {
            throw new GitHubIssueCreationFailed('GitHub token is missing.');
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post($this->issuesEndpoint($project), [
                    'title' => $ticket->subject,
                    'body' => $this->exportPreview->forTicket($ticket)['body'],
                ]);
        } catch (ConnectionException) {
            throw new GitHubIssueCreationFailed('GitHub request failed before a response was received.');
        }

        if (! $response->successful()) {
            throw new GitHubIssueCreationFailed(
                'GitHub issue creation failed.',
                $response->status(),
            );
        }

        $payload = $response->json();
        $url = data_get($payload, 'html_url');

        if (! is_string($url) || trim($url) === '') {
            throw new GitHubIssueCreationFailed('GitHub did not return an issue URL.', $response->status());
        }

        $number = data_get($payload, 'number');

        return [
            'id' => filled(data_get($payload, 'id')) ? (string) data_get($payload, 'id') : null,
            'number' => filled($number) ? (string) $number : null,
            'url' => $url,
            'title' => is_string(data_get($payload, 'title')) ? data_get($payload, 'title') : null,
        ];
    }

    private function issuesEndpoint(SiteExternalIssueProject $project): string
    {
        $parts = explode('/', $project->project_key);

        if (count($parts) !== 2 || blank($parts[0]) || blank($parts[1])) {
            throw new GitHubIssueCreationFailed('GitHub project key must use owner/repository.');
        }

        $baseUrl = rtrim($project->providerConnection?->base_url ?: 'https://api.github.com', '/');

        return $baseUrl.'/repos/'.rawurlencode($parts[0]).'/'.rawurlencode($parts[1]).'/issues';
    }
}
