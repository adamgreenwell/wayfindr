<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Enums\AccountPermission;
use App\Events\VisitorPresenceUpdated;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\OutboundWebhookDelivery;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorErasure;
use App\Support\DashboardLanguage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Erase one visitor and everything that exists because of them (ADR 0026).
 *
 * A plain `$visitor->delete()` succeeds and leaves the person behind: tickets
 * keep a copy of the transcript, audit metadata keeps note bodies, agent
 * notifications keep message previews, and a database cascade never fires the
 * hook that removes an attachment's file. This class is the one place that
 * knows every store, and {@see self::COVERAGE} is the map a contract test
 * holds against the live schema, so a new table cannot quietly reach a
 * visitor without saying what erasure does to it.
 */
final class VisitorEraser
{
    /**
     * How recently a started call to an outside service may still be under
     * way: a note post to a linked issue (§3), or a copilot request to the AI
     * provider (§1). Each worker commits its start, then makes the call
     * outside any lock, and only its job's own timeout bounds it; this is
     * twice the longest of those timeouts, rounded up.
     */
    public const IN_FLIGHT_WINDOW_SECONDS = 180;

    /** The copilot tables whose rows carry a started request, by conversation. */
    private const COPILOT_TABLES = [
        'conversation_copilot_summaries',
        'conversation_copilot_reply_drafts',
        'conversation_copilot_ticket_suggestions',
        'conversation_copilot_knowledge_suggestions',
    ];

