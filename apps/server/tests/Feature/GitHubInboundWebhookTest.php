<?php

// Inbound GitHub issue webhooks (#22, the bidirectional half): a signed
// delivery reflects the external issue's open/closed state onto the linked
// Wayfindr ticket. The endpoint is public, so authenticity rests entirely on
// the per-connection webhook secret (X-Hub-Signature-256 HMAC over the raw
// body). State is reflected, never enforced — the Wayfindr ticket is never
// auto-closed.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function githubWebhookFixture(array $connectionOverrides = [], array $linkOverrides = []): array
{
    $account = Account::factory()->create();
    $site = Site::factory()->for($account)->create();
    $ticket = Ticket::factory()->for($account)->for($site)->create(['status' => 'open']);

    $connection = ExternalIssueProviderConnection::factory()->for($account)->create(array_replace([
        'provider' => 'github',
        'is_enabled' => true,
        'credentials' => ['token' => 'gh_token', 'webhook_secret' => 'whsec_test'],
    ], $connectionOverrides));

    $link = TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create(array_replace([
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'external_id' => '456789',
            'external_key' => '#7',
            'url' => 'https://github.com/adamgreenwell/wayfindr/issues/7',
            'sync_status' => 'linked',
            'metadata' => ['external_issue_provider_connection_id' => $connection->id],
        ], $linkOverrides));

    return compact('account', 'site', 'ticket', 'connection', 'link');
}

function postGithubWebhook($test, ExternalIssueProviderConnection $connection, array $payload, ?string $secret, string $event = 'issues'): TestResponse
{
    $body = json_encode($payload);
    $headers = ['X-GitHub-Event' => $event];

    if ($secret !== null) {
        $headers['X-Hub-Signature-256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call(
        'POST',
        route('integrations.github.webhook', $connection),
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all()
        + ['CONTENT_TYPE' => 'application/json'],
        $body,
    );
}

test('a signed closed event reflects the state and audits the change', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'closed',
        'issue' => ['id' => 456789, 'number' => 7, 'state' => 'closed'],
    ], 'whsec_test')->assertOk();

    $link = $fixture['link']->fresh();

    expect($link->metadata['external_state'])->toBe('closed')
        ->and($link->last_synced_at)->not->toBeNull();

    $connection = $fixture['connection']->fresh();

    expect($connection->hasVerifiedInboundWebhook())->toBeTrue()
        ->and(data_get($connection->settings, 'inbound_webhook.event'))->toBe('issues')
        ->and(data_get($connection->settings, 'inbound_webhook.status_code'))->toBe(200);

    expect(
        $fixture['ticket']->auditEvents()
            ->where('action', 'ticket.external_issue_state_changed')
            ->where('metadata->external_state', 'closed')
            ->count()
    )->toBe(1);

    // The Wayfindr ticket is reflected, not enforced — it stays open.
    expect($fixture['ticket']->fresh()->status)->toBe('open');
});

test('an invalid signature is rejected and changes nothing', function (): void {
    $fixture = githubWebhookFixture();

    $body = json_encode(['action' => 'closed', 'issue' => ['id' => 456789]]);

    $this->call('POST', route('integrations.github.webhook', $fixture['connection']), [], [], [], [
        'HTTP_X_GITHUB_EVENT' => 'issues',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'wrong-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(401);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');

    expect($fixture['connection']->fresh()->hasVerifiedInboundWebhook())->toBeFalse();
});

test('a missing signature is rejected', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'closed',
        'issue' => ['id' => 456789],
    ], null)->assertStatus(401);
});

test('a connection without a webhook secret refuses deliveries', function (): void {
    $fixture = githubWebhookFixture(['credentials' => ['token' => 'gh_token']]);

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'closed',
        'issue' => ['id' => 456789],
    ], 'whsec_test')->assertStatus(403);
});

test('a signed reopened event flips the state back to open', function (): void {
    $fixture = githubWebhookFixture([], [
        'metadata' => fn () => null,
    ]);
    // Seed as closed first.
    $fixture['link']->forceFill([
        'metadata' => [
            'external_issue_provider_connection_id' => $fixture['connection']->id,
            'external_state' => 'closed',
        ],
    ])->save();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'reopened',
        'issue' => ['id' => 456789, 'state' => 'open'],
    ], 'whsec_test')->assertOk();

    expect($fixture['link']->fresh()->metadata['external_state'])->toBe('open');
});

