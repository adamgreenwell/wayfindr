<?php

// Jira outbound issue creation (#22 — the roadmap's middle adapter: GitHub,
// then Jira, then GitLab). Same conservative posture as the other adapters:
// the exported body is the scoped summary only — no transcripts, cobrowse
// snapshots, or internal notes — and Jira Cloud's v3 API receives it as an
// Atlassian Document Format description. Cloud authenticates with Basic
// email:api-token (a colon in the stored credential); Server/Data Center
// personal access tokens ride as a Bearer.

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function jiraOutboundIssueFixture(array $connectionOverrides = [], array $projectOverrides = []): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-jira']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-JIRA1',
        'subject' => 'Checkout export keeps failing',
    ]);

    ConversationMessage::factory()->for($conversation)->create([
        'sender_type' => Visitor::class,
        'sender_id' => $visitor->id,
        'body' => 'my card number is 4242 4242 4242 4242',
    ]);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create([
            'subject' => 'Checkout export keeps failing',
            'description' => 'The visitor cannot export orders after checkout.',
            'priority' => 'high',
            'category' => 'bug',
            'metadata' => [
                'cobrowse_snapshot' => [
                    'token' => 'super-secret-cobrowse-token',
                ],
            ],
        ]);

    $ticket->auditEvents()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'action' => 'ticket.note_added',
        'metadata' => [
            'body' => 'Do not send this internal note',
        ],
        'occurred_at' => now(),
    ]);

    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create(array_replace_recursive([
            'provider' => 'jira',
            'name' => 'Engineering Jira',
            'base_url' => 'https://acme.atlassian.net',
            'credentials' => ['token' => 'ada@acme.test:jira_api_token_secret'],
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => false,
                'sync_status' => false,
            ],
        ], $connectionOverrides));

    $project = SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(array_replace([
            'project_key' => 'WAY',
            'project_name' => 'Wayfindr',
            'web_url' => 'https://acme.atlassian.net/browse/WAY',
        ], $projectOverrides));

    return compact('account', 'agent', 'site', 'visitor', 'conversation', 'ticket', 'project');
}

test('agent can create a conservative Jira issue from a mapped ticket', function (): void {
    $fixture = jiraOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://acme.atlassian.net/rest/api/3/issue' => Http::response([
            'id' => '10042',
            'key' => 'WAY-7',
            'self' => 'https://acme.atlassian.net/rest/api/3/issue/10042',
        ], 201),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('External issue actions')
        ->assertSee('Create Jira issue');

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/jira", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'Jira issue created.');

    Http::assertSent(function (HttpClientRequest $request) use ($ticket): bool {
        $payload = $request->data();
        $descriptionText = collect(data_get($payload, 'fields.description.content', []))
            ->flatMap(fn (array $paragraph): array => array_column($paragraph['content'] ?? [], 'text'))
            ->implode("\n");

        expect($request->method())->toBe('POST')
            ->and((string) $request->url())->toBe('https://acme.atlassian.net/rest/api/3/issue')
            // Jira Cloud Basic auth: base64(email:api-token).
            ->and($request->header('Authorization')[0] ?? '')->toBe('Basic '.base64_encode('ada@acme.test:jira_api_token_secret'))
            ->and(data_get($payload, 'fields.project.key'))->toBe('WAY')
            ->and(data_get($payload, 'fields.summary'))->toBe('Checkout export keeps failing')
            ->and(data_get($payload, 'fields.issuetype.name'))->toBe('Task')
            ->and(data_get($payload, 'fields.description.type'))->toBe('doc')
            ->and(data_get($payload, 'fields.description.version'))->toBe(1)
            ->and($descriptionText)->toContain("Wayfindr ticket #{$ticket->id}")
            ->and($descriptionText)->toContain('Support code: WF-JIRA1')
            ->and($descriptionText)->toContain('The visitor cannot export orders after checkout.')
            ->and($descriptionText)->toContain('Raw visitor transcripts, cobrowse snapshots, and internal notes were not exported')
            ->and($descriptionText)->not->toContain('my card number is 4242 4242 4242 4242')
            ->and($descriptionText)->not->toContain('Do not send this internal note')
            ->and($descriptionText)->not->toContain('super-secret-cobrowse-token');

        return true;
    });

    $this->assertDatabaseHas('ticket_external_links', [
        'account_id' => $fixture['account']->id,
        'site_id' => $fixture['site']->id,
        'ticket_id' => $ticket->id,
        'provider' => 'jira',
        'project_key' => 'WAY',
        'external_id' => '10042',
        'external_key' => 'WAY-7',
        'url' => 'https://acme.atlassian.net/browse/WAY-7',
        'sync_status' => 'linked',
    ]);

    $externalLink = $ticket->externalLinks()->firstOrFail();

    expect($externalLink->metadata)->toMatchArray([
        'site_external_issue_project_id' => $project->id,
        'external_issue_provider_connection_id' => $project->external_issue_provider_connection_id,
        'created_via' => 'jira_adapter',
        'jira_issue_key' => 'WAY-7',
    ]);

    expect(
        $ticket->auditEvents()->where('action', 'ticket.external_issue_created')->count()
    )->toBe(1);
});

