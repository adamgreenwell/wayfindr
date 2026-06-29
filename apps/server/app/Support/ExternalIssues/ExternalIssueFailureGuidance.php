<?php

namespace App\Support\ExternalIssues;

final class ExternalIssueFailureGuidance
{
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
