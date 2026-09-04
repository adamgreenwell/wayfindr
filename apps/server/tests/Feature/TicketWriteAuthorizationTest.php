<?php

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\CustomRole;
use App\Models\ExternalIssueProviderConnection;
use App\Models\Site;
use App\Models\SiteExternalIssueProject;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\TicketLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('ordinary ticket mutations reauthorize a stale custom role under the account lock', function (string $action): void {
    $account = Account::factory()->create();
    $ticketRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $ticketRole->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $ticket = Ticket::factory()->for($account)->for($site)->create([
        'subject' => 'Original subject',
        'status' => 'open',
    ]);
    $label = TicketLabel::factory()->for($account)->create();
    $ticket->labels()->attach($label);
    $externalLink = TicketExternalLink::factory()
        ->for($account)
        ->for($site)
        ->for($ticket)
        ->create();

    $this->actingAs($agent);
    expect($agent->hasAccountPermission(AccountPermission::ManageTickets))->toBeTrue();
    User::query()->whereKey($agent->id)->update(['custom_role_id' => $revokedRole->id]);

    $before = $ticket->fresh()->getRawOriginal();

    $response = match ($action) {
        'note' => $this->post(route('dashboard.tickets.notes.store', $ticket), ['body' => 'Late note']),
        'add label' => $this->post(route('dashboard.tickets.labels.store', $ticket), ['label_name' => 'Late label']),
        'remove label' => $this->delete(route('dashboard.tickets.labels.destroy', [$ticket, $label])),
        'update' => $this->put(route('dashboard.tickets.update', $ticket), [
            'subject' => 'Changed subject',
            'priority' => 'high',
        ]),
        'pending' => $this->post(route('dashboard.tickets.pending', $ticket)),
        'close' => $this->post(route('dashboard.tickets.close', $ticket)),
        'reopen' => $this->post(route('dashboard.tickets.reopen', $ticket)),
        'add external link' => $this->post(route('dashboard.tickets.external-links.store', $ticket), [
            'provider' => 'github',
            'project_key' => 'late/project',
            'url' => 'https://example.test/late/project',
        ]),
        'remove external link' => $this->delete(route('dashboard.tickets.external-links.destroy', [$ticket, $externalLink])),
    };

    $response->assertNotFound();

    expect($ticket->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('ticket_labels', 1);
    $this->assertDatabaseHas('ticket_label_ticket', [
        'ticket_id' => $ticket->id,
        'ticket_label_id' => $label->id,
    ]);
    $this->assertDatabaseCount('ticket_external_links', 1);
    $this->assertDatabaseHas('ticket_external_links', ['id' => $externalLink->id]);
    $this->assertDatabaseCount('audit_events', 0);
})->with([
    'note',
    'add label',
    'remove label',
    'update',
    'pending',
    'close',
    'reopen',
    'add external link',
    'remove external link',
]);

test('external issue creation reauthorizes a stale custom role before provider delivery', function (string $provider): void {
    Http::fake();
    $account = Account::factory()->create();
    $ticketRole = CustomRole::factory()->for($account)->create([
        'permissions' => [AccountPermission::ManageTickets->value],
    ]);
    $revokedRole = CustomRole::factory()->for($account)->create(['permissions' => []]);
    $agent = User::factory()->for($account)->create([
        'account_role' => AccountRole::Agent,
        'custom_role_id' => $ticketRole->id,
    ]);
    $site = Site::factory()->for($account)->create();
    $site->supportAgents()->attach($agent);
    $ticket = Ticket::factory()->for($account)->for($site)->create();
    $connection = ExternalIssueProviderConnection::factory()->for($account)->create([
        'provider' => $provider,
        'capabilities' => ['create_issue' => true],
    ]);
    $project = SiteExternalIssueProject::factory()
        ->for($account)
        ->for($site)
        ->for($connection, 'providerConnection')
        ->create();

    $this->actingAs($agent);
    expect($agent->hasAccountPermission(AccountPermission::ManageTickets))->toBeTrue();
    User::query()->whereKey($agent->id)->update(['custom_role_id' => $revokedRole->id]);

    $this->post(route('dashboard.tickets.external-issues.'.$provider.'.store', $ticket), [
        'site_external_issue_project_id' => $project->id,
    ])->assertNotFound();

    Http::assertNothingSent();
    $this->assertDatabaseCount('ticket_external_links', 0);
    $this->assertDatabaseCount('audit_events', 0);
})->with(['github', 'gitlab', 'jira']);
