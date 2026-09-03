<?php

namespace App\Support\ExternalIssues;

final class ExternalIssueFailureGuidance
{
    /**
     * A stable reason a request-facing controller can translate without
     * exposing the provider's raw response or teaching this support class
     * about the current request locale.
     */
    public static function reason(?int $status, string $fallback = ''): string
    {
        if ($status === null || $status < 400) {
            $configurationReason = match ($fallback) {
                'GitHub token is missing.',
                'GitLab token is missing.' => 'token_missing',
                'GitLab project key is missing.',
                'Jira project key is missing.' => 'project_key_missing',
                'GitHub project key must use owner/repository.' => 'github_project_key_format',
                'Jira credential is missing. Use email:api-token for Jira Cloud, or a personal access token for Jira Server/Data Center.' => 'jira_credential_missing',
                'Jira base URL is missing. Set the connection base URL to your Jira site, like https://your-team.atlassian.net.' => 'jira_base_url_missing',
                default => null,
            };

            if ($configurationReason !== null) {
                return $configurationReason;
            }

            return match (true) {
                str_contains($fallback, 'request failed before a response was received') => 'request_failed',
                str_contains($fallback, 'did not return an issue') => 'response_contract',
                default => 'configuration',
            };
        }

        return match (true) {
            $status === 401 => 'credentials',
            $status === 403 => 'permissions',
            $status === 404 => 'project_not_found',
            $status === 422 => 'issue_rejected',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            default => 'http_error',
        };
    }

    /**
     * Map an external-issue creation failure to safe, actionable agent guidance.
     *
     * Only curated, non-sensitive text is returned — never the raw provider
     * response body. When the failure carries no HTTP status (or a non-error
     * status), the adapter's own already-curated message is the clearest signal,
     * so it is passed through as the fallback.
     */
    public static function for(string $provider, ?int $status, string $fallback): string
    {
        if ($status === null || $status < 400) {
            return $fallback;
        }

        return match (true) {
            $status === 401 => "{$provider} rejected the connection credentials. Check that the access token is valid and not expired.",
            $status === 403 => "{$provider} denied the request. The token may lack access to this project, or the request was rate-limited. Check the token's permissions and try again shortly.",
            $status === 404 => "{$provider} could not find the project. Check the project key and that the token can access it.",
            $status === 422 => "{$provider} rejected the issue. The project may have issues disabled, or a field was invalid.",
            $status === 429 => "{$provider} is rate-limiting requests. Wait a moment and try again.",
            $status >= 500 => "{$provider} had a server error. Wait a moment and try again.",
            default => "{$provider} could not create the issue (status {$status}).",
        };
    }
}
