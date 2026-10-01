<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\AuditEvent;
use App\Models\BreakGlassGrant;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\ConversationRating;
use App\Models\OutboundWebhookDelivery;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorIdentityAlias;
use App\Models\VisitorNote;
use App\Support\LiteralLike;
use App\Support\ProactiveMessages\ProactiveVisitorKey;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where one visitor's data is held, as the rows that hold it (ADR 0026).
 *
 * Erasure removes these rows and export reads them, so both select through
 * here: what one finds, the other finds. Nothing here changes anything.
 */
final class VisitorFootprint
{
    use QueriesIdsInChunks;

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
    public function scope(Visitor $visitor): array
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
    public function mergedVisitorIds(Visitor $visitor): array
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
     * Platform-operator access grants scoped to one of these conversations.
     *
     * @param  list<int>  $conversationIds
     * @return list<int>
     */
    public function breakGlassGrantIds(int $accountId, array $conversationIds): array
    {
        return $this->idsIn(
            BreakGlassGrant::query()->where('account_id', $accountId),
            'conversation_id',
            $conversationIds,
        );
    }

    /**
     * Agent inbox rows about the person: they hold a preview of the
     * message, the subject and the browser ID. They name their conversation
     * or ticket only inside JSON, which is read the way the alert code reads
     * it, so this works on every supported database.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $ticketIds
     * @return list<string>
     */
    public function notificationIds(int $accountId, array $conversationIds, array $ticketIds): array
    {
        if ($conversationIds === [] && $ticketIds === []) {
            return [];
        }

        $conversations = array_flip($conversationIds);
        $tickets = array_flip($ticketIds);
        $found = [];

        DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            // A subquery, not a list: an account's agents are not bounded.
            ->whereIn('notifiable_id', User::query()->select('id')->where('account_id', $accountId))
            ->select(['id', 'data'])
            ->chunkById(500, function (Collection $notifications) use ($conversations, $tickets, &$found): void {
                foreach ($notifications as $notification) {
                    $conversationId = (int) data_get($notification->data, 'conversation_id');
                    $ticketId = (int) data_get($notification->data, 'ticket_id');

                    if (isset($conversations[$conversationId]) || isset($tickets[$ticketId])) {
                        $found[] = (string) $notification->id;
                    }
                }
            }, 'id');

        return $found;
    }

    /**
     * Bulk-action runs whose saved queue search may have found the person
     * (§4): what the agent typed to find the work, which may be their name or
     * email. A run found them if it selected one of their items, changed or
     * not: `item_ids` lists the selection, `changes` only what changed. A run
     * from before `item_ids` cannot say what it skipped, so one that skipped
     * anything may have found them too, and is marked as not attributed:
     * erasure clears it to be safe, and export, which would hand it to the
     * person, leaves it out. The runs name their items only inside JSON, read
     * in PHP like the notifications.
     *
     * @param  list<int>  $conversationIds
     * @param  list<int>  $ticketIds
     * @return list<array{table: string, id: int, search_key: string, return_query: array<string, mixed>, attributed: bool}>
     */
    public function bulkRunsSelecting(int $accountId, array $conversationIds, array $ticketIds): array
    {
        $found = [];

        foreach ([
            ['conversation_bulk_action_runs', 'conversation_id', 'conversation_search', $conversationIds],
            ['ticket_bulk_action_runs', 'ticket_id', 'ticket_search', $ticketIds],
        ] as [$table, $itemKey, $searchKey, $ids]) {
            if ($ids === []) {
                continue;
            }

            $selected = array_flip($ids);

            DB::table($table)
                ->where('account_id', $accountId)
                ->whereNotNull('return_query')
                ->select(['id', 'item_count', 'changed_count', 'changes', 'item_ids', 'return_query'])
                ->chunkById(500, function (Collection $runs) use ($table, $itemKey, $searchKey, $selected, &$found): void {
                    foreach ($runs as $run) {
                        $query = json_decode((string) $run->return_query, true);
                        $changes = json_decode((string) $run->changes, true);

                        if (! is_array($query) || ! array_key_exists($searchKey, $query) || ! is_array($changes)) {
                            continue;
                        }

                        $items = $run->item_ids === null ? null : json_decode((string) $run->item_ids, true);
                        $attributed = collect($changes)->contains(
                            fn (mixed $change): bool => isset($selected[(int) data_get($change, $itemKey)]),
                        ) || (is_array($items) && collect($items)->contains(fn (mixed $id): bool => isset($selected[(int) $id])));
                        $unknowable = ! is_array($items) && (int) $run->item_count > (int) $run->changed_count;

                        if ($attributed || $unknowable) {
                            $found[] = ['table' => $table, 'id' => (int) $run->id, 'search_key' => $searchKey, 'return_query' => $query, 'attributed' => $attributed];
                        }
                    }
                });
        }

        return $found;
    }

    /**
     * Deliveries about the person that hold a response sample: up to 4 KB of
     * whatever the subscriber replied, which can echo what it fetched about
     * them. Their payloads are identifiers only (ADR 0020).
     *
     * @param  list<string>  $supportCodes
     * @param  list<int>  $ticketIds
     * @return list<int>
     */
    public function webhookDeliveryIdsWithResponse(int $siteId, array $supportCodes, array $ticketIds): array
    {
        if ($supportCodes === [] && $ticketIds === []) {
            return [];
        }

        $codes = array_flip($supportCodes);
        $tickets = array_flip($ticketIds);
        $found = [];

        OutboundWebhookDelivery::query()
            ->where('site_id', $siteId)
            ->whereNotNull('response_body')
            ->select(['id', 'payload'])
            ->chunkById(500, function (EloquentCollection $deliveries) use ($codes, $tickets, &$found): void {
                foreach ($deliveries as $delivery) {
                    $code = data_get($delivery->payload, 'resource.support_code')
                        ?? data_get($delivery->payload, 'resource.conversation_support_code');

                    if ((is_string($code) && isset($codes[$code]))
                        || (data_get($delivery->payload, 'resource.type') === 'ticket'
                            && isset($tickets[(int) data_get($delivery->payload, 'resource.id')]))) {
                        $found[] = (int) $delivery->id;
                    }
                }
            });

        return $found;
    }

