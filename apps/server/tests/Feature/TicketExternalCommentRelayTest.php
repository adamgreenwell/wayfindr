<?php

// Outbound comment relay (#22): an agent's internal note can be opted in, per
// note, to also post as a comment on the linked external issue. Internal notes
// stay internal by default — only a checked note leaves Wayfindr — and the
// connection must have the add_comment capability. GitHub first.

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function commentRelayFixture(array $connectionOverrides = [], array $linkOverrides = []): array
{
    $account = Account::factory()->create();
    $agent = User::factory()->for($account)->create(['name' => 'Ada Agent']);
    $site = Site::factory()->for($account)->create();
    $visitor = Visitor::factory()->for($site)->create();
    $conversation = Conversation::factory()->for($site)->for($visitor)->create(['support_code' => 'WF-CR01']);

    $ticket = Ticket::factory()
        ->for($account)
        ->for($site)
        ->for($conversation)
        ->for($visitor, 'requester')
        ->for($agent, 'assignee')
        ->create(['subject' => 'Export bug']);

    $connection = ExternalIssueProviderConnection::factory()
        ->for($account)
        ->create(array_replace_recursive([
            'provider' => 'github',
            'base_url' => 'https://api.github.com',
            'credentials' => ['token' => 'ghp_test_secret'],
            'capabilities' => ['create_issue' => true, 'add_comment' => true, 'sync_status' => false],
        ], $connectionOverrides));

    $link = TicketExternalLink::factory()->create(array_replace([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'ticket_id' => $ticket->id,
        'provider' => 'github',
        'project_key' => 'acme/widgets',
        'external_id' => '9001',
        'external_key' => '#42',
        'url' => 'https://github.com/acme/widgets/issues/42',
        'metadata' => ['external_issue_provider_connection_id' => $connection->id],
    ], $linkOverrides));

    return compact('account', 'agent', 'site', 'ticket', 'connection', 'link');
}

test('an opted-in note posts to the linked GitHub issue and records the relay', function (): void {
    $f = commentRelayFixture();

    Http::fake([
        'https://api.github.com/repos/acme/widgets/issues/42/comments' => Http::response([
            'id' => 700123,
            'html_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-1',
        ], 201),
    ]);

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Investigating the export path.',
            'post_to_external' => '1',
        ])
        ->assertRedirect("/dashboard/tickets/{$f['ticket']->id}")
        ->assertSessionHas('status', 'Ticket note added and posted to the linked issue.');

    // The relayed comment id is remembered so the inbound webhook won't echo it back.
    expect(data_get($f['link']->fresh()->metadata, 'synced_comment_ids'))->toContain('700123');

    Http::assertSent(function (HttpClientRequest $request): bool {
        expect($request->method())->toBe('POST')
            ->and((string) $request->url())->toBe('https://api.github.com/repos/acme/widgets/issues/42/comments')
            ->and($request->header('Authorization'))->toContain('Bearer ghp_test_secret')
            ->and(data_get($request->data(), 'body'))->toBe('Investigating the export path.');

        return true;
    });

    expect(AuditEvent::where('action', 'ticket.note_added')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'ticket.external_comment_posted')->count())->toBe(1);
});

test('an opted-in note posts to the linked GitLab issue as a note', function (): void {
    $f = commentRelayFixture(
        ['provider' => 'gitlab', 'base_url' => 'https://gitlab.com', 'credentials' => ['token' => 'glpat-secret']],
        ['provider' => 'gitlab', 'project_key' => 'acme/widgets', 'external_id' => '555', 'external_key' => '#7', 'url' => 'https://gitlab.com/acme/widgets/-/issues/7'],
    );

    Http::fake([
        'https://gitlab.com/api/v4/projects/acme%2Fwidgets/issues/7/notes' => Http::response(['id' => 1], 201),
    ]);

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Relaying context to GitLab.',
            'post_to_external' => '1',
        ])
        ->assertSessionHas('status', 'Ticket note added and posted to the linked issue.');

    Http::assertSent(function (HttpClientRequest $request): bool {
        expect($request->method())->toBe('POST')
            ->and((string) $request->url())->toBe('https://gitlab.com/api/v4/projects/acme%2Fwidgets/issues/7/notes')
            ->and($request->header('PRIVATE-TOKEN'))->toContain('glpat-secret')
            ->and(data_get($request->data(), 'body'))->toBe('Relaying context to GitLab.');

        return true;
    });

    expect(AuditEvent::where('action', 'ticket.external_comment_posted')->count())->toBe(1);
});

