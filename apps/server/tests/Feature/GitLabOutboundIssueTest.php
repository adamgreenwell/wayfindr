<?php

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('agent can create a conservative GitLab issue from a mapped ticket', function (): void {
    $fixture = gitlabOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://gitlab.com/api/v4/projects/adamgreenwell%2Fwayfindr/issues' => Http::response([
            'id' => 456789,
            'iid' => 42,
            'web_url' => 'https://gitlab.com/adamgreenwell/wayfindr/-/issues/42',
            'title' => 'Checkout export keeps failing',
        ], 201),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('External issue actions')
        ->assertSee('Create GitLab issue');

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/gitlab", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'GitLab issue created.');

    Http::assertSent(function (HttpClientRequest $request) use ($ticket): bool {
        $payload = $request->data();
        $description = (string) data_get($payload, 'description');

        expect($request->method())->toBe('POST')
            ->and((string) $request->url())->toBe('https://gitlab.com/api/v4/projects/adamgreenwell%2Fwayfindr/issues')
            ->and($request->header('PRIVATE-TOKEN'))->toContain('glpat_test_secret')
            ->and($request->header('Accept'))->toContain('application/json')
            ->and(data_get($payload, 'title'))->toBe('Checkout export keeps failing')
            ->and($description)->toContain("Wayfindr ticket #{$ticket->id}")
            ->and($description)->toContain('Support code: WF-GL01')
            ->and($description)->toContain('Site: Acme Docs')
            ->and($description)->toContain('Priority: High')
            ->and($description)->toContain('Category: Bug')
            ->and($description)->toContain("/dashboard/tickets/{$ticket->id}")
            ->and($description)->toContain('The visitor cannot export orders after checkout.')
            ->and($description)->toContain('Raw visitor transcripts, cobrowse snapshots, and internal notes were not exported')
            ->and($description)->not->toContain('my card number is 4242 4242 4242 4242')
            ->and($description)->not->toContain('Do not send this internal note')
            ->and($description)->not->toContain('super-secret-cobrowse-token');

        return true;
    });

    $this->assertDatabaseHas('ticket_external_links', [
        'account_id' => $fixture['account']->id,
        'site_id' => $fixture['site']->id,
        'ticket_id' => $ticket->id,
        'provider' => 'gitlab',
        'project_key' => 'adamgreenwell/wayfindr',
        'external_id' => '456789',
        'external_key' => '#42',
        'url' => 'https://gitlab.com/adamgreenwell/wayfindr/-/issues/42',
        'sync_status' => 'linked',
    ]);

    $externalLink = $ticket->externalLinks()->firstOrFail();

    expect($externalLink->metadata)
        ->toMatchArray([
            'site_external_issue_project_id' => $project->id,
            'external_issue_provider_connection_id' => $project->external_issue_provider_connection_id,
            'created_via' => 'gitlab_adapter',
            'gitlab_issue_iid' => '42',
        ]);

    $this->assertDatabaseHas('audit_events', [
        'account_id' => $fixture['account']->id,
        'site_id' => $fixture['site']->id,
        'actor_type' => User::class,
        'actor_id' => $agent->id,
        'subject_type' => Ticket::class,
        'subject_id' => $ticket->id,
        'action' => 'ticket.external_issue_created',
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('GitLab issue created')
        ->assertSee('https://gitlab.com/adamgreenwell/wayfindr/-/issues/42');
});

test('GitLab issue creation supports self-managed host base URLs', function (): void {
    $fixture = gitlabOutboundIssueFixture([
        'base_url' => 'https://gitlab.example.test',
    ], [
        'project_key' => 'support/platform',
        'web_url' => 'https://gitlab.example.test/support/platform',
    ]);
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://gitlab.example.test/api/v4/projects/support%2Fplatform/issues' => Http::response([
            'id' => 987654,
            'iid' => 7,
            'web_url' => 'https://gitlab.example.test/support/platform/-/issues/7',
            'title' => 'Checkout export keeps failing',
        ], 201),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/gitlab", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'GitLab issue created.');

    Http::assertSent(fn (HttpClientRequest $request): bool => (string) $request->url() === 'https://gitlab.example.test/api/v4/projects/support%2Fplatform/issues');

    $this->assertDatabaseHas('ticket_external_links', [
        'provider' => 'gitlab',
        'project_key' => 'support/platform',
        'external_id' => '987654',
        'external_key' => '#7',
        'url' => 'https://gitlab.example.test/support/platform/-/issues/7',
    ]);
});

test('ticket detail previews the conservative GitLab issue export payload', function (): void {
    $fixture = gitlabOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $agent = $fixture['agent'];

    $response = $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk();

    $response
        ->assertSeeInOrder([
            'External issue export preview',
            'Issue title',
            'Checkout export keeps failing',
            'Summary sent to external trackers',
            "Wayfindr ticket #{$ticket->id}",
            'Support code: WF-GL01',
            'Site: Acme Docs',
            'Priority: High',
            'Category: Bug',
            'Status: Open',
            'Description',
            'The visitor cannot export orders after checkout.',
            'Raw visitor transcripts, cobrowse snapshots, and internal notes were not exported by default.',
        ]);

    preg_match('/<div class="external-issue-export-preview" data-external-issue-export-preview>.*?<\/pre>\s*<\/div>/s', $response->getContent(), $matches);

    expect($matches[0] ?? '')
        ->not->toBe('')
        ->not->toContain('my card number is 4242 4242 4242 4242')
        ->not->toContain('Do not send this internal note')
        ->not->toContain('super-secret-cobrowse-token')
        ->not->toContain('glpat_test_secret');
});