    /**
     * What names this person wherever it appears, for searching free text
     * that says nothing of the site or account it was about: their email
     * address, one mailbox, and their support codes, unique across the
     * install. A host or browser ID is unique only within its site, so it
     * would find other sites' visitors too.
     *
     * @param  list<string>  $supportCodes
     * @return list<string>
     */
    public function installWideIdentifiers(Visitor $visitor, array $supportCodes): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (string $identifier): string => trim($identifier), [(string) $visitor->email, ...$supportCodes]),
            fn (string $identifier): bool => mb_strlen($identifier) >= 6,
        )));
    }

    /**
     * Their support codes alone, for text that must be tied to this person
     * and nobody else: a code is unique across the install, while an email
     * address can be another contact's too, in another account or site.
     *
     * @param  list<string>  $supportCodes
     * @return list<string>
     */
    public function uniqueIdentifiers(array $supportCodes): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (string $code): string => trim($code), $supportCodes),
            fn (string $code): bool => mb_strlen($code) >= 6,
        )));
    }

    /**
     * Jobs that exhausted their retries and name the person. A job is kept
     * with its payload and the exception it died on, and a mail server's
     * rejection quotes the address it refused. Each identifier must appear as
     * a whole token, so a longer code or address that merely contains it is
     * not a match.
     *
     * Wherever the operator keeps them: a database store on its own
     * connection is searched there, and a file or DynamoDB store through the
     * provider every store implements.
     *
     * @param  list<string>  $identifiers  from installWideIdentifiers()
     * @return list<array{id: int|string, payload: string, exception: string, failed_at: mixed}>
     */
    public function failedJobsNaming(array $identifiers): array
    {
        $driver = config('queue.failed.driver');

        if ($identifiers === [] || $driver === null || $driver === 'null') {
            return [];
        }

        if (in_array($driver, ['database', 'database-uuids'], true)) {
            return $this->failedJobRowsNaming($identifiers);
        }

        $found = [];

        foreach (app('queue.failer')->all() as $job) {
            $payload = (string) data_get($job, 'payload');
            $exception = (string) data_get($job, 'exception');

            if ($this->namesAny($payload.' '.$exception, $identifiers)) {
                $found[] = ['id' => data_get($job, 'id'), 'payload' => $payload, 'exception' => $exception, 'failed_at' => data_get($job, 'failed_at')];
            }
        }

        return $found;
    }

    /**
     * Proactive messages shown to the person: rows naming the visitor, and
     * rows already detached from a pruned presence row but still keyed by
     * this person's browser.
     *
     * @param  list<string>  $anonymousIds
     * @return list<int>
     */
    public function proactiveDeliveryIds(int $siteId, int $visitorId, array $anonymousIds): array
    {
        $keys = array_map(
            fn (string $anonymousId): string => ProactiveVisitorKey::for($siteId, $anonymousId),
            $anonymousIds,
        );

        $ids = $this->ints(DB::table('proactive_message_deliveries')
            ->where('site_id', $siteId)
            ->where('visitor_id', $visitorId)
            ->pluck('id'));

        foreach (array_chunk($keys, 500) as $chunk) {
            $ids = [...$ids, ...$this->ints(DB::table('proactive_message_deliveries')
                ->where('site_id', $siteId)
                ->whereIn('visitor_key', $chunk)
                ->pluck('id'))];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $identifiers
     * @return list<array{id: int, payload: string, exception: string, failed_at: mixed}>
     */
    private function failedJobRowsNaming(array $identifiers): array
    {
        $connection = DB::connection(config('queue.failed.database') ?: null);
        $table = (string) (config('queue.failed.table') ?: 'failed_jobs');

        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        // LIKE finds the candidates on any database; the whole-token check
        // that decides is made here, the same for every store. In chunks: a
        // long history has a support code per conversation, and each adds
        // terms and bindings to the statement.
        $found = [];

        foreach (array_chunk($identifiers, 50) as $chunk) {
            $query = $connection->table($table);
            $grammar = $query->getGrammar();
            $query->where(function (Builder $query) use ($grammar, $chunk): void {
                foreach ($chunk as $identifier) {
                    foreach (['payload', 'exception'] as $column) {
                        $query->orWhereRaw('LOWER('.$grammar->wrap($column).') LIKE LOWER(?) ESCAPE ?', [LiteralLike::pattern($identifier), '\\']);
                    }
                }
            });

            foreach ($query->get(['id', 'payload', 'exception', 'failed_at']) as $job) {
                if ($this->namesAny($job->payload.' '.$job->exception, $chunk)) {
                    $found[(int) $job->id] = ['id' => (int) $job->id, 'payload' => (string) $job->payload, 'exception' => (string) $job->exception, 'failed_at' => $job->failed_at];
                }
            }
        }

        ksort($found);

        return array_values($found);
    }

    /**
     * Whether the text names one of the identifiers as a whole token, not as
     * part of a longer address or code.
     *
     * @param  list<string>  $identifiers
     */
    private function namesAny(string $text, array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            $pattern = '/(?<![\\p{L}\\p{N}._%+-])'.preg_quote($identifier, '/').'(?![\\p{L}\\p{N}_%+-]|\\.[\\p{L}\\p{N}])/iu';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
