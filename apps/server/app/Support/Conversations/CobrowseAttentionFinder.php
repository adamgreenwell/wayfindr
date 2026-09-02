<?php

declare(strict_types=1);

namespace App\Support\Conversations;

use App\Models\Conversation;
use App\Support\CobrowseConsentState;
use App\Support\Database\StableReadTransaction;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Evaluate cobrowse attention from stable id chunks without holding every model.
 */
final class CobrowseAttentionFinder
{
    public const CHUNK_SIZE = ConversationQueueQuery::DISPLAY_LIMIT;

    public function __construct(
        private readonly CobrowseConsentState $cobrowseConsentState,
    ) {}

    /** @param Builder<Conversation> $query */
    public function count(Builder $query): int
    {
        return $this->withinStableSnapshot($query, function () use ($query): int {
            $query = (clone $query)
                ->select('conversations.id')
                ->reorder()
                ->orderBy('conversations.id');

            return $this->scan($query, collectMatchingIds: false)['count'];
        });
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return array{
     *     count: int,
     *     conversations: Collection<int, Conversation>,
     *     transportByConversationId: Collection<int, array<string, mixed>>
     * }
     */
    public function page(Builder $query, int $limit): array
    {
        return $this->withinStableSnapshot($query, function () use ($query, $limit): array {
            $result = $this->scan(clone $query, collectMatchingIds: true);
            $conversations = $this->hydratePage(clone $query, $result['matching_ids'], $limit);

            return [
                'count' => $result['count'],
                'conversations' => $conversations,
                'transportByConversationId' => $conversations->mapWithKeys(fn (Conversation $conversation): array => [
                    $conversation->id => $this->cobrowseConsentState->queueTransportForConversation($conversation),
                ]),
            ];
        });
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Collection<int, Conversation>
     */
    public function take(Builder $query, int $limit): Collection
    {
        return $this->withinStableSnapshot($query, function () use ($query, $limit): Collection {
            $result = $this->scan(clone $query, collectMatchingIds: true);

            return $this->hydratePage(clone $query, $result['matching_ids'], $limit);
        });
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return array{count: int, matching_ids: list<int>}
     */
    private function scan(Builder $query, bool $collectMatchingIds): array
    {
        $count = 0;
        $matchingIds = [];

        // Snapshot the high-water id, then walk by immutable primary key. The
        // queue order includes last_message_at, which changes under ordinary
        // traffic; OFFSET pages in that order can duplicate or skip a row when
        // a message arrives between queries.
        $query = $query
            ->setEagerLoads([])
            ->with('latestCobrowseSession')
            ->reorder();
        $maximumId = (clone $query)->max('conversations.id');

        if ($maximumId !== null) {
            $query
                ->where('conversations.id', '<=', $maximumId)
                ->chunkById(self::CHUNK_SIZE, function (Collection $candidates) use (&$count, &$matchingIds, $collectMatchingIds): void {
                    foreach ($candidates as $conversation) {
                        $transport = $this->cobrowseConsentState->queueTransportForConversation($conversation);

                        if (! $this->cobrowseConsentState->transportNeedsAttention($transport)) {
                            continue;
                        }

                        $count++;

                        if ($collectMatchingIds) {
                            $matchingIds[] = $conversation->id;
                        }
                    }
                }, 'conversations.id', 'id');
        }

        return [
            'count' => $count,
            'matching_ids' => $matchingIds,
        ];
    }

    /**
     * @param  list<int>  $matchingIds
     * @return Collection<int, Conversation>
     */
    private function hydratePage(Builder $query, array $matchingIds, int $limit): Collection
    {
        if ($matchingIds === []) {
            return collect();
        }

        // Keep every match reconsiderable until one snapshot-consistent SQL
        // ordering picks the rendered window. Retaining only the current top
        // 200 full models permanently loses an evicted match if a new message
        // moves it back into the window while later id chunks are evaluated.
        $query
            ->whereIntegerInRaw('conversations.id', $matchingIds)
            ->reorder();

        ConversationQueueQuery::ordered($query);

        return $query->limit($limit)->get();
    }

    /**
     * Give every chunk, the exact count, and the final ordering one database
     * view. The supported runtime is PostgreSQL; its default READ COMMITTED
     * would otherwise refresh the active-session predicate on every chunk.
     *
     * An existing transaction owns its own isolation contract. That path is
     * used by the transactional test harness; web callers enter here without
     * one, so PostgreSQL can set the isolation before the snapshot's first
     * query.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    private function withinStableSnapshot(Builder $query, Closure $read): mixed
    {
        return StableReadTransaction::run($query->getConnection(), $read);
    }
}