test('GitLab issue exports omit conversation generated ticket descriptions', function (): void {
    $fixture = gitlabOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    $ticket->forceFill([
        'description' => 'Visitor: my card number is 4242 4242 4242 4242'.PHP_EOL.PHP_EOL.'Ada Agent: Do not export this transcript.',
        'metadata' => array_replace($ticket->metadata ?? [], [
            'description_source' => 'conversation_transcript',
        ]),
    ])->save();

    Http::fake([
        'https://gitlab.com/api/v4/projects/adamgreenwell%2Fwayfindr/issues' => Http::response([
            'id' => 456789,
            'iid' => 42,
            'web_url' => 'https://gitlab.com/adamgreenwell/wayfindr/-/issues/42',
            'title' => 'Checkout export keeps failing',
        ], 201),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/gitlab", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'GitLab issue created.');

    Http::assertSent(function (HttpClientRequest $request): bool {
        $description = (string) data_get($request->data(), 'description');

        expect($description)
            ->toContain('Conversation transcript omitted.')
            ->not->toContain('my card number is 4242 4242 4242 4242')
            ->not->toContain('Do not export this transcript');

        return true;
    });
});

test('GitLab issue creation failure is audited without storing credentials or leaking response details', function (): void {
    $fixture = gitlabOutboundIssueFixture(projectOverrides: [
        'project_key' => 'secret-group/private-project',
    ]);
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://gitlab.com/api/v4/projects/secret-group%2Fprivate-project/issues' => Http::response([
            'message' => '404 Project Not Found: secret-group/private-project',
        ], 404),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/gitlab", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors(['external_issue' => 'GitLab issue could not be created.']);

    $this->assertDatabaseCount('ticket_external_links', 0);

    $auditEvent = AuditEvent::query()
        ->where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->where('action', 'ticket.external_sync_failed')
        ->firstOrFail();

    expect($auditEvent->metadata)
        ->toMatchArray([
            'provider' => 'gitlab',
            'project_key' => 'secret-group/private-project',
            'status' => 404,
            'message' => 'GitLab issue creation failed.',
        ])
        ->and(json_encode($auditEvent->metadata))->not->toContain('glpat_test_secret')
        ->and(json_encode($auditEvent->metadata))->not->toContain('404 Project Not Found');
});

test('GitLab connection failures are audited without crashing the agent request', function (): void {
    $fixture = gitlabOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://gitlab.com/api/v4/projects/adamgreenwell%2Fwayfindr/issues' => fn () => throw new ConnectionException('GitLab timed out.'),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/gitlab", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHasErrors('external_issue');

    $this->assertDatabaseCount('ticket_external_links', 0);

    $auditEvent = AuditEvent::query()
        ->where('subject_type', Ticket::class)
        ->where('subject_id', $ticket->id)
        ->where('action', 'ticket.external_sync_failed')
        ->firstOrFail();

    expect($auditEvent->metadata)
        ->toMatchArray([
            'provider' => 'gitlab',
            'project_key' => 'adamgreenwell/wayfindr',
            'status' => null,
            'message' => 'GitLab request failed before a response was received.',
        ])
        ->and(json_encode($auditEvent->metadata))->not->toContain('glpat_test_secret');
});

test('agent cannot create a GitLab issue through another account project mapping', function (): void {
    $fixture = gitlabOutboundIssueFixture();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherConnection = ExternalIssueProviderConnection::factory()
        ->for($otherAccount)
        ->create(['provider' => 'gitlab']);
    $otherProject = SiteExternalIssueProject::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->for($otherConnection, 'providerConnection')
        ->create(['project_key' => 'other/private']);

    Http::fake();

    $this->actingAs($fixture['agent'])
        ->post("/dashboard/tickets/{$fixture['ticket']->id}/external-issues/gitlab", [
            'site_external_issue_project_id' => $otherProject->id,
        ])
        ->assertNotFound();

    Http::assertNothingSent();
    $this->assertDatabaseCount('ticket_external_links', 0);
});

test('GitLab issue creation is hidden when the mapped provider cannot create issues', function (): void {
    $fixture = gitlabOutboundIssueFixture([
        'capabilities' => [
            'create_issue' => false,
            'add_comment' => true,
            'sync_status' => false,
        ],
    ]);

    $this->actingAs($fixture['agent'])
        ->get("/dashboard/tickets/{$fixture['ticket']->id}")
        ->assertOk()
        ->assertSee('External links')
        ->assertDontSee('External issue actions')
        ->assertDontSee('Create GitLab issue');
});

/**
 * @return array{account: Account, agent: User, site: Site, visitor: Visitor, conversation: Conversation, ticket: Ticket, project: SiteExternalIssueProject}
 */
function gitlabOutboundIssueFixture(array $connectionOverrides = [], array $projectOverrides = []): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-gl']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-GL01',
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
            'provider' => 'gitlab',
            'name' => 'Engineering GitLab',
            'base_url' => 'https://gitlab.com',
            'credentials' => ['token' => 'glpat_test_secret'],
            'capabilities' => [
                'create_issue' => true,
                'add_comment' => true,
                'sync_status' => false,
            ],
        ], $connectionOverrides));

    $project = SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create(array_replace([
            'project_key' => 'adamgreenwell/wayfindr',
            'project_name' => 'Wayfindr',
            'web_url' => 'https://gitlab.com/adamgreenwell/wayfindr',
        ], $projectOverrides));

    return compact('account', 'agent', 'site', 'visitor', 'conversation', 'ticket', 'project');
}