    /**
     * How erasure treats every table that can hold data derived from one
     * visitor, and where the export puts it or why it leaves it out (§7). The
     * keys are table names; the contract test fails for any foreign key or
     * polymorphic column reaching a visitor, conversation, message,
     * attachment, cobrowse session or ticket from a table not here. An export
     * entry is `file#key` (several separated by commas), or `not exported:`
     * and the reason.
     *
     * @var array<string, array{erasure: string, export: string}>
     */
    public const COVERAGE = [
        'visitors' => [
            'erasure' => 'deleted',
            'export' => 'visitor.json#visitor',
        ],
        'visitor_identity_aliases' => [
            'erasure' => 'deleted by cascade from the visitor',
            'export' => 'visitor.json#browser_ids',
        ],
        'visitor_notes' => [
            'erasure' => 'deleted by cascade from the visitor',
            'export' => 'visitor.json#notes',
        ],
        'cobrowse_sessions' => [
            'erasure' => 'deleted by cascade from the visitor and conversation',
            'export' => 'conversations/*.json#cobrowse_sessions, cobrowse.json#sessions',
        ],
        'conversations' => [
            'erasure' => 'deleted',
            'export' => 'conversations/*.json#conversation',
        ],
        'conversation_messages' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'conversations/*.json#messages',
        ],
        'conversation_message_attachments' => [
            'erasure' => 'deleted by cascade; binaries removed after commit',
            'export' => 'conversations/*.json#attachments',
        ],
        'conversation_reply_deliveries' => [
            'erasure' => 'deleted by cascade from the message',
            'export' => 'conversations/*.json#reply_deliveries',
        ],
        'conversation_ratings' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'conversations/*.json#ratings',
        ],
        'conversation_read_states' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'not exported: which agent last read a thread is bookkeeping (§7)',
        ],
        'conversation_copilot_summaries' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'conversations/*.json#copilot_summaries',
        ],
        'conversation_copilot_reply_drafts' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'conversations/*.json#copilot_reply_drafts',
        ],
        'conversation_copilot_ticket_suggestions' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'conversations/*.json#copilot_ticket_suggestions',
        ],
        'conversation_copilot_knowledge_suggestions' => [
            'erasure' => 'deleted by cascade from the conversation',
            'export' => 'conversations/*.json#copilot_knowledge_suggestions',
        ],
        'proactive_message_deliveries' => [
            'erasure' => 'deleted, by visitor and by keyed browser digest',
            'export' => 'proactive.json#deliveries',
        ],
        'tickets' => [
            'erasure' => 'kept as a work item, with the person stripped out (ADR 0026 §3)',
            'export' => 'tickets/*.json#ticket',
        ],
        'ticket_label_ticket' => [
            'erasure' => 'kept with the ticket: label links only',
            'export' => 'not exported: links to the team\'s own labels, which hold nothing about the person',
        ],
        'ticket_external_links' => [
            'erasure' => 'kept with the ticket: the external issue is out of reach (§6)',
            'export' => 'tickets/*.json#external_links',
        ],
        'ticket_external_comment_deliveries' => [
            'erasure' => 'deleted for stripped tickets: they hold note bodies',
            'export' => 'tickets/*.json#note_deliveries',
        ],
        'audit_events' => [
            'erasure' => 'kept with metadata replaced and any visitor actor cleared (§2)',
            'export' => 'audit.json#events, tickets/*.json#notes, break_glass.json#views',
        ],
        'notifications' => [
            'erasure' => 'deleted when they name an erased conversation or stripped ticket; alert mail built before the erasure is refused before SMTP',
            'export' => 'alerts.json#alerts',
        ],
        'agent_alert_deliveries' => [
            'erasure' => 'deleted by cascade from the notification',
            'export' => 'not exported: which channel an alert went out on, and when: bookkeeping that goes with the alert',
        ],
        'sla_clocks' => [
            'erasure' => 'deleted for erased conversations; kept for stripped tickets',
            'export' => 'not exported: SLA clocks are bookkeeping (§7)',
        ],
        'sla_alert_deliveries' => [
            'erasure' => 'deleted by cascade from the clock; unsent ones for stripped tickets cancelled',
            'export' => 'not exported: SLA alert bookkeeping (§7)',
        ],
        'automation_rule_executions' => [
            'erasure' => 'deleted for erased conversations; kept for stripped tickets, with the raw error message cleared',
            'export' => 'incidental.json#automation_runs',
        ],
        'outbound_webhook_deliveries' => [
            'erasure' => 'pending deliveries for erased conversations cancelled; the response sample cleared on every delivery about them; payloads are identifiers only',
            'export' => 'incidental.json#webhook_responses',
        ],
        'conversation_bulk_action_runs' => [
            'erasure' => 'kept for undo; the saved queue search cleared on runs that selected an erased conversation, and on older runs that cannot say',
            'export' => 'incidental.json#conversation_searches',
        ],
        'ticket_bulk_action_runs' => [
            'erasure' => 'kept for undo; the saved queue search cleared on runs that selected a stripped ticket, and on older runs that cannot say',
            'export' => 'incidental.json#ticket_searches',
        ],
        'break_glass_grants' => [
            'erasure' => 'kept: the record of operator access outweighs its incidental reason text (§4); its trail stops naming an erased conversation',
            'export' => 'break_glass.json#grants',
        ],
        'push_subscriptions' => [
            'erasure' => 'not visitor data: agent devices only',
            'export' => 'not exported: agent devices only',
        ],
        'api_idempotency_keys' => [
            'erasure' => 'kept: hashes and a resource id only, never a body, and expired rows are pruned',
            'export' => 'not exported: hashes and a resource id only, never a body',
        ],
        'visitor_erasures' => [
            'erasure' => 'the ledger itself: identifiers and counts only',
            'export' => 'not exported: a contact that can be exported has not been erased',
        ],
        'alert_mail_sends' => [
            'erasure' => 'alert mail on its way to SMTP, by identifier only: erasure waits while one about the person is fresh, and removes the rest',
            'export' => 'not exported: alert mail on its way to SMTP, by identifier only',
        ],
        'failed_jobs' => [
            'erasure' => 'rows naming the person by email address or support code, whole, deleted, in whichever store is configured (a table on any connection, a file, DynamoDB); a reply that fails for good, which can land after that sweep, records its error type only; other failed-job text is diagnostics, like logs (§6)',
            'export' => 'incidental.json#failed_jobs',
        ],
    ];

    use QueriesIdsInChunks;

    public function __construct(
        private readonly ErasureLedger $ledger,
        private readonly VisitorFootprint $footprint,
    ) {}

    /**
     * What erasing this visitor would do, for the confirmation screen.
     *
     * @return array{
     *     counts: array<string, int>,
     *     tickets: EloquentCollection<int, Ticket>,
     *     external_issue_urls: list<string>
     * }
     */
    public function summarize(Visitor $visitor): array
    {
        $scope = $this->footprint->scope($visitor);
        $tickets = new EloquentCollection;
        $urls = [];

        // In chunks, like the erasure: a long history must not outgrow the
        // driver's bound-parameter limit on the page that confirms it.
        foreach (array_chunk($scope['ticket_ids'], 500) as $chunk) {
            $tickets = $tickets->merge(Ticket::query()->whereIn('id', $chunk)->get(['id', 'subject', 'status']));
            $urls += TicketExternalLink::query()
                ->whereIn('ticket_id', $chunk)
                ->whereNotNull('url')
                ->pluck('url', 'id')
                ->all();
        }

        ksort($urls);

        return [
            'counts' => $scope['counts'],
            'tickets' => $tickets->sortBy('id')->values(),
            'external_issue_urls' => array_values(array_map(fn (mixed $url): string => (string) $url, $urls)),
        ];
    }

    public function erase(User $actor, Visitor $visitor): VisitorErasure
    {
        $receiptId = (string) Str::uuid();
        // The transaction's last statement. An exception before it rolled the
        // erasure back; one after it came from the commit, which may have
        // happened all the same, and only the database can settle that.
        $bodyCompleted = false;

        try {
            [$receipt, $site, $erasedId] = DB::transaction(function () use ($actor, $visitor, $receiptId, &$bodyCompleted): array {
                // The contact merge's lock order, so the two can never deadlock:
                // account, actor, site, then the visitor rows.
                $accountId = (int) $actor->account_id;
                Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
                $actor = User::query()
                    ->whereKey($actor->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless($actor->hasAccountPermission(AccountPermission::HandleDataRequests), 403);

                // Exclusive: public widget writes that create visitor-owned rows
                // take a shared lock on the site, so none lands mid-erasure. It is
                // also what a reconciliation of this erasure's ledger entry waits
                // for (§8).
                $site = Site::query()
                    ->whereKey($visitor->site_id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $visitor = Visitor::query()
                    ->whereKey($visitor->id)
                    ->where('site_id', $site->id)
                    ->lockForUpdate()
                    ->first();

                abort_unless($visitor instanceof Visitor, 404);
                abort_unless(Gate::forUser($actor)->allows('view', $visitor), 404);

                $erasedAt = now();
                $erased = $this->removeVisitor(
                    $accountId,
                    $site,
                    $visitor,
                    function (array $mergedIds, array $pendingFiles) use ($receiptId, $accountId, $site, $visitor, $actor, $erasedAt): void {
                        // On the volume before anything changes, and flushed
                        // there, so an erasure the database commits is never
                        // missing from the ledger a restore reads. If it cannot
                        // be written, nothing is erased.
                        try {
                            $this->ledger->writePending(ErasureLedger::entry(
                                $receiptId,
                                $accountId,
                                $site,
                                (int) $visitor->id,
                                $mergedIds,
                                (int) $actor->id,
                                $erasedAt->toIso8601ZuluString(),
                                $pendingFiles,
                            ));
                        } catch (\Throwable $e) {
                            report($e);

                            throw ValidationException::withMessages([
                                'confirmation' => __('visitor_erasure.errors.ledger_unwritable'),
                            ]);
                        }
                    },
                );

                $receipt = VisitorErasure::query()->create([
                    'public_id' => $receiptId,
                    'account_id' => $accountId,
                    'site_id' => $site->id,
                    'site_public_key' => $site->public_key,
                    'erased_visitor_id' => $erased['visitor_id'],
                    'merged_visitor_ids' => $erased['merged_ids'],
                    'actor_id' => $actor->id,
                    'counts' => $erased['counts'],
                    'pending_files' => $erased['pending_files'] === [] ? null : $erased['pending_files'],
                    'erased_at' => $erasedAt,
                ]);

                // Written after the scrub, so it is not scrubbed itself. Counts and
                // the receipt only: the record that the erasure happened must not
                // become a record of who was erased.
                AuditEvent::query()->create([
                    'account_id' => $accountId,
                    'site_id' => $site->id,
                    'actor_type' => $actor->getMorphClass(),
                    'actor_id' => $actor->id,
                    'subject_type' => $visitor->getMorphClass(),
                    'subject_id' => $erased['visitor_id'],
                    'action' => 'visitor.erased',
                    'metadata' => [
                        'receipt' => $receipt->public_id,
                        'erased' => $erased['counts'],
                    ],
                    'occurred_at' => now(),
                ]);

                $bodyCompleted = true;

                return [$receipt, $site, $erased['visitor_id']];
            });
        } catch (\Throwable $e) {
            // Rolled back for certain, so its entry goes now. A failed commit
            // leaves the entry pending for reconciliation to settle.
            if (! $bodyCompleted) {
                try {
                    $this->ledger->discard($receiptId);
                } catch (\Throwable $discardFailure) {
                    report($discardFailure);
                }
            }

            throw $e;
        }

        // Committed, so the erasure has happened: a rename that fails here is
        // reported, not returned. The pending entry already holds everything,
        // and reconciliation promotes it.
        try {
            $this->ledger->promote($receiptId);
        } catch (\Throwable $e) {
            report($e);
        }

        // After the commit, as in the site purge: deleting first would leave
        // live rows pointing at missing files. What cannot be removed now
        // stays on the receipt for wayfindr:finish-erasures. The erasure has
        // happened by here, so a failure is reported, not returned: the
        // receipt still lists every file until it is shortened.
        try {
            $this->removePendingFiles($receiptId);
        } catch (\Throwable $e) {
            report($e);
        }

        $this->announceRemoval($site, $erasedId);

        return $receipt->refresh();
    }

    /**
     * Erase again every visitor a ledger entry names that the database holds
     * (§8): after a restore brought back rows from before the erasure, under
     * the erased visitor's ID or that of anyone merged into them. Returns how
     * many visitors it erased; none, when the database holds none of them.
     *
     * The ledger row goes back with them, under the same receipt, so later
     * backups carry it: the restored database may predate it.
     *
     * @param  array<string, mixed>  $entry
     */
    public function reapply(array $entry): int
    {
        $receiptId = (string) ($entry['receipt'] ?? '');
        $lineage = ErasureLedger::lineage($entry);

        if ($receiptId === '' || $lineage === []) {
            return 0;
        }

        $reapplied = DB::transaction(function () use ($entry, $receiptId, $lineage): ?array {
            $site = $this->siteOf($entry);

            if ($site === null) {
                return null;
            }

            // The erasure's lock order: account, site, then the visitors.
            Account::query()->whereKey($site->account_id)->lockForUpdate()->firstOrFail();
            $site = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $visitors = Visitor::query()
                ->where('site_id', $site->id)
                ->whereIn('id', $lineage)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($visitors->isEmpty()) {
                return null;
            }

            $accountId = (int) $site->account_id;
            $counts = [];
            $pendingFiles = [];

            foreach ($visitors as $visitor) {
                $erased = $this->removeVisitor($accountId, $site, $visitor, static function (): void {});
                $pendingFiles = [...$pendingFiles, ...$erased['pending_files']];

                foreach ($erased['counts'] as $key => $count) {
                    $counts[$key] = ($counts[$key] ?? 0) + $count;
                }
            }

            // Listed on the row in this transaction, as an erasure does, so a
            // crash after the commit cannot lose them.
            $receipt = VisitorErasure::query()->where('public_id', $receiptId)->lockForUpdate()->first();
            $files = ErasureLedger::files([...($receipt?->pending_files ?? []), ...$pendingFiles]);

            if ($receipt === null) {
                $actorId = is_int($entry['actor_id'] ?? null) ? $entry['actor_id'] : null;
                $receipt = VisitorErasure::query()->create([
                    'public_id' => $receiptId,
                    'account_id' => $accountId,
                    'site_id' => $site->id,
                    'site_public_key' => $site->public_key,
                    'erased_visitor_id' => (int) $entry['erased_visitor_id'],
                    'merged_visitor_ids' => array_values(array_diff($lineage, [(int) $entry['erased_visitor_id']])),
                    // The agent who erased may postdate the restored database.
                    'actor_id' => $actorId !== null && User::query()->whereKey($actorId)->where('account_id', $accountId)->exists() ? $actorId : null,
                    'counts' => $counts,
                    'pending_files' => $files === [] ? null : $files,
                    'erased_at' => Carbon::parse((string) $entry['erased_at']),
                ]);
            } else {
                $receipt->forceFill(['pending_files' => $files === [] ? null : $files])->save();
            }

            // The system acted, under the same receipt: counts only, like the
            // erasure's own event, which the restored database may not hold.
            AuditEvent::query()->create([
                'account_id' => $accountId,
                'site_id' => $site->id,
                'actor_type' => null,
                'actor_id' => null,
                'subject_type' => (new Visitor)->getMorphClass(),
                'subject_id' => (int) $entry['erased_visitor_id'],
                'action' => 'visitor.erasure_reapplied',
                'metadata' => [
                    'receipt' => $receiptId,
                    'erased' => $counts,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'site' => $site,
                'visitor_ids' => $this->ints($visitors->pluck('id')),
                'files' => $pendingFiles,
            ];
        });

        if ($reapplied === null) {
            return 0;
        }

        // The volume lists them too: a later restore takes the row back to
        // whatever its archive holds.
        try {
            $this->ledger->updatePendingFiles($receiptId, fn (array $files): array => [...$files, ...$reapplied['files']]);
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            $this->removePendingFiles($receiptId);
        } catch (\Throwable $e) {
            report($e);
        }

        foreach ($reapplied['visitor_ids'] as $visitorId) {
            $this->announceRemoval($reapplied['site'], $visitorId);
        }

        return count($reapplied['visitor_ids']);
    }

    /**
     * Remove the binaries an erasure still lists, keeping any that storage
     * would not delete for the next attempt, and strike each removed one off
     * the ledger row and the volume entry both. Either may list what the
     * other does not: the volume survives a restore, the row survives a lost
     * volume, and a file already gone is simply struck off. Returns how many
     * remain.
     */
    public function removePendingFiles(string $receiptId): int
    {
        $entry = $this->ledger->find($receiptId);
        // A restored archive may predate the ledger table; the volume still
        // lists what to remove.
        $hasTable = $this->ledger->databaseCanAnswer();
        $row = $hasTable ? VisitorErasure::query()->where('public_id', $receiptId)->first(['id', 'pending_files']) : null;
        $removed = [];
        $remaining = 0;

        foreach (ErasureLedger::files([...($entry['pending_files'] ?? []), ...($row?->pending_files ?? [])]) as $file) {
            try {
                $disk = Storage::disk($file['disk']);

                if ($disk->exists($file['key']) && ! $disk->delete($file['key'])) {
                    $remaining++;

                    continue;
                }

                $removed[$file['disk']."\0".$file['key']] = true;
            } catch (\Throwable $e) {
                report($e);
                $remaining++;
            }
        }

        if ($removed === []) {
            return $remaining;
        }

        $kept = fn (mixed $files): array => array_values(array_filter(
            ErasureLedger::files($files),
            fn (array $file): bool => ! isset($removed[$file['disk']."\0".$file['key']]),
        ));

        if ($entry !== null) {
            $this->ledger->updatePendingFiles($receiptId, $kept);
        }

        if ($hasTable) {
            DB::transaction(function () use ($receiptId, $kept): void {
                $row = VisitorErasure::query()->where('public_id', $receiptId)->lockForUpdate()->first();
                $left = $kept($row?->pending_files ?? []);
                $row?->forceFill(['pending_files' => $left === [] ? null : $left])->save();
            });
        }

        return $remaining;
    }

    /**
     * The site an entry's visitors are on, when it is this install's. An
     * archive from another install can hold a site, and visitors, under the
     * same IDs, so the site is found by its public key, which is random per
     * site, and must also have the recorded ID when there is one. An entry
     * that records no key cannot prove its site, and is never re-applied:
     * erasing someone else's contact is the worse mistake.
     *
     * @param  array<string, mixed>  $entry
     */
    private function siteOf(array $entry): ?Site
    {
        $key = $entry['site_public_key'] ?? null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        $site = Site::query()->where('public_key', $key)->first();

        if ($site === null || (is_int($entry['site_id'] ?? null) && (int) $site->id !== $entry['site_id'])) {
            return null;
        }

        return $site;
    }

    /**
     * The erased row may be on an agent's live board. With the row gone the
     * event carries only its removal. A realtime failure never undoes the
     * durable erasure; the board's periodic resync is the fallback.
     */
    private function announceRemoval(Site $site, int $visitorId): void
    {
        try {
            $gone = new Visitor;
            $gone->forceFill(['id' => $visitorId, 'site_id' => $site->id]);
            event(new VisitorPresenceUpdated($site, $gone, $visitorId));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The erasure itself, under the account, site and visitor locks its
     * caller holds. $beforeChanging is called once everything to remove is
     * known and nothing has changed yet, with the visitor IDs merged into this
     * one and the binaries to remove after the commit.
     *
     * @param  callable(list<int>, list<array{disk: string, key: string}>): void  $beforeChanging
     * @return array{visitor_id: int, merged_ids: list<int>, pending_files: list<array{disk: string, key: string}>, counts: array<string, int>}
     */
    private function removeVisitor(int $accountId, Site $site, Visitor $visitor, callable $beforeChanging): array
    {
        $scope = $this->footprint->scope($visitor);
        // Before the scrub, which replaces the metadata that holds it.
        $mergedIds = $this->footprint->mergedVisitorIds($visitor);
        // Before anything that scans for what names them: an alert or a
        // break-glass view stored under a shared lock on one of these rows
        // now lands either before this point, and is found, or after the
        // commit, when it can see the erasure.
        $this->whereInChunks(
            DB::table('conversations'),
            'id',
            $scope['conversation_ids'],
            fn (Builder $query) => $query->lockForUpdate()->get(['id']),
        );
        $breakGlassGrants = $this->footprint->breakGlassGrantIds($accountId, $scope['conversation_ids']);

        $this->refuseWhileNotesArePosting($scope['ticket_ids']);
        $this->refuseWhileCopilotIsRunning($scope['conversation_ids']);

        // Collected before the rows go: the cascade takes the only record
        // of where each binary lives. Kept on the receipt, not only in
        // memory, so a crash after the commit cannot lose them.
        $pendingFiles = [];
        $this->whereInChunks(
            DB::table('conversation_message_attachments'),
            'conversation_id',
            $scope['conversation_ids'],
            function (Builder $query) use (&$pendingFiles): void {
                foreach ($query->whereNotNull('storage_key')->get(['storage_disk', 'storage_key']) as $file) {
                    $pendingFiles[] = ['disk' => (string) $file->storage_disk, 'key' => (string) $file->storage_key];
                }
            },
        );

        $beforeChanging($mergedIds, $pendingFiles);

        $this->stripTickets($scope['ticket_ids']);
        // After stripTickets, which locks the tickets, as the conversations
        // are locked above: an alert mail past its pre-SMTP check is on
        // record by now, and one that is not will see the erasure.
        $this->refuseWhileAlertMailIsSending($scope['conversation_ids'], $scope['ticket_ids']);
        $audited = $this->scrubAuditEvents($scope);
        // SLA deliveries before the alerts they belong to: the check every
        // alert mail makes just before SMTP locks them in that order, so
        // the two cannot deadlock. That check is also what stops a send a
        // worker has already claimed, once these rows are gone.
        $this->deleteConversationBookkeeping($scope['conversation_ids']);
        $this->cancelTicketSlaDeliveries($scope['ticket_ids']);
        $notifications = $this->deleteNotifications($accountId, $scope);
        $this->clearTicketAutomationErrors($scope['ticket_ids']);
        $this->clearBulkRunSearches($accountId, $scope['conversation_ids'], $scope['ticket_ids']);
        $cancelled = $this->cancelPendingWebhooks((int) $site->id, $scope['support_codes']);
        $this->clearWebhookResponses((int) $site->id, $scope['support_codes'], $scope['ticket_ids']);
        $proactive = $this->deleteProactiveDeliveries((int) $site->id, (int) $visitor->id, $scope['anonymous_ids']);
        $this->deleteFailedJobsNaming($this->footprint->installWideIdentifiers($visitor, $scope['support_codes']));

        $this->whereInChunks(
            DB::table('conversations'),
            'id',
            $scope['conversation_ids'],
            fn (Builder $query) => $query->delete(),
        );

        // After the conversations go, so a view recorded while they were
        // locked is relabelled too; the grants were collected before the
        // delete nulled their conversation.
        $this->relabelBreakGlassTrail($accountId, $scope['conversation_ids'], $breakGlassGrants);

        $erasedId = (int) $visitor->id;
        DB::table('visitors')->where('id', $erasedId)->delete();

        return [
            'visitor_id' => $erasedId,
            'merged_ids' => $mergedIds,
            'pending_files' => $pendingFiles,
            'counts' => [
                ...$scope['counts'],
                'notifications' => $notifications,
                'proactive_deliveries' => $proactive,
                'webhook_deliveries_cancelled' => $cancelled,
                'audit_events_scrubbed' => $audited,
            ],
        ];
    }

    /**
     * Keep each ticket as a work item and remove the person from it (§3):
     * the subject may be their words and nothing records whether it is, the
     * description of a dashboard ticket is a copy of the transcript, and the
     * metadata carries their page addresses, host context and support code.
     *
     * @param  list<int>  $ticketIds
     */
    private function stripTickets(array $ticketIds): void
    {
        if ($ticketIds === []) {
            return;
        }

        // Stored content, seen by every agent and API consumer: the install's
        // language, not that of the agent who happens to erase.
        $subjectLocale = DashboardLanguage::forStoredContent();

        foreach (array_chunk($ticketIds, 500) as $chunk) {
            foreach (Ticket::query()->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get(['id', 'metadata']) as $ticket) {
                $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
                unset($metadata['visitor_context'], $metadata['support_code']);
                $metadata['requester_erased'] = true;

                // Raw, like the merge: an Eloquent save would fire ticket side
                // effects (automations, webhooks) for what is not a support change.
                DB::table('tickets')->where('id', $ticket->id)->update([
                    'subject' => __('visitor_erasure.ticket_subject', ['number' => $ticket->id], $subjectLocale),
                    'description' => null,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'requester_id' => null,
                    'conversation_id' => null,
                    'updated_at' => now(),
                ]);
            }
        }

        // Each row carries the body of a note that was, or was about to be,
        // posted to the linked provider. Removing them also stops a pending
        // one from being sent after the erasure.
        $this->whereInChunks(
            DB::table('ticket_external_comment_deliveries'),
            'ticket_id',
            $ticketIds,
            fn (Builder $query) => $query->delete(),
        );
    }

    /**
     * No note may reach a tracker after the erasure completes (§3). Every
     * delivery for these tickets is locked here, the row lock the worker
     * claims with, so none can start between this check and the delete. One
     * that started within the window may be mid-post, and cannot be recalled.
     *
     * @param  list<int>  $ticketIds
     */
    private function refuseWhileNotesArePosting(array $ticketIds): void
    {
        $posting = false;

        $this->whereInChunks(
            DB::table('ticket_external_comment_deliveries'),
            'ticket_id',
            $ticketIds,
            function (Builder $query) use (&$posting): void {
                $cutoff = now()->subSeconds(self::IN_FLIGHT_WINDOW_SECONDS);
                $underWay = $query->lockForUpdate()
                    ->get(['started_at', 'accepted_at', 'delivered_at', 'failed_at'])
                    ->contains(fn (object $delivery): bool => $delivery->started_at !== null
                        && $delivery->accepted_at === null
                        && $delivery->delivered_at === null
                        && $delivery->failed_at === null
                        && Carbon::parse($delivery->started_at)->greaterThan($cutoff));

                $posting = $posting || $underWay;
            },
        );

        if ($posting) {
            throw ValidationException::withMessages([
                'confirmation' => __('visitor_erasure.errors.note_posting'),
            ]);
        }
    }

    /**
     * A running copilot request may be sending the transcript to the AI
     * provider right now (§1). Its job claims the row before calling out, so
     * locking the rows here means none can start between this check and the
     * cascade that deletes them; one that started within the window may still
     * be under way, and cannot be recalled.
     *
     * @param  list<int>  $conversationIds
     */
    private function refuseWhileCopilotIsRunning(array $conversationIds): void
    {
        $cutoff = now()->subSeconds(self::IN_FLIGHT_WINDOW_SECONDS);
        $running = false;

        foreach (self::COPILOT_TABLES as $table) {
            $this->whereInChunks(
                DB::table($table),
                'conversation_id',
                $conversationIds,
                function (Builder $query) use ($cutoff, &$running): void {
                    $underWay = $query->lockForUpdate()
                        ->get(['status', 'started_at'])
                        ->contains(fn (object $request): bool => $request->status === 'running'
                            && $request->started_at !== null
                            && Carbon::parse($request->started_at)->greaterThan($cutoff));

                    $running = $running || $underWay;
                },
            );
        }

        if ($running) {
            throw ValidationException::withMessages([
                'confirmation' => __('visitor_erasure.errors.copilot_running'),
            ]);
        }
    }

    /**
     * An alert mail past its check before SMTP may carry what this erasure is
     * about to remove, and the check's lock ends before the transport runs,
     * so each such send is on record until the mail server has it. Refused
     * while one about this person's work is fresh, like a note or a copilot
     * call in flight. Older records are sends that never reported back: they
     * hold only an identifier and a time, and go with the erasure.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $ticketIds
     */
    private function refuseWhileAlertMailIsSending(array $conversationIds, array $ticketIds): void
    {
        $cutoff = now()->subSeconds(self::IN_FLIGHT_WINDOW_SECONDS);
        $sending = false;

        foreach ([
            [(new Conversation)->getMorphClass(), $conversationIds],
            [(new Ticket)->getMorphClass(), $ticketIds],
        ] as [$type, $ids]) {
            $this->whereInChunks(
                DB::table('alert_mail_sends')->where('subject_type', $type),
                'subject_id',
                $ids,
                function (Builder $query) use ($cutoff, &$sending): void {
                    $sending = $sending || (clone $query)->where('started_at', '>', $cutoff)->exists();
                    $query->delete();
                },
            );
        }

        if ($sending) {
            throw ValidationException::withMessages([
                'confirmation' => __('visitor_erasure.errors.alert_mail_sending'),
            ]);
        }
    }

    /**
     * Keep who did what, lose what was said (§2).
     *
     * @param  array{visitor_id: int, conversation_ids: list<int>, message_ids: list<int>, attachment_ids: list<int>, cobrowse_ids: list<int>, ticket_ids: list<int>}  $scope
     */
    private function scrubAuditEvents(array $scope): int
    {
        $subjects = [
            (new Visitor)->getMorphClass() => [$scope['visitor_id']],
            (new Conversation)->getMorphClass() => $scope['conversation_ids'],
            (new ConversationMessage)->getMorphClass() => $scope['message_ids'],
            (new ConversationMessageAttachment)->getMorphClass() => $scope['attachment_ids'],
            (new CobrowseSession)->getMorphClass() => $scope['cobrowse_ids'],
            (new Ticket)->getMorphClass() => $scope['ticket_ids'],
        ];
        $erased = json_encode(['erased' => true], JSON_THROW_ON_ERROR);
        // Keyed by event: a reply the visitor sent is about their conversation
        // and has them as its actor, and is one event scrubbed, not two.
        $scrubbed = [];

        foreach ($subjects as $type => $ids) {
            $this->whereInChunks(
                DB::table('audit_events')->where('subject_type', $type),
                'subject_id',
                $ids,
                function (Builder $query) use ($erased, &$scrubbed): void {
                    $scrubbed += array_fill_keys($this->ints((clone $query)->pluck('id')), true);
                    $query->update(['metadata' => $erased]);
                },
            );
        }

        // The visitor as actor: a reply they sent, a rating they left.
        $asActor = DB::table('audit_events')
            ->where('actor_type', (new Visitor)->getMorphClass())
            ->where('actor_id', $scope['visitor_id']);
        $scrubbed += array_fill_keys($this->ints((clone $asActor)->pluck('id')), true);
        $asActor->update(['metadata' => $erased, 'actor_type' => null, 'actor_id' => null]);

        return count($scrubbed);
    }

    /**
     * Agent inbox rows about the person: they hold a preview of the
     * message, the subject and the browser ID. They name their conversation
     * or ticket only inside JSON, which is read the way the alert code reads
     * it, so this works on every supported database.
     *
     * @param  array{conversation_ids: list<int>, ticket_ids: list<int>}  $scope
     */
    private function deleteNotifications(int $accountId, array $scope): int
    {
        $ids = $this->footprint->notificationIds($accountId, $scope['conversation_ids'], $scope['ticket_ids']);

        foreach (array_chunk($ids, 500) as $chunk) {
            DatabaseNotification::query()->whereIn('id', $chunk)->delete();
        }

        return count($ids);
    }

    /**
     * Rows that point at a conversation with no foreign key, and so would
     * outlive it. Stripped tickets keep theirs: they are the work item's
     * history, and hold no content.
     *
     * @param  list<int>  $conversationIds
     */
    private function deleteConversationBookkeeping(array $conversationIds): void
    {
        $type = (new Conversation)->getMorphClass();

        foreach (['sla_clocks', 'automation_rule_executions'] as $table) {
            $this->whereInChunks(
                DB::table($table)->where('subject_type', $type),
                'subject_id',
                $conversationIds,
                fn (Builder $query) => $query->delete(),
            );
        }
    }

    /**
     * A stripped ticket keeps its SLA clocks, and with them any alert not yet
     * sent. A mail worker may already hold one, with the ticket's original
     * subject in memory; the check it makes just before SMTP refuses a
     * cancelled delivery, so cancelling them here stops that send (§1).
     *
     * @param  list<int>  $ticketIds
     */
    private function cancelTicketSlaDeliveries(array $ticketIds): void
    {
        $clockIds = $this->idsIn(
            SlaClock::query()->where('subject_type', (new Ticket)->getMorphClass()),
            'subject_id',
            $ticketIds,
        );

        $this->whereInChunks(
            DB::table('sla_alert_deliveries')
                ->whereNull('started_at')
                ->whereNull('accepted_at')
                ->whereNull('failed_at')
                ->whereNull('cancelled_at'),
            'sla_clock_id',
            $clockIds,
            fn (Builder $query) => $query->update(['cancelled_at' => now(), 'updated_at' => now()]),
        );
    }

    /**
     * A stripped ticket keeps its executions as the work item's history (§1):
     * identifiers, outcomes and a copy of the rule's own text. The error
     * message is the exception's own text, and a failed query quotes its
     * values, so it can hold the ticket's or a message's content.
     *
     * @param  list<int>  $ticketIds
     */
    private function clearTicketAutomationErrors(array $ticketIds): void
    {
        $this->whereInChunks(
            DB::table('automation_rule_executions')
                ->where('subject_type', (new Ticket)->getMorphClass())
                ->whereNotNull('error_message'),
            'subject_id',
            $ticketIds,
            fn (Builder $query) => $query->update(['error_message' => null]),
        );
    }

    /**
     * Runs never expire and can be undone at any time, so they stay (§4).
     * The queue search saved for the way back is what the agent typed to find
     * the work, which may be the person's name or email. A run found the
     * person if it selected one of their items, changed or not: `item_ids`
     * lists the selection, `changes` only what changed. A run from before
     * `item_ids` cannot say what it skipped, so one that skipped anything
     * loses its search too. The runs name their items only inside JSON, read
     * in PHP like the notifications.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $ticketIds
     */
    private function clearBulkRunSearches(int $accountId, array $conversationIds, array $ticketIds): void
    {
        foreach ($this->footprint->bulkRunsSelecting($accountId, $conversationIds, $ticketIds) as $run) {
            $query = $run['return_query'];
            unset($query[$run['search_key']]);

            DB::table($run['table'])->where('id', $run['id'])->update([
                'return_query' => json_encode($query, JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * The break-glass trail keeps its reason and who acted (§4), but names a
     * conversation by its support code. Once the conversation is gone the
     * grant itself reads "Conversation (deleted)", so the labels stored in its
     * events are brought into line. The trail is account-homed and names the
     * conversation only inside JSON, so it is read in PHP.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $grantIds  grants scoped to those conversations
     */
    private function relabelBreakGlassTrail(int $accountId, array $conversationIds, array $grantIds): void
    {
        if ($conversationIds === []) {
            return;
        }

        $erased = array_flip($conversationIds);
        $grants = array_flip($grantIds);
        $label = 'Conversation (deleted)';

        DB::table('audit_events')
            ->where('account_id', $accountId)
            ->where('subject_type', (new BreakGlassGrant)->getMorphClass())
            ->select(['id', 'subject_id', 'metadata'])
            ->chunkById(500, function (Collection $events) use ($erased, $grants, $label): void {
                foreach ($events as $event) {
                    $metadata = json_decode((string) $event->metadata, true);

                    if (! is_array($metadata)) {
                        continue;
                    }

                    $relabelled = $metadata;

                    if (isset($grants[(int) $event->subject_id]) && array_key_exists('scope_label', $metadata)) {
                        $relabelled['scope_label'] = $label;
                    }

                    if (($metadata['resource_type'] ?? null) === 'conversation'
                        && isset($erased[(int) ($metadata['resource_id'] ?? 0)])) {
                        $relabelled['resource_label'] = $label;
                    }

                    if ($relabelled !== $metadata) {
                        DB::table('audit_events')->where('id', $event->id)->update([
                            'metadata' => json_encode($relabelled, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }

    /**
     * Every delivery about the person keeps its payload, which is identifiers
     * only (ADR 0020), but not its response sample: up to 4 KB of whatever
     * the subscriber replied, which can echo what it fetched about them.
     * Delivered and failed rows as well as pending ones.
     *
     * @param  list<string>  $supportCodes
     * @param  list<int>  $ticketIds
     */
    private function clearWebhookResponses(int $siteId, array $supportCodes, array $ticketIds): void
    {
        $ids = $this->footprint->webhookDeliveryIdsWithResponse($siteId, $supportCodes, $ticketIds);

        foreach (array_chunk($ids, 500) as $chunk) {
            OutboundWebhookDelivery::query()->whereIn('id', $chunk)->update(['response_body' => null]);
        }
    }

    /**
     * A pending delivery would announce a conversation that no longer
     * exists. Payloads are identifiers only (ADR 0020), so delivered history
     * stays; the pending set is small, and read in PHP like the notifications.
     *
     * @param  list<string>  $supportCodes
     */
    private function cancelPendingWebhooks(int $siteId, array $supportCodes): int
    {
        if ($supportCodes === []) {
            return 0;
        }

        $codes = array_flip($supportCodes);
        $ids = OutboundWebhookDelivery::query()
            ->where('site_id', $siteId)
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->whereNull('cancelled_at')
            ->get(['id', 'payload'])
            ->filter(function (OutboundWebhookDelivery $delivery) use ($codes): bool {
                $code = data_get($delivery->payload, 'resource.support_code')
                    ?? data_get($delivery->payload, 'resource.conversation_support_code');

                return is_string($code) && isset($codes[$code]);
            })
            ->pluck('id')
            ->all();

        foreach (array_chunk($ids, 500) as $chunk) {
            OutboundWebhookDelivery::query()->whereIn('id', $chunk)->update(['cancelled_at' => now()]);
        }

        return count($ids);
    }

    /**
     * A job that exhausted its retries is kept with its payload and the
     * exception it died on, and a mail server's rejection quotes the address
     * it refused, so one that names the person goes (§1). A store on another
     * connection or outside the database is not part of this transaction.
     *
     * @param  list<string>  $identifiers  from VisitorFootprint::installWideIdentifiers()
     */
    private function deleteFailedJobsNaming(array $identifiers): void
    {
        $ids = array_column($this->footprint->failedJobsNaming($identifiers), 'id');

        if ($ids === []) {
            return;
        }

        if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $connection = DB::connection(config('queue.failed.database') ?: null);
            $table = (string) (config('queue.failed.table') ?: 'failed_jobs');

            foreach (array_chunk($ids, 500) as $chunk) {
                $connection->table($table)->whereIn('id', $chunk)->delete();
            }

            return;
        }

        $failer = app('queue.failer');

        foreach ($ids as $id) {
            $failer->forget($id);
        }
    }

    /**
     * @param  list<string>  $anonymousIds
     */
    private function deleteProactiveDeliveries(int $siteId, int $visitorId, array $anonymousIds): int
    {
        $ids = $this->footprint->proactiveDeliveryIds($siteId, $visitorId, $anonymousIds);
        $deleted = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $deleted += DB::table('proactive_message_deliveries')->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }
}
