<?php

// Inbound GitLab issue webhooks (#22, bidirectional). GitLab does not
// HMAC-sign; it echoes the configured secret in X-Gitlab-Token, so
// authenticity is a constant-time comparison against the per-connection
// webhook secret. State is reflected onto the linked ticket, never enforced.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function gitlabWebhookFixture(array $connectionOverrides = [], array $linkOverrides = []): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['status' => 'open']);

    $connection = ExternalIssueProviderConnection::factory()->for($account)->create(array_replace([
        'provider' => 'gitlab',
        'is_enabled' => true,
        'credentials' => ['token' => 'gl_token', 'webhook_secret' => 'glhook_secret'],
    ], $connectionOverrides));

    $link = TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create(array_replace([
            'provider' => 'gitlab',
            'project_key' => 'adamgreenwell/wayfindr',
            'external_id' => '456789',
            'external_key' => '#42',
            'url' => 'https://gitlab.com/adamgreenwell/wayfindr/-/issues/42',
            'sync_status' => 'linked',
            'metadata' => ['external_issue_provider_connection_id' => $connection->id],
        ], $linkOverrides));

    return compact('account', 'site', 'ticket', 'connection', 'link');
}

function postGitlabWebhook($test, ExternalIssueProviderConnection $connection, array $payload, ?string $token): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($token !== null) {
        $headers['HTTP_X_GITLAB_TOKEN'] = $token;
    }

    return $test->call(
        'POST',
        route('integrations.gitlab.webhook', $connection),
        [],
        [],
        [],
        $headers,
        json_encode($payload),
    );
}

test('a valid token close event reflects the state and audits the change', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 456789, 'action' => 'close', 'state' => 'closed'],
    ], 'glhook_secret')->assertOk();

    $link = $fixture['link']->fresh();

    expect($link->metadata['external_state'])->toBe('closed')
        ->and($link->last_synced_at)->not->toBeNull();

    expect($fixture['connection']->fresh()->hasVerifiedInboundWebhook())->toBeTrue()
        ->and(data_get($fixture['connection']->fresh()->settings, 'inbound_webhook.event'))->toBe('issue')
        ->and(data_get($fixture['connection']->fresh()->settings, 'inbound_webhook.status_code'))->toBe(200);

    expect(
        $fixture['ticket']->auditEvents()
            ->where('action', 'ticket.external_issue_state_changed')
            ->where('metadata->source', 'gitlab_webhook')
            ->count()
    )->toBe(1);

    expect($fixture['ticket']->fresh()->status)->toBe('open');
});

test('a wrong token is rejected and changes nothing', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 456789, 'action' => 'close'],
    ], 'wrong-token')->assertStatus(401);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');
});

test('a missing token is rejected', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 456789, 'action' => 'close'],
    ], null)->assertStatus(401);
});

test('a connection without a webhook secret refuses deliveries', function (): void {
    $fixture = gitlabWebhookFixture(['credentials' => ['token' => 'gl_token']]);

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 456789, 'action' => 'close'],
    ], 'glhook_secret')->assertStatus(403);
});

test('a reopen event flips the state back to open', function (): void {
    $fixture = gitlabWebhookFixture();
    $fixture['link']->forceFill([
        'metadata' => [
            'external_issue_provider_connection_id' => $fixture['connection']->id,
            'external_state' => 'closed',
        ],
    ])->save();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 456789, 'action' => 'reopen', 'state' => 'opened'],
    ], 'glhook_secret')->assertOk();

    expect($fixture['link']->fresh()->metadata['external_state'])->toBe('open');
});

test('a non-issue or untracked event is accepted without acting', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'push',
    ], 'glhook_secret')->assertStatus(202);

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 999999, 'action' => 'close'],
    ], 'glhook_secret')->assertStatus(202);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');
});

test('a disabled or non-gitlab connection is not found', function (): void {
    $fixture = gitlabWebhookFixture(['is_enabled' => false]);

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'issue',
        'object_attributes' => ['id' => 456789, 'action' => 'close'],
    ], 'glhook_secret')->assertNotFound();
});

// --- Inbound comment relay (note hooks on issues) ---

test('a note hook on an issue records an internal note', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'note',
        'object_attributes' => ['id' => 5501, 'note' => 'Deployed the fix, please retest.', 'noteable_type' => 'Issue'],
        'issue' => ['id' => 456789],
        'user' => ['username' => 'gl-dev'],
    ], 'glhook_secret')->assertOk();

    $event = AuditEvent::where('action', 'ticket.external_comment_received')->first();

    expect($event)->not->toBeNull()
        ->and(data_get($event->metadata, 'body'))->toBe('Deployed the fix, please retest.')
        ->and(data_get($event->metadata, 'author'))->toBe('gl-dev')
        ->and(data_get($event->metadata, 'provider'))->toBe('gitlab')
        ->and(data_get($event->metadata, 'external_comment_id'))->toBe('5501');
});

test('a note hook Wayfindr relayed is not echoed back', function (): void {
    $fixture = gitlabWebhookFixture();
    $link = $fixture['link'];
    $link->forceFill(['metadata' => array_merge($link->metadata, ['synced_comment_ids' => ['5501']])])->save();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'note',
        'object_attributes' => ['id' => 5501, 'note' => 'our own relayed note', 'noteable_type' => 'Issue'],
        'issue' => ['id' => 456789],
        'user' => ['username' => 'wayfindr'],
    ], 'glhook_secret')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('a note hook on a non-issue notable is ignored', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'note',
        'object_attributes' => ['id' => 5502, 'note' => 'a merge request note', 'noteable_type' => 'MergeRequest'],
        'issue' => ['id' => 456789],
        'user' => ['username' => 'gl-dev'],
    ], 'glhook_secret')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('a note hook with a bad token is rejected', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'note',
        'object_attributes' => ['id' => 5503, 'note' => 'forged', 'noteable_type' => 'Issue'],
        'issue' => ['id' => 456789],
        'user' => ['username' => 'mallory'],
    ], 'wrong-token')->assertStatus(401);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('an edited issue note (action update) is not recorded as a new note', function (): void {
    $fixture = gitlabWebhookFixture();

    postGitlabWebhook($this, $fixture['connection'], [
        'object_kind' => 'note',
        'object_attributes' => ['id' => 5599, 'note' => 'this comment was edited', 'noteable_type' => 'Issue', 'action' => 'update'],
        'issue' => ['id' => 456789],
        'user' => ['username' => 'gl-dev'],
    ], 'glhook_secret')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});
