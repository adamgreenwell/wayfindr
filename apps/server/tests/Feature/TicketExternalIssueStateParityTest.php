<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Support\ExternalIssueSyncStatus;
use App\Support\TicketExternalIssueState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the SQL external issue state agrees with the PHP one, ticket by ticket', function (): void {
    $account = Account::factory()->create();
    $otherAccount = Account::factory()->create();
    $site = Site::factory()->for($account)->create();

    /** @var array<string, int> $fixtures */
    $fixtures = [];
    $ticket = function (string $label) use ($account, $site, &$fixtures): Ticket {
        $created = Ticket::factory()->for($account)->for($site)->create(['subject' => $label]);
        $fixtures[$label] = $created->id;

        return $created;
    };
    $event = function (Ticket $ticket, string $action, array $metadata, string $occurredAt, ?Account $eventAccount = null): AuditEvent {
        return AuditEvent::factory()
            ->for($eventAccount ?? $ticket->account)
            ->for($ticket, 'subject')
            ->create([
                'action' => $action,
                'metadata' => $metadata,
                'occurred_at' => $occurredAt,
                'site_id' => $ticket->site_id,
            ]);
    };
    $link = function (Ticket $ticket, string $status): TicketExternalLink {
        return TicketExternalLink::factory()
            ->for($ticket->account)
            ->for($ticket->site)
            ->for($ticket)
            ->create(['sync_status' => $status]);
    };

    $ticket('empty');

    $failedLink = $ticket('failed link');
    $link($failedLink, ExternalIssueSyncStatus::FAILED);

    $pendingLink = $ticket('pending link');
    $link($pendingLink, ExternalIssueSyncStatus::PENDING);

    $linkedLink = $ticket('linked link');
    $link($linkedLink, ExternalIssueSyncStatus::LINKED);

    $failedBeatsPending = $ticket('failed beats pending');
    $link($failedBeatsPending, ExternalIssueSyncStatus::PENDING);
    $link($failedBeatsPending, ExternalIssueSyncStatus::FAILED);

    $pendingBeatsLinked = $ticket('pending beats linked');
    $link($pendingBeatsLinked, ExternalIssueSyncStatus::LINKED);
    $link($pendingBeatsLinked, ExternalIssueSyncStatus::PENDING);

    $unresolvedFailure = $ticket('unresolved failure');
    $event($unresolvedFailure, 'ticket.external_sync_failed', [
        'provider' => 'github',
        'project_key' => 'acme/api',
        'site_external_issue_project_id' => 10,
    ], '2026-09-02 10:00:00');

    $resolvedFailure = $ticket('resolved failure');
    $event($resolvedFailure, 'ticket.external_sync_failed', [
        'provider' => 'github',
        'project_key' => 'acme/api',
        'site_external_issue_project_id' => 11,
    ], '2026-09-02 10:00:00');
    $event($resolvedFailure, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'different-key-does-not-matter-when-both-ids-exist',
        'site_external_issue_project_id' => 11,
        'external_key' => '#11',
    ], '2026-09-02 10:01:00');

    $wrongSuccess = $ticket('wrong success does not resolve failure');
    $event($wrongSuccess, 'ticket.external_sync_failed', [
        'provider' => 'github',
        'site_external_issue_project_id' => 12,
    ], '2026-09-02 10:00:00');
    $event($wrongSuccess, 'ticket.external_issue_created', [
        'provider' => 'gitlab',
        'site_external_issue_project_id' => 12,
        'external_key' => '#12',
    ], '2026-09-02 10:01:00');

    $missingFailureProject = $ticket('failure without project cannot be resolved');
    $event($missingFailureProject, 'ticket.external_sync_failed', [
        'provider' => 'github',
        'project_key' => 'acme/missing-id',
    ], '2026-09-02 10:00:00');
    $event($missingFailureProject, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'acme/missing-id',
        'external_key' => '#13',
    ], '2026-09-02 10:01:00');

    $creation = $ticket('current creation');
    $event($creation, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'acme/current',
        'site_external_issue_project_id' => 20,
        'external_key' => '#20',
    ], '2026-09-02 10:00:00');

    $removedById = $ticket('creation removed by project id');
    $event($removedById, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'acme/old-key',
        'site_external_issue_project_id' => 21,
        'external_key' => '#21',
    ], '2026-09-02 10:00:00');
    $event($removedById, 'ticket.external_link_removed', [
        'provider' => 'github',
        'project_key' => 'acme/new-key',
        'site_external_issue_project_id' => 21,
        'external_key' => '#21',
    ], '2026-09-02 10:01:00');

    $removedByKey = $ticket('creation removed by project key fallback');
    $event($removedByKey, 'ticket.external_issue_created', [
        'provider' => 'gitlab',
        'project_key' => 'acme/key-fallback',
        'external_id' => '22',
    ], '2026-09-02 10:00:00');
    $event($removedByKey, 'ticket.external_link_removed', [
        'provider' => 'gitlab',
        'project_key' => 'acme/key-fallback',
        'external_id' => '22',
    ], '2026-09-02 10:01:00');

    $removedWithoutReference = $ticket('creation removed without a reference');
    $event($removedWithoutReference, 'ticket.external_issue_created', [
        'provider' => 'jira',
        'project_key' => 'OPS',
    ], '2026-09-02 10:00:00');
    $event($removedWithoutReference, 'ticket.external_link_removed', [
        'provider' => 'jira',
        'project_key' => 'OPS',
    ], '2026-09-02 10:01:00');

    $referenceAllowsMissingProject = $ticket('matching reference allows a missing project');
    $event($referenceAllowsMissingProject, 'ticket.external_issue_created', [
        'provider' => 'github',
        'external_key' => '#23',
    ], '2026-09-02 10:00:00');
    $event($referenceAllowsMissingProject, 'ticket.external_link_removed', [
        'provider' => 'github',
        'external_key' => '#23',
    ], '2026-09-02 10:01:00');

    $missingEverythingDoesNotMatch = $ticket('missing reference and project does not match');
    $event($missingEverythingDoesNotMatch, 'ticket.external_issue_created', [
        'provider' => 'github',
    ], '2026-09-02 10:00:00');
    $event($missingEverythingDoesNotMatch, 'ticket.external_link_removed', [
        'provider' => 'github',
    ], '2026-09-02 10:01:00');

    $differentIdsDoNotFallBack = $ticket('different project ids do not fall back to matching keys');
    $event($differentIdsDoNotFallBack, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'acme/same',
        'site_external_issue_project_id' => 24,
        'external_key' => '#24',
    ], '2026-09-02 10:00:00');
    $event($differentIdsDoNotFallBack, 'ticket.external_link_removed', [
        'provider' => 'github',
        'project_key' => 'acme/same',
        'site_external_issue_project_id' => 25,
        'external_key' => '#24',
    ], '2026-09-02 10:01:00');

    $olderRemoval = $ticket('older removal does not cancel creation');
    $event($olderRemoval, 'ticket.external_link_removed', [
        'provider' => 'github',
        'project_key' => 'acme/time',
        'external_key' => '#26',
    ], '2026-09-02 09:59:00');
    $event($olderRemoval, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'acme/time',
        'external_key' => '#26',
    ], '2026-09-02 10:00:00');

    $sameTimeRemoval = $ticket('same-time later id cancels creation');
    $event($sameTimeRemoval, 'ticket.external_issue_created', [
        'provider' => 'github',
        'project_key' => 'acme/tie',
        'external_key' => '#27',
    ], '2026-09-02 10:00:00');
    $event($sameTimeRemoval, 'ticket.external_link_removed', [
        'provider' => 'github',
        'project_key' => 'acme/tie',
        'external_key' => '#27',
    ], '2026-09-02 10:00:00');

    $removalOnly = $ticket('removal only is none');
    $event($removalOnly, 'ticket.external_link_removed', [
        'provider' => 'github',
        'project_key' => 'acme/removal-only',
        'external_key' => '#28',
    ], '2026-09-02 10:00:00');

    $wrongAccountEvent = $ticket('another account event is ignored');
    $event($wrongAccountEvent, 'ticket.external_sync_failed', [
        'provider' => 'github',
        'site_external_issue_project_id' => 29,
    ], '2026-09-02 10:00:00', $otherAccount);

    $states = [
        TicketExternalIssueState::FAILED,
        TicketExternalIssueState::PENDING,
        TicketExternalIssueState::LINKED,
        TicketExternalIssueState::NONE,
    ];
    $sqlStates = collect();

    foreach ($states as $state) {
        TicketExternalIssueState::whereState(Ticket::query(), $state)
            ->pluck('id')
            ->each(fn (int $id) => $sqlStates->put($id, $state));
    }

    $tickets = Ticket::query()
        ->with([
            'auditEvents' => fn ($query) => $query->whereIn('action', TicketExternalIssueState::trackedAuditActions()),
            'externalLinks',
        ])
        ->get();
    $disagreements = [];

    foreach ($tickets as $candidate) {
        $php = TicketExternalIssueState::forTicket($candidate);
        $sql = $sqlStates->get($candidate->id);

        if ($php !== $sql) {
            $label = array_search($candidate->id, $fixtures, true) ?: "ticket {$candidate->id}";
            $disagreements[] = "{$label}: PHP said {$php}, SQL said ".($sql ?? 'nothing');
        }
    }

    expect($disagreements)->toBe([], implode('; ', $disagreements));
    expect($sqlStates->values()->unique()->sort()->values()->all())
        ->toBe(collect($states)->sort()->values()->all(), 'the fixture did not exercise every external-issue state');
});