test('a signed delivery for an untracked issue is accepted but changes nothing', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'closed',
        'issue' => ['id' => 999999, 'state' => 'closed'],
    ], 'whsec_test')->assertStatus(202);

    expect($fixture['link']->fresh()->metadata)->not->toHaveKey('external_state');
});

test('a ping event is acknowledged without acting', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], ['zen' => 'Design for failure.'], 'whsec_test', 'ping')
        ->assertStatus(202);

    expect($fixture['connection']->fresh()->hasVerifiedInboundWebhook())->toBeTrue()
        ->and(data_get($fixture['connection']->fresh()->settings, 'inbound_webhook.event'))->toBe('ping')
        ->and(data_get($fixture['connection']->fresh()->settings, 'inbound_webhook.status_code'))->toBe(202);
});

test('a non-github or disabled connection is not found', function (): void {
    $disabled = githubWebhookFixture(['is_enabled' => false]);
    postGithubWebhook($this, $disabled['connection'], ['action' => 'closed', 'issue' => ['id' => 1]], 'whsec_test')
        ->assertNotFound();
});

// --- Inbound comment relay (the other half of #22's comment relay) ---

test('a signed issue_comment records an internal note from the external comment', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'created',
        'issue' => ['id' => 456789],
        'comment' => ['id' => 555, 'body' => 'Fix is deployed, please retest.', 'user' => ['login' => 'octocat']],
    ], 'whsec_test', 'issue_comment')->assertOk();

    $event = AuditEvent::where('action', 'ticket.external_comment_received')->first();

    expect($event)->not->toBeNull()
        ->and(data_get($event->metadata, 'body'))->toBe('Fix is deployed, please retest.')
        ->and(data_get($event->metadata, 'author'))->toBe('octocat')
        ->and(data_get($event->metadata, 'external_comment_id'))->toBe('555')
        ->and(data_get($event->metadata, 'provider'))->toBe('github')
        // The ledger remembers the id so a retry is a no-op.
        ->and(data_get($fixture['link']->fresh()->metadata, 'synced_comment_ids'))->toContain('555');
});

test('a comment Wayfindr relayed is not echoed back as a note', function (): void {
    $fixture = githubWebhookFixture();
    $link = $fixture['link'];

    // Simulate the outbound relay having posted (and remembered) this comment.
    $link->forceFill(['metadata' => array_merge($link->metadata, ['synced_comment_ids' => ['555']])])->save();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'created',
        'issue' => ['id' => 456789],
        'comment' => ['id' => 555, 'body' => 'This was our own relayed note.', 'user' => ['login' => 'wayfindr-bot']],
    ], 'whsec_test', 'issue_comment')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('a repeated issue_comment delivery records the note only once', function (): void {
    $fixture = githubWebhookFixture();
    $payload = [
        'action' => 'created',
        'issue' => ['id' => 456789],
        'comment' => ['id' => 900, 'body' => 'Retry me.', 'user' => ['login' => 'octocat']],
    ];

    postGithubWebhook($this, $fixture['connection'], $payload, 'whsec_test', 'issue_comment')->assertOk();
    postGithubWebhook($this, $fixture['connection'], $payload, 'whsec_test', 'issue_comment')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(1);
});

test('an edited comment action is ignored', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'edited',
        'issue' => ['id' => 456789],
        'comment' => ['id' => 1, 'body' => 'edited body', 'user' => ['login' => 'octocat']],
    ], 'whsec_test', 'issue_comment')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('an issue_comment with a bad signature is rejected', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'created',
        'issue' => ['id' => 456789],
        'comment' => ['id' => 2, 'body' => 'forged', 'user' => ['login' => 'mallory']],
    ], 'wrong-secret', 'issue_comment')->assertStatus(401);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});

test('a comment on an issue Wayfindr does not track is acknowledged without a note', function (): void {
    $fixture = githubWebhookFixture();

    postGithubWebhook($this, $fixture['connection'], [
        'action' => 'created',
        'issue' => ['id' => 999999],
        'comment' => ['id' => 3, 'body' => 'unlinked', 'user' => ['login' => 'octocat']],
    ], 'whsec_test', 'issue_comment')->assertStatus(202);

    expect(AuditEvent::where('action', 'ticket.external_comment_received')->count())->toBe(0);
});