test('an opted-in note posts to Jira Cloud as an ADF comment over Basic auth', function (): void {
    $f = commentRelayFixture(
        ['provider' => 'jira', 'base_url' => 'https://acme.atlassian.net', 'credentials' => ['token' => 'ops@acme.com:api-token-secret']],
        ['provider' => 'jira', 'project_key' => 'PROJ', 'external_id' => '10001', 'external_key' => 'PROJ-42', 'url' => 'https://acme.atlassian.net/browse/PROJ-42'],
    );

    Http::fake([
        'https://acme.atlassian.net/rest/api/3/issue/PROJ-42/comment' => Http::response(['id' => '1'], 201),
    ]);

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Escalated to engineering.',
            'post_to_external' => '1',
        ])
        ->assertSessionHas('status', 'Ticket note added and posted to the linked issue.');

    Http::assertSent(function (HttpClientRequest $request): bool {
        $expectedBasic = 'Basic '.base64_encode('ops@acme.com:api-token-secret');

        expect((string) $request->url())->toBe('https://acme.atlassian.net/rest/api/3/issue/PROJ-42/comment')
            ->and($request->header('Authorization'))->toContain($expectedBasic)
            // Cloud v3 wants an ADF document, not a plain string.
            ->and(data_get($request->data(), 'body.type'))->toBe('doc')
            ->and(data_get($request->data(), 'body.content.0.content.0.text'))->toBe('Escalated to engineering.');

        return true;
    });

    expect(AuditEvent::where('action', 'ticket.external_comment_posted')->count())->toBe(1);
});

test('an opted-in note posts to Jira Server as a plain comment over Bearer auth', function (): void {
    $f = commentRelayFixture(
        ['provider' => 'jira', 'base_url' => 'https://jira.internal', 'credentials' => ['token' => 'pat-secret-token']],
        ['provider' => 'jira', 'project_key' => 'OPS', 'external_id' => '20002', 'external_key' => 'OPS-7', 'url' => 'https://jira.internal/browse/OPS-7'],
    );

    Http::fake([
        'https://jira.internal/rest/api/2/issue/OPS-7/comment' => Http::response(['id' => '2'], 201),
    ]);

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Server note.',
            'post_to_external' => '1',
        ])
        ->assertSessionHas('status', 'Ticket note added and posted to the linked issue.');

    Http::assertSent(function (HttpClientRequest $request): bool {
        expect((string) $request->url())->toBe('https://jira.internal/rest/api/2/issue/OPS-7/comment')
            ->and($request->header('Authorization'))->toContain('Bearer pat-secret-token')
            // Server/DC v2 takes a plain-text body.
            ->and(data_get($request->data(), 'body'))->toBe('Server note.');

        return true;
    });

    expect(AuditEvent::where('action', 'ticket.external_comment_posted')->count())->toBe(1);
});

test('a note stays internal when the opt-in is not checked', function (): void {
    $f = commentRelayFixture();
    Http::fake();

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Internal context only.',
        ])
        ->assertSessionHas('status', 'Ticket note added.');

    Http::assertNothingSent();
    expect(AuditEvent::where('action', 'ticket.external_comment_posted')->count())->toBe(0);
});

test('the opt-in is unavailable and inert without the add_comment capability', function (): void {
    $f = commentRelayFixture(['capabilities' => ['create_issue' => true, 'add_comment' => false, 'sync_status' => false]]);
    Http::fake();

    $this->actingAs($f['agent'])
        ->get("/dashboard/tickets/{$f['ticket']->id}")
        ->assertOk()
        ->assertDontSee('post this note as a comment on the linked external issue');

    // Even a forged post does nothing when the capability is off.
    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Should not leave.',
            'post_to_external' => '1',
        ])
        ->assertSessionHas('status', 'Ticket note added.');

    Http::assertNothingSent();
});

test('a disabled connection never relays', function (): void {
    $f = commentRelayFixture(['is_enabled' => false]);
    Http::fake();

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'Should not leave.',
            'post_to_external' => '1',
        ])
        ->assertSessionHas('status', 'Ticket note added.');

    Http::assertNothingSent();
});

test('a failed comment is recorded and the note still lands', function (): void {
    $f = commentRelayFixture();

    Http::fake([
        'https://api.github.com/repos/acme/widgets/issues/42/comments' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->actingAs($f['agent'])
        ->from("/dashboard/tickets/{$f['ticket']->id}")
        ->post(route('dashboard.tickets.notes.store', $f['ticket']), [
            'body' => 'This should still be an internal note.',
            'post_to_external' => '1',
        ])
        ->assertSessionHas('status', 'Ticket note added, but the external comment could not be posted. See ticket activity.');

    expect(AuditEvent::where('action', 'ticket.note_added')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'ticket.external_comment_failed')->count())->toBe(1)
        ->and(AuditEvent::where('action', 'ticket.external_comment_posted')->count())->toBe(0);
});

test('the note form offers the opt-in when a commentable link exists', function (): void {
    $f = commentRelayFixture();

    $this->actingAs($f['agent'])
        ->get("/dashboard/tickets/{$f['ticket']->id}")
        ->assertOk()
        ->assertSee('post this note as a comment on the linked external issue');
});
