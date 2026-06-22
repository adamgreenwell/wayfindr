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

test('agent can create a conservative GitHub issue from a mapped ticket', function (): void {
    $fixture = githubOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://api.github.com/repos/adamgreenwell/wayfindr/issues' => Http::response([
            'id' => 987,
            'number' => 123,
            'html_url' => 'https://github.com/adamgreenwell/wayfindr/issues/123',
            'title' => 'Checkout export keeps failing',
        ], 201),
    ]);

    $this->actingAs($agent)
        ->get("/dashboard/tickets/{$ticket->id}")
        ->assertOk()
        ->assertSee('External issue actions')
        ->assertSee('Create GitHub issue');

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/github", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'GitHub issue created.');

    Http::assertSent(function (HttpClientRequest $request) use ($ticket): bool {
        $payload = $request->data();
        $body = (string) data_get($payload, 'body');

        expect($request->method())->toBe('POST')
            ->and((string) $request->url())->toBe('https://api.github.com/repos/adamgreenwell/wayfindr/issues')
            ->and($request->header('Authorization'))->toContain('Bearer ghp_test_secret')
            ->and($request->header('Accept'))->toContain('application/vnd.github+json')
            ->and($request->header('X-GitHub-Api-Version'))->toContain('2022-11-28')
            ->and(data_get($payload, 'title'))->toBe('Checkout export keeps failing')
            ->and($body)->toContain("Wayfindr ticket #{$ticket->id}")
            ->and($body)->toContain('Support code: WF-GH01')
            ->and($body)->toContain('Site: Acme Docs')
            ->and($body)->toContain('Priority: High')
            ->and($body)->toContain('Category: Bug')
            ->and($body)->toContain("/dashboard/tickets/{$ticket->id}")
            ->and($body)->toContain('The visitor cannot export orders after checkout.')
            ->and($body)->toContain('Raw visitor transcripts, cobrowse snapshots, and internal notes were not exported')
            ->and($body)->not->toContain('my card number is 4242 4242 4242 4242')
            ->and($body)->not->toContain('Do not send this internal note')
            ->and($body)->not->toContain('super-secret-cobrowse-token');

        return true;
    });

    $this->assertDatabaseHas('ticket_external_links', [
        'account_id' => $fixture['account']->id,
        'site_id' => $fixture['site']->id,
        'ticket_id' => $ticket->id,
        'provider' => 'github',
        'project_key' => 'adamgreenwell/wayfindr',
        'external_id' => '987',
        'external_key' => '#123',
        'url' => 'https://github.com/adamgreenwell/wayfindr/issues/123',
        'sync_status' => 'linked',
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
        ->assertSee('GitHub issue created')
        ->assertSee('https://github.com/adamgreenwell/wayfindr/issues/123');
});

test('ticket detail previews the conservative GitHub issue export payload', function (): void {
    $fixture = githubOutboundIssueFixture();
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
            'Support code: WF-GH01',
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
        ->not->toContain('ghp_test_secret');
});

test('GitHub issue exports omit conversation generated ticket descriptions', function (): void {
    $fixture = githubOutboundIssueFixture();
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
        'https://api.github.com/repos/adamgreenwell/wayfindr/issues' => Http::response([
            'id' => 987,
            'number' => 123,
            'html_url' => 'https://github.com/adamgreenwell/wayfindr/issues/123',
            'title' => 'Checkout export keeps failing',
        ], 201),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/github", [
            'site_external_issue_project_id' => $project->id,
        ])
        ->assertRedirect("/dashboard/tickets/{$ticket->id}")
        ->assertSessionHas('status', 'GitHub issue created.');

    Http::assertSent(function (HttpClientRequest $request): bool {
        $body = (string) data_get($request->data(), 'body');

        expect($body)
            ->toContain('Conversation transcript omitted.')
            ->not->toContain('my card number is 4242 4242 4242 4242')
            ->not->toContain('Do not export this transcript');

        return true;
    });
});

test('GitHub issue creation failure is audited without storing credentials', function (): void {
    $fixture = githubOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://api.github.com/repos/adamgreenwell/wayfindr/issues' => Http::response([
            'message' => 'Validation Failed',
        ], 422),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/github", [
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
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'status' => 422,
        ])
        ->and(json_encode($auditEvent->metadata))->not->toContain('ghp_test_secret');
});

test('GitHub connection failures are audited without crashing the agent request', function (): void {
    $fixture = githubOutboundIssueFixture();
    $ticket = $fixture['ticket'];
    $project = $fixture['project'];
    $agent = $fixture['agent'];

    Http::fake([
        'https://api.github.com/repos/adamgreenwell/wayfindr/issues' => fn () => throw new ConnectionException('GitHub timed out.'),
    ]);

    $this->actingAs($agent)
        ->from("/dashboard/tickets/{$ticket->id}")
        ->post("/dashboard/tickets/{$ticket->id}/external-issues/github", [
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
            'provider' => 'github',
            'project_key' => 'adamgreenwell/wayfindr',
            'status' => null,
            'message' => 'GitHub request failed before a response was received.',
        ])
        ->and(json_encode($auditEvent->metadata))->not->toContain('ghp_test_secret');
});

test('agent cannot create a GitHub issue through another account project mapping', function (): void {
    $fixture = githubOutboundIssueFixture();
    $otherAccount = Account::factory()->create();
    $otherSite = Site::factory()->for($otherAccount)->create();
    $otherConnection = ExternalIssueProviderConnection::factory()
        ->for($otherAccount)
        ->create(['provider' => 'github']);
    $otherProject = SiteExternalIssueProject::factory()
        ->for($otherAccount)
        ->for($otherSite)
        ->for($otherConnection, 'providerConnection')
        ->create(['project_key' => 'other/private']);

    Http::fake();

    $this->actingAs($fixture['agent'])
        ->post("/dashboard/tickets/{$fixture['ticket']->id}/external-issues/github", [
            'site_external_issue_project_id' => $otherProject->id,
        ])
        ->assertNotFound();

    Http::assertNothingSent();
    $this->assertDatabaseCount('ticket_external_links', 0);
});

test('GitHub issue creation is hidden when the mapped provider cannot create issues', function (): void {
    $fixture = githubOutboundIssueFixture([
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
        ->assertDontSee('Create GitHub issue');
});

/**
 * @return array{account: Account, agent: User, site: Site, visitor: Visitor, conversation: Conversation, ticket: Ticket, project: SiteExternalIssueProject}
 */
function githubOutboundIssueFixture(array $connectionOverrides = []): array
{
    $account = Account::factory()->create(['name' => 'Acme Support']);
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create(['name' => 'Acme Docs']);
    $visitor = Visitor::factory()->for($site)->create(['anonymous_id' => 'anon-gh']);
    $conversation = Conversation::factory()->for($site)->for($visitor)->create([
        'support_code' => 'WF-GH01',
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
            'provider' => 'github',
            'name' => 'Engineering GitHub',
            'base_url' => 'https://api.github.com',
            'credentials' => ['token' => 'ghp_test_secret'],
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
        ->create([
            'project_key' => 'adamgreenwell/wayfindr',
            'project_name' => 'Wayfindr',
            'web_url' => 'https://github.com/adamgreenwell/wayfindr',
        ]);

    return compact('account', 'agent', 'site', 'visitor', 'conversation', 'ticket', 'project');
}
