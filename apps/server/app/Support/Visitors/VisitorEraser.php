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
use App\Models\ConversationRating;
use App\Models\OutboundWebhookDelivery;
use App\Models\Site;
use App\Models\SlaClock;
use App\Models\Ticket;
use App\Models\TicketExternalLink;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorErasure;
use App\Models\VisitorIdentityAlias;
use App\Models\VisitorNote;
use App\Support\DashboardLanguage;
use App\Support\LiteralLike;
use App\Support\ProactiveMessages\ProactiveVisitorKey;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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
     * How erasure treats every table that can hold data derived from one
     * visitor. The keys are table names; the contract test fails for any
     * foreign key or polymorphic column reaching a visitor, conversation,
     * message, attachment, cobrowse session or ticket from a table not here.
     *
     * @var array<string, string>
     */
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

    public const COVERAGE = [
        'visitors' => 'deleted',
        'visitor_identity_aliases' => 'deleted by cascade from the visitor',
        'visitor_notes' => 'deleted by cascade from the visitor',
        'cobrowse_sessions' => 'deleted by cascade from the visitor and conversation',
        'conversations' => 'deleted',
        'conversation_messages' => 'deleted by cascade from the conversation',
        'conversation_message_attachments' => 'deleted by cascade; binaries removed after commit',
        'conversation_reply_deliveries' => 'deleted by cascade from the message',
        'conversation_ratings' => 'deleted by cascade from the conversation',
        'conversation_read_states' => 'deleted by cascade from the conversation',
        'conversation_copilot_summaries' => 'deleted by cascade from the conversation',
        'conversation_copilot_reply_drafts' => 'deleted by cascade from the conversation',
        'conversation_copilot_ticket_suggestions' => 'deleted by cascade from the conversation',
        'conversation_copilot_knowledge_suggestions' => 'deleted by cascade from the conversation',
        'proactive_message_deliveries' => 'deleted, by visitor and by keyed browser digest',
        'tickets' => 'kept as a work item, with the person stripped out (ADR 0026 §3)',
        'ticket_label_ticket' => 'kept with the ticket: label links only',
        'ticket_external_links' => 'kept with the ticket: the external issue is out of reach (§6)',
        'ticket_external_comment_deliveries' => 'deleted for stripped tickets: they hold note bodies',
        'audit_events' => 'kept with metadata replaced and any visitor actor cleared (§2)',
        'notifications' => 'deleted when they name an erased conversation or stripped ticket',
        'agent_alert_deliveries' => 'deleted by cascade from the notification',
        'sla_clocks' => 'deleted for erased conversations; kept for stripped tickets',
        'sla_alert_deliveries' => 'deleted by cascade from the clock; unsent ones for stripped tickets cancelled',
        'automation_rule_executions' => 'deleted for erased conversations; kept for stripped tickets, with the raw error message cleared',
        'outbound_webhook_deliveries' => 'pending deliveries for erased conversations cancelled; the response sample cleared on every delivery about them; payloads are identifiers only',
        'conversation_bulk_action_runs' => 'kept for undo; the saved queue search cleared on runs that touched an erased conversation',
        'ticket_bulk_action_runs' => 'kept for undo; the saved queue search cleared on runs that touched a stripped ticket',
        'break_glass_grants' => 'kept: the record of operator access outweighs its incidental reason text (§4); its trail stops naming an erased conversation',
        'push_subscriptions' => 'not visitor data: agent devices only',
        'api_idempotency_keys' => 'kept: hashes and a resource id only, never a body, and expired rows are pruned',
        'visitor_erasures' => 'the ledger itself: identifiers and counts only',
        'failed_jobs' => 'rows naming the person by email, host ID, browser ID or support code deleted; other failed-job text is diagnostics, like logs (§6)',
    ];

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
        $scope = $this->scope($visitor);

        return [
            'counts' => $scope['counts'],
            'tickets' => Ticket::query()
                ->whereIn('id', $scope['ticket_ids'])
                ->orderBy('id')
                ->get(['id', 'subject', 'status']),
            'external_issue_urls' => TicketExternalLink::query()
                ->whereIn('ticket_id', $scope['ticket_ids'])
                ->whereNotNull('url')
                ->orderBy('id')
                ->pluck('url')
                ->map(fn (mixed $url): string => (string) $url)
                ->values()
                ->all(),
        ];
    }

    public function erase(User $actor, Visitor $visitor): VisitorErasure
    {
        [$receipt, $site, $erasedId] = DB::transaction(function () use ($actor, $visitor): array {
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
            // take a shared lock on the site, so none lands mid-erasure.
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

            $scope = $this->scope($visitor);
            // Before the scrub, which replaces the metadata that holds it.
            $mergedIds = $this->mergedVisitorIds($visitor);
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
            $breakGlassGrants = $this->idsIn(
                BreakGlassGrant::query()->where('account_id', $accountId),
                'conversation_id',
                $scope['conversation_ids'],
            );

            $this->refuseWhileNotesArePosting($scope['ticket_ids']);
            $this->refuseWhileCopilotIsRunning($scope['conversation_ids']);

            // Collected before the rows go: the cascade takes the only record
            // of where each binary lives. Kept on the receipt below, not only
            // in memory, so a crash after the commit cannot lose them.
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

            $this->stripTickets($scope['ticket_ids']);
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
            $this->deleteFailedJobsNaming([
                (string) $visitor->email,
                (string) $visitor->external_id,
                ...$scope['anonymous_ids'],
                ...$scope['support_codes'],
            ]);

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

            $counts = [
                ...$scope['counts'],
                'notifications' => $notifications,
                'proactive_deliveries' => $proactive,
                'webhook_deliveries_cancelled' => $cancelled,
                'audit_events_scrubbed' => $audited,
            ];

            $receipt = VisitorErasure::query()->create([
                'public_id' => (string) Str::uuid(),
                'account_id' => $accountId,
                'site_id' => $site->id,
                'erased_visitor_id' => $erasedId,
                'merged_visitor_ids' => $mergedIds,
                'actor_id' => $actor->id,
                'counts' => $counts,
                'pending_files' => $pendingFiles === [] ? null : $pendingFiles,
                'erased_at' => now(),
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
                'subject_id' => $erasedId,
                'action' => 'visitor.erased',
                'metadata' => [
                    'receipt' => $receipt->public_id,
                    'erased' => $counts,
                ],
                'occurred_at' => now(),
            ]);

            return [$receipt, $site, $erasedId];
        });

        // After the commit, as in the site purge: deleting first would leave
        // live rows pointing at missing files. What cannot be removed now
        // stays on the receipt for wayfindr:finish-erasures.
        $this->removePendingFiles($receipt);

        // The erased row may be on an agent's live board. With the row gone
        // the event carries only its removal. A realtime failure never undoes
        // the durable erasure; the board's periodic resync is the fallback.
        try {
            $gone = new Visitor;
            $gone->forceFill(['id' => $erasedId, 'site_id' => $site->id]);
            event(new VisitorPresenceUpdated($site, $gone, $erasedId));
        } catch (\Throwable $e) {
            report($e);
        }

        return $receipt;
    }

    /**
     * Remove the binaries an erasure left on its receipt, keeping any that
     * storage would not delete for the next attempt. Returns how many remain.
     */
    public function removePendingFiles(VisitorErasure $erasure): int
    {
        $remaining = [];

        foreach ($erasure->pending_files ?? [] as $file) {
            try {
                $disk = Storage::disk((string) ($file['disk'] ?? ''));
                $key = (string) ($file['key'] ?? '');

                if ($key !== '' && $disk->exists($key) && ! $disk->delete($key)) {
                    $remaining[] = $file;
                }
            } catch (\Throwable $e) {
                report($e);
                $remaining[] = $file;
            }
        }

        $erasure->forceFill(['pending_files' => $remaining === [] ? null : $remaining])->save();

        return count($remaining);
    }

    /**
     * Everything the erasure touches, as identifiers.
     *
     * @return array{
     *     conversation_ids: list<int>,
     *     message_ids: list<int>,
     *     attachment_ids: list<int>,
     *     cobrowse_ids: list<int>,
     *     ticket_ids: list<int>,
     *     support_codes: list<string>,
     *     anonymous_ids: list<string>,
     *     visitor_id: int,
     *     counts: array<string, int>
     * }
     */
    private function scope(Visitor $visitor): array
    {
        $visitorId = (int) $visitor->id;
        $conversations = Conversation::query()
            ->where('visitor_id', $visitorId)
            ->get(['id', 'support_code']);
        $conversationIds = $this->ints($conversations->pluck('id'));

        $messageIds = $this->idsIn(ConversationMessage::query(), 'conversation_id', $conversationIds);
        $attachmentIds = $this->idsIn(ConversationMessageAttachment::query(), 'conversation_id', $conversationIds);
        $cobrowseIds = $this->ints(CobrowseSession::query()
            ->where('visitor_id', $visitorId)
            ->pluck('id')
            ->merge($this->idsIn(CobrowseSession::query(), 'conversation_id', $conversationIds))
            ->unique());
        $ticketIds = $this->ints(Ticket::query()
            ->where('site_id', $visitor->site_id)
            ->where('requester_id', $visitorId)
            ->pluck('id')
            ->merge($this->idsIn(Ticket::query()->where('site_id', $visitor->site_id), 'conversation_id', $conversationIds))
            ->unique()
            ->sort());

        $anonymousIds = VisitorIdentityAlias::query()
            ->where('visitor_id', $visitorId)
            ->pluck('anonymous_id')
            ->push($visitor->anonymous_id)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'conversation_ids' => $conversationIds,
            'message_ids' => $messageIds,
            'attachment_ids' => $attachmentIds,
            'cobrowse_ids' => $cobrowseIds,
            'ticket_ids' => $ticketIds,
            'support_codes' => $conversations->pluck('support_code')->filter()->map(fn (mixed $code): string => (string) $code)->values()->all(),
            'anonymous_ids' => $anonymousIds,
            'visitor_id' => $visitorId,
            'counts' => [
                'conversations' => count($conversationIds),
                'messages' => count($messageIds),
                'attachments' => count($attachmentIds),
                'ratings' => $this->countIn(ConversationRating::query(), 'conversation_id', $conversationIds),
                'notes' => VisitorNote::query()->where('visitor_id', $visitorId)->count(),
                'cobrowse_sessions' => count($cobrowseIds),
                'tickets_stripped' => count($ticketIds),
            ],
        ];
    }

    /**
     * Every visitor ID merged into this one (§8). A merge deletes the source
     * row and re-anchors its audit events onto the target, so a chain of
     * merges ends with every `visitor.merged` event on this visitor. The
     * aliases' own history is read as well: each keeps only its last IDs,
     * but it is the record the widget follows.
     *
     * @return list<int>
     */
    private function mergedVisitorIds(Visitor $visitor): array
    {
        $visitorId = (int) $visitor->id;

        $fromAudit = AuditEvent::query()
            ->where('subject_type', $visitor->getMorphClass())
            ->where('subject_id', $visitorId)
            ->where('action', 'visitor.merged')
            ->get(['metadata'])
            ->map(fn (AuditEvent $event): mixed => data_get($event->metadata, 'source_visitor_id'));
        $fromAliases = VisitorIdentityAlias::query()
            ->where('visitor_id', $visitorId)
            ->get(['previous_visitor_ids'])
            ->flatMap(fn (VisitorIdentityAlias $alias): array => is_array($alias->previous_visitor_ids) ? $alias->previous_visitor_ids : []);

        return $fromAudit->merge($fromAliases)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id <= 0 || $id === $visitorId)
            ->unique()
            ->sort()
            ->values()
            ->all();
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

        $tickets = Ticket::query()->whereIn('id', $ticketIds)->lockForUpdate()->get(['id', 'metadata']);
        // Stored content, seen by every agent and API consumer: the install's
        // language, not that of the agent who happens to erase.
        $subjectLocale = DashboardLanguage::forStoredContent();

        foreach ($tickets as $ticket) {
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
        $scrubbed = 0;

        foreach ($subjects as $type => $ids) {
            $this->whereInChunks(
                DB::table('audit_events')->where('subject_type', $type),
                'subject_id',
                $ids,
                function (Builder $query) use ($erased, &$scrubbed): void {
                    $scrubbed += $query->update(['metadata' => $erased]);
                },
            );
        }

        // The visitor as actor: a reply they sent, a rating they left.
        $scrubbed += DB::table('audit_events')
            ->where('actor_type', (new Visitor)->getMorphClass())
            ->where('actor_id', $scope['visitor_id'])
            ->update(['metadata' => $erased, 'actor_type' => null, 'actor_id' => null]);

        return $scrubbed;
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
        if ($scope['conversation_ids'] === [] && $scope['ticket_ids'] === []) {
            return 0;
        }

        $conversations = array_flip($scope['conversation_ids']);
        $tickets = array_flip($scope['ticket_ids']);
        $userIds = User::query()->where('account_id', $accountId)->pluck('id');
        $doomed = [];

        DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('notifiable_id', $userIds)
            ->select(['id', 'data'])
            ->chunkById(500, function (Collection $notifications) use ($conversations, $tickets, &$doomed): void {
                foreach ($notifications as $notification) {
                    $conversationId = (int) data_get($notification->data, 'conversation_id');
                    $ticketId = (int) data_get($notification->data, 'ticket_id');

                    if (isset($conversations[$conversationId]) || isset($tickets[$ticketId])) {
                        $doomed[] = $notification->id;
                    }
                }
            }, 'id');

        foreach (array_chunk($doomed, 500) as $ids) {
            DatabaseNotification::query()->whereIn('id', $ids)->delete();
        }

        return count($doomed);
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
     * the work, which may be the person's name or email. The runs name their
     * items only inside JSON, read in PHP like the notifications.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $ticketIds
     */
    private function clearBulkRunSearches(int $accountId, array $conversationIds, array $ticketIds): void
    {
        foreach ([
            ['conversation_bulk_action_runs', 'conversation_id', 'conversation_search', $conversationIds],
            ['ticket_bulk_action_runs', 'ticket_id', 'ticket_search', $ticketIds],
        ] as [$table, $itemKey, $searchKey, $ids]) {
            if ($ids === []) {
                continue;
            }

            $erased = array_flip($ids);

            DB::table($table)
                ->where('account_id', $accountId)
                ->whereNotNull('return_query')
                ->select(['id', 'changes', 'return_query'])
                ->chunkById(500, function (Collection $runs) use ($table, $itemKey, $searchKey, $erased): void {
                    foreach ($runs as $run) {
                        $query = json_decode((string) $run->return_query, true);
                        $changes = json_decode((string) $run->changes, true);

                        if (! is_array($query) || ! array_key_exists($searchKey, $query) || ! is_array($changes)) {
                            continue;
                        }

                        $touched = collect($changes)->contains(
                            fn (mixed $change): bool => isset($erased[(int) data_get($change, $itemKey)]),
                        );

                        if ($touched) {
                            unset($query[$searchKey]);
                            DB::table($table)->where('id', $run->id)->update([
                                'return_query' => json_encode($query, JSON_THROW_ON_ERROR),
                            ]);
                        }
                    }
                });
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
        if ($supportCodes === [] && $ticketIds === []) {
            return;
        }

        $codes = array_flip($supportCodes);
        $tickets = array_flip($ticketIds);

        OutboundWebhookDelivery::query()
            ->where('site_id', $siteId)
            ->whereNotNull('response_body')
            ->select(['id', 'payload'])
            ->chunkById(500, function (EloquentCollection $deliveries) use ($codes, $tickets): void {
                $ids = $deliveries
                    ->filter(function (OutboundWebhookDelivery $delivery) use ($codes, $tickets): bool {
                        $code = data_get($delivery->payload, 'resource.support_code')
                            ?? data_get($delivery->payload, 'resource.conversation_support_code');

                        if (is_string($code) && isset($codes[$code])) {
                            return true;
                        }

                        return data_get($delivery->payload, 'resource.type') === 'ticket'
                            && isset($tickets[(int) data_get($delivery->payload, 'resource.id')]);
                    })
                    ->pluck('id')
                    ->all();

                if ($ids !== []) {
                    OutboundWebhookDelivery::query()->whereIn('id', $ids)->update(['response_body' => null]);
                }
            });
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
     * it refused. Both are free text, so the rows are found by the person's
     * own identifiers. One too short to be specific (a host ID like "42")
     * would match strangers, so it is not used.
     *
     * @param  list<string>  $identifiers
     */
    private function deleteFailedJobsNaming(array $identifiers): void
    {
        $table = (string) (config('queue.failed.table') ?: 'failed_jobs');
        $identifiers = array_values(array_unique(array_filter(
            $identifiers,
            fn (string $identifier): bool => mb_strlen(trim($identifier)) >= 6,
        )));

        if ($identifiers === [] || ! Schema::hasTable($table)) {
            return;
        }

        foreach ($identifiers as $identifier) {
            $query = DB::table($table);
            $grammar = $query->getGrammar();
            $pattern = LiteralLike::pattern(trim($identifier));

            $query->where(function (Builder $query) use ($grammar, $pattern): void {
                foreach (['payload', 'exception'] as $column) {
                    $query->orWhereRaw('LOWER('.$grammar->wrap($column).') LIKE LOWER(?) ESCAPE ?', [$pattern, '\\']);
                }
            })->delete();
        }
    }

    /**
     * Rows naming the visitor, and rows already detached from a pruned
     * presence row but still keyed by this person's browser.
     *
     * @param  list<string>  $anonymousIds
     */
    private function deleteProactiveDeliveries(int $siteId, int $visitorId, array $anonymousIds): int
    {
        $keys = array_map(
            fn (string $anonymousId): string => ProactiveVisitorKey::for($siteId, $anonymousId),
            $anonymousIds,
        );

        return DB::table('proactive_message_deliveries')
            ->where('site_id', $siteId)
            ->where(function (Builder $query) use ($visitorId, $keys): void {
                $query->where('visitor_id', $visitorId);

                if ($keys !== []) {
                    $query->orWhereIn('visitor_key', $keys);
                }
            })
            ->delete();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @param  list<int>  $values
     * @return list<int>
     */
    private function idsIn(\Illuminate\Database\Eloquent\Builder $query, string $column, array $values): array
    {
        $ids = [];

        foreach (array_chunk($values, 500) as $chunk) {
            $ids = [...$ids, ...$this->ints((clone $query)->whereIn($column, $chunk)->pluck('id'))];
        }

        return $ids;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Model>  $query
     * @param  list<int>  $values
     */
    private function countIn(\Illuminate\Database\Eloquent\Builder $query, string $column, array $values): int
    {
        $count = 0;

        foreach (array_chunk($values, 500) as $chunk) {
            $count += (clone $query)->whereIn($column, $chunk)->count();
        }

        return $count;
    }

    /**
     * Run a statement against an id list in chunks, so a long history stays
     * under every driver's bound-parameter limit.
     *
     * @param  list<int>  $values
     * @param  callable(Builder): mixed  $statement
     */
    private function whereInChunks(Builder $query, string $column, array $values, callable $statement): void
    {
        foreach (array_chunk($values, 500) as $chunk) {
            $statement((clone $query)->whereIn($column, $chunk));
        }
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<int>
     */
    private function ints(Collection $values): array
    {
        return $values->map(fn (mixed $value): int => (int) $value)->values()->all();
    }
}
