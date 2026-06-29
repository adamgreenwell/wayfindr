<?php

use App\Support\ExternalIssues\ExternalIssueFailureGuidance;

test('maps common HTTP error statuses to safe actionable guidance', function (): void {
    expect(ExternalIssueFailureGuidance::for('GitHub', 401, 'fallback'))->toContain('credentials');
    expect(ExternalIssueFailureGuidance::for('GitHub', 403, 'fallback'))->toContain('permissions');
    expect(ExternalIssueFailureGuidance::for('GitHub', 404, 'fallback'))->toContain('could not find the project');
    expect(ExternalIssueFailureGuidance::for('GitHub', 422, 'fallback'))->toContain('issues disabled');
    expect(ExternalIssueFailureGuidance::for('GitHub', 429, 'fallback'))->toContain('rate-limiting');
    expect(ExternalIssueFailureGuidance::for('GitHub', 503, 'fallback'))->toContain('server error');
});

test('prefixes guidance with the provider label', function (): void {
    expect(ExternalIssueFailureGuidance::for('GitLab', 404, 'fallback'))->toStartWith('GitLab');
    expect(ExternalIssueFailureGuidance::for('GitHub', 404, 'fallback'))->toStartWith('GitHub');
});

test('falls back to the curated adapter message when there is no error status', function (): void {
    // No status (token missing, malformed key, connection failure) and non-error
    // statuses should surface the adapter's own already-curated message.
    expect(ExternalIssueFailureGuidance::for('GitHub', null, 'GitHub token is missing.'))
        ->toBe('GitHub token is missing.');
    expect(ExternalIssueFailureGuidance::for('GitHub', 201, 'GitHub did not return an issue URL.'))
        ->toBe('GitHub did not return an issue URL.');
});

test('reports the raw status for unmapped error codes without leaking detail', function (): void {
    expect(ExternalIssueFailureGuidance::for('GitHub', 418, 'fallback'))
        ->toBe('GitHub could not create the issue (status 418).');
});