test('a colon-free credential targets Server/Data Center: bearer auth, REST v2, plain-text description', function (): void {
    $fixture = jiraOutboundIssueFixture([
        'base_url' => 'https://jira.acme.internal',
        'credentials' => ['token' => 'pat_datacenter_secret'],
    ]);

    Http::fake([
        'https://jira.acme.internal/rest/api/2/issue' => Http::response(['id' => '77', 'key' => 'WAY-9'], 201),
    ]);

    $this->actingAs($fixture['agent'])
        ->post("/dashboard/tickets/{$fixture['ticket']->id}/external-issues/jira", [
            'site_external_issue_project_id' => $fixture['project']->id,
        ])
        ->assertSessionHas('status', 'Jira issue created.');

    Http::assertSent(function (HttpClientRequest $request): bool {
        $description = data_get($request->data(), 'fields.description');

        expect(($request->header('Authorization')[0] ?? ''))->toBe('Bearer pat_datacenter_secret')
            // Server/Data Center: REST v2, and the description is the plain
            // scoped summary, not an ADF document.
            ->and((string) $request->url())->toBe('https://jira.acme.internal/rest/api/2/issue')
            ->and($description)->toBeString()
            ->and($description)->toContain('Support code: WF-JIRA1')
            ->and($description)->not->toContain('my card number is 4242 4242 4242 4242');

        return true;
    });

    $this->assertDatabaseHas('ticket_external_links', [
        'ticket_id' => $fixture['ticket']->id,
        'url' => 'https://jira.acme.internal/browse/WAY-9',
    ]);
});

test('Jira-only mappings count as handoff-ready in readiness surfaces', function (): void {
    $fixture = jiraOutboundIssueFixture();

    expect($fixture['project']->fresh()->load('providerConnection')->supportsIssueCreationHandoff())->toBeTrue();
});

test('a missing Jira base URL fails with guidance and records the sync failure', function (): void {
    $fixture = jiraOutboundIssueFixture(['base_url' => null]);

    Http::fake();

    $this->actingAs($fixture['agent'])
        ->from("/dashboard/tickets/{$fixture['ticket']->id}")
        ->post("/dashboard/tickets/{$fixture['ticket']->id}/external-issues/jira", [
            'site_external_issue_project_id' => $fixture['project']->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$fixture['ticket']->id}")
        ->assertSessionHasErrors('external_issue');

    Http::assertNothingSent();

    expect(
        $fixture['ticket']->auditEvents()->where('action', 'ticket.external_sync_failed')->count()
    )->toBe(1);
});

test('a Jira API rejection surfaces failure guidance without leaking the raw error', function (): void {
    $fixture = jiraOutboundIssueFixture();

    Http::fake([
        'https://acme.atlassian.net/rest/api/3/issue' => Http::response(['errorMessages' => ['field summary is required']], 400),
    ]);

    $response = $this->actingAs($fixture['agent'])
        ->from("/dashboard/tickets/{$fixture['ticket']->id}")
        ->post("/dashboard/tickets/{$fixture['ticket']->id}/external-issues/jira", [
            'site_external_issue_project_id' => $fixture['project']->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$fixture['ticket']->id}")
        ->assertSessionHasErrors('external_issue');

    $failure = $fixture['ticket']->auditEvents()
        ->where('action', 'ticket.external_sync_failed')
        ->firstOrFail();

    expect($failure->metadata)->toMatchArray([
        'provider' => 'jira',
        'project_key' => 'WAY',
        'status' => 400,
    ]);
});

test('a connection without the create_issue capability is refused', function (): void {
    $fixture = jiraOutboundIssueFixture([
        'capabilities' => ['create_issue' => false],
    ]);

    Http::fake();

    $this->actingAs($fixture['agent'])
        ->post("/dashboard/tickets/{$fixture['ticket']->id}/external-issues/jira", [
            'site_external_issue_project_id' => $fixture['project']->id,
        ])
        ->assertSessionHasErrors('external_issue');

    Http::assertNothingSent();
});
