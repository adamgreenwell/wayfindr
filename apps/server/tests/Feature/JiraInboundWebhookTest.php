<?php

// Inbound Jira issue webhooks (#22, completing the three-provider inbound
// story). Jira Cloud webhooks configured with a secret sign the delivery
// with an X-Hub-Signature HMAC-SHA256 over the raw body (the same scheme as
// GitHub), so authenticity is a constant-time HMAC comparison. Jira has no
// "closed" action — an issue is done when its status enters the "done"
// status category — so the reflected state comes from
// fields.status.statusCategory.key.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function jiraWebhookFixture(array $connectionOverrides = [], array $linkOverrides = []): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['status' => 'open']);

    $connection = ExternalIssueProviderConnection::factory()->for($account)->create(array_replace([
        'provider' => 'jira',
        'is_enabled' => true,
        'base_url' => 'https://acme.atlassian.net',
        'credentials' => ['token' => 'email:api', 'webhook_secret' => 'jira_hook_secret'],
    ], $connectionOverrides));

    $link = TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create(array_replace([
            'provider' => 'jira',
            'project_key' => 'WAY',
            'external_id' => '10042',
            'external_key' => 'WAY-7',
            'url' => 'https://acme.atlassian.net/browse/WAY-7',
            'sync_status' => 'linked',
            'metadata' => ['external_issue_provider_connection_id' => $connection->id],
        ], $linkOverrides));

    return compact('account', 'site', 'ticket', 'connection', 'link');
}

function jiraIssuePayload(string $categoryKey, string|int $id = 10042, string $event = 'jira:issue_updated'): array
{
    return [
        'webhookEvent' => $event,
        'issue' => [
            'id' => $id,
            'key' => 'WAY-7',
            'fields' => ['status' => ['statusCategory' => ['key' => $categoryKey]]],
        ],
    ];
}

function postJiraWebhook($test, ExternalIssueProviderConnection $connection, array $payload, ?string $secret): TestResponse
{
    $body = json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $headers['HTTP_X_HUB_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call(
        'POST',
        route('integrations.jira.webhook', $connection),
        [],
        [],
        [],
        $headers,
        $body,
    );
}

test('a done status category reflects closed and audits the change', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('done'), 'jira_hook_secret')->assertOk();

    $link = $fixture['link']->fresh();

    expect($link->metadata['external_state'])->toBe('closed')
        ->and($link->last_synced_at)->not->toBeNull();

    expect(
        $fixture['ticket']->auditEvents()
            ->where('action', 'ticket.external_issue_state_changed')
            ->where('metadata->source', 'jira_webhook')
            ->count()
    )->toBe(1);

    expect($fixture['ticket']->fresh()->status)->toBe('open');
});

test('new and indeterminate status categories reflect open', function (): void {
    $fixture = jiraWebhookFixture();
    $fixture['link']->forceFill([
        'metadata' => ['external_issue_provider_connection_id' => $fixture['connection']->id, 'external_state' => 'closed'],
    ])->save();

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('indeterminate'), 'jira_hook_secret')->assertOk();

    expect($fixture['link']->fresh()->metadata['external_state'])->toBe('open');
});

test('a wrong signature is rejected and changes nothing', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('done'), 'wrong-secret')->assertStatus(401);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');
});

test('a missing signature is rejected', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('done'), null)->assertStatus(401);
});

test('a connection without a webhook secret refuses deliveries', function (): void {
    $fixture = jiraWebhookFixture(['credentials' => ['token' => 'email:api']]);

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('done'), 'jira_hook_secret')->assertStatus(403);
});

test('non-issue events and unknown status categories are accepted without acting', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], ['webhookEvent' => 'comment_created'], 'jira_hook_secret')
        ->assertStatus(202);

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('unknown-category'), 'jira_hook_secret')
        ->assertStatus(202);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');
});

test('an untracked issue is accepted without acting', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('done', 999999), 'jira_hook_secret')
        ->assertStatus(202);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');
});

test('a disabled or non-jira connection is not found', function (): void {
    $fixture = jiraWebhookFixture(['is_enabled' => false]);

    postJiraWebhook($this, $fixture['connection'], jiraIssuePayload('done'), 'jira_hook_secret')->assertNotFound();
});

// --- Inbound comment relay (comment_created) ---

test('a comment_created event records an internal note', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], [
        'webhookEvent' => 'comment_created',
        'issue' => ['id' => 10042],
        'comment' => ['id' => '90001', 'body' => 'Shipped in 2.4.', 'author' => ['displayName' => 'Ada Ops']],
    ], 'jira_hook_secret')->assertOk();

    $event = AuditEvent::where('action', 'ticket.external_comment_received')->first();

    expect($event)->not->toBeNull()
        ->and(data_get($event->metadata, 'body'))->toBe('Shipped in 2.4.')
        ->and(data_get($event->metadata, 'author'))->toBe('Ada Ops')
        ->and(data_get($event->metadata, 'provider'))->toBe('jira')
        ->and(data_get($event->metadata, 'external_comment_id'))->toBe('90001');
});

test('a comment_created event flattens an ADF body', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], [
        'webhookEvent' => 'comment_created',
        'issue' => ['id' => 10042],
        'comment' => [
            'id' => '90002',
            'author' => ['displayName' => 'Ada'],
            'body' => ['type' => 'doc', 'version' => 1, 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line one.']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line two.']]],
            ]],
        ],
    ], 'jira_hook_secret')->assertOk();

    $event = AuditEvent::where('action', 'ticket.external_comment_received')->first();
    expect(data_get($event->metadata, 'body'))->toBe("Line one.\nLine two.");
});

test('a comment_created event Wayfindr relayed is not echoed back', function (): void {
    $fixture = jiraWebhookFixture();
    $link = $fixture['link'];
    $link->forceFill(['metadata' => array_merge($link->metadata, ['synced_comment_ids' => ['90003']])])->save();

    postJiraWebhook($this, $fixture['connection'], [
        'webhookEvent' => 'comment_created',
        'issue' => ['id' => 10042],
        'comment' => ['id' => '90003', 'body' => 'our own relayed note', 'author' => ['displayName' => 'Bot']],
    ], 'jira_hook_secret')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('a comment_created event with a bad signature is rejected', function (): void {
    $fixture = jiraWebhookFixture();

    postJiraWebhook($this, $fixture['connection'], [
        'webhookEvent' => 'comment_created',
        'issue' => ['id' => 10042],
        'comment' => ['id' => '90004', 'body' => 'forged', 'author' => ['displayName' => 'Mallory']],
    ], 'wrong-secret')->assertStatus(401);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});
