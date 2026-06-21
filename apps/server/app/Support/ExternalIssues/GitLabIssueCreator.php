<?php

namespace App\Support\ExternalIssues;

use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GitLabIssueCreator
{
    /**
     * @return array{id: string|null, iid: string|null, url: string, title: string|null}
     */
    public function create(SiteExternalIssueProject $project, Ticket $ticket): array
    {
        $connection = $project->providerConnection;
        $token = data_get($connection?->credentials, 'token');

        if (! is_string($token) || trim($token) === '') {
            throw new GitLabIssueCreationFailed('GitLab token is missing.');
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'PRIVATE-TOKEN' => $token,
            ])->post($this->issuesEndpoint($project), [
                'title' => $ticket->subject,
                'description' => $this->issueDescription($ticket),
            ]);
        } catch (ConnectionException) {
            throw new GitLabIssueCreationFailed('GitLab request failed before a response was received.');
        }

        if (! $response->successful()) {
            throw new GitLabIssueCreationFailed(
                'GitLab issue creation failed.',
                $response->status(),
            );
        }

        $payload = $response->json();
        $url = data_get($payload, 'web_url');

        if (! is_string($url) || trim($url) === '') {
            throw new GitLabIssueCreationFailed('GitLab did not return an issue URL.', $response->status());
        }

        $iid = data_get($payload, 'iid');

        return [
            'id' => filled(data_get($payload, 'id')) ? (string) data_get($payload, 'id') : null,
            'iid' => filled($iid) ? (string) $iid : null,
            'url' => $url,
            'title' => is_string(data_get($payload, 'title')) ? data_get($payload, 'title') : null,
        ];
    }

    private function issuesEndpoint(SiteExternalIssueProject $project): string
    {
        $projectKey = trim($project->project_key);

        if ($projectKey === '') {
            throw new GitLabIssueCreationFailed('GitLab project key is missing.');
        }

        return $this->apiBaseUrl($project).'/projects/'.rawurlencode($projectKey).'/issues';
    }

    private function apiBaseUrl(SiteExternalIssueProject $project): string
    {
        $baseUrl = trim((string) ($project->providerConnection?->base_url ?: 'https://gitlab.com'));

        if ($baseUrl === '') {
            $baseUrl = 'https://gitlab.com';
        }

        $baseUrl = rtrim($baseUrl, '/');

        if (! str_ends_with($baseUrl, '/api/v4')) {
            $baseUrl .= '/api/v4';
        }

        return $baseUrl;
    }

    private function issueDescription(Ticket $ticket): string
    {
        $ticket->loadMissing(['conversation', 'site']);

        return collect([
            "Wayfindr ticket #{$ticket->id}",
            '',
            'Support code: '.($ticket->conversation?->support_code ?? 'Not linked'),
            'Site: '.$ticket->site->name,
            'Priority: '.Str::headline($ticket->priority),
            'Category: '.$ticket->categoryLabel(),
            'Status: '.Str::headline($ticket->status),
            'Wayfindr URL: '.route('dashboard.tickets.show', $ticket),
            '',
            'Description:',
            $this->description($ticket),
            '',
            'Export note:',
            'This issue was created from a Wayfindr ticket. Raw visitor transcripts, cobrowse snapshots, and internal notes were not exported by default.',
        ])->implode(PHP_EOL);
    }

    private function description(Ticket $ticket): string
    {
        $description = trim((string) $ticket->description);

        return $description === '' ? 'No description provided.' : $description;
    }
}
