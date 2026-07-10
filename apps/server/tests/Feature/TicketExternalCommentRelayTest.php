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

function commentRelayFixture(array $connectionOverrides = []): array
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

    $link = TicketExternalLink::factory()->create([
        'account_id' => $account->id,
        'site_id' => $site->id,
        'ticket_id' => $ticket->id,
        'provider' => 'github',
        'project_key' => 'acme/widgets',
        'external_id' => '9001',
        'external_key' => '#42',
        'url' => 'https://github.com/acme/widgets/issues/42',
        'metadata' => ['external_issue_provider_connection_id' => $connection->id],
    ]);

    return compact('account', 'agent', 'site', 'ticket', 'connection', 'link');
}

test('an opted-in note posts to the linked GitHub issue and records the relay', function (): void {
    $f = commentRelayFixture();

    Http::fake([
        'https://api.github.com/repos/acme/widgets/issues/42/comments' => Http::response([
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
