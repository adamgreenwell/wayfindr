<?php

declare(strict_types=1);

namespace App\Support\Conversations;

use App\Models\Conversation;
use App\Support\CobrowseConsentState;
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
        $query = (clone $query)
            ->select('conversations.id')
            ->reorder()
            ->orderBy('conversations.id');

        return $this->scan($query, retain: 0)['count'];
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
        return $this->scan(clone $query, retain: $limit);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Collection<int, Conversation>
     */
    public function take(Builder $query, int $limit): Collection
    {
        return $this->scan(clone $query, retain: $limit)['conversations'];
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return array{
     *     count: int,
     *     conversations: Collection<int, Conversation>,
     *     transportByConversationId: Collection<int, array<string, mixed>>
     * }
     */
    private function scan(Builder $query, int $retain): array
    {
        $count = 0;
        $conversations = collect();
        $transportByConversationId = collect();

        // Snapshot the high-water id, then walk by immutable primary key. The
        // queue order includes last_message_at, which changes under ordinary
        // traffic; OFFSET pages in that order can duplicate or skip a row when
        // a message arrives between queries.
        $query = $query->with('latestCobrowseSession')->reorder();
        $maximumId = (clone $query)->max('conversations.id');

        if ($maximumId !== null) {
            $query
                ->where('conversations.id', '<=', $maximumId)
                ->chunkById(self::CHUNK_SIZE, function (Collection $candidates) use (&$count, &$conversations, &$transportByConversationId, $retain): void {
                    foreach ($candidates as $conversation) {
                        $transport = $this->cobrowseConsentState->queueTransportForConversation($conversation);

                        if (! $this->cobrowseConsentState->transportNeedsAttention($transport)) {
                            continue;
                        }

                        $count++;

                        if ($retain > 0) {
                            $conversations->push($conversation);
                            $transportByConversationId->put($conversation->id, $transport);
                        }
                    }

                    if ($retain > 0 && $conversations->count() > $retain) {
                        $conversations = $this->retainMostRecent($conversations, $retain);
                        $transportByConversationId = $transportByConversationId->only($conversations->pluck('id')->all());
                    }
                }, 'conversations.id', 'id');
        }

        if ($retain > 0 && $conversations->isNotEmpty()) {
            // A retained conversation can receive a message after its id chunk
            // was read. Rank the bounded set in SQL again so the rendered page
            // agrees with the shared queue order at the end of the scan.
            $conversations = $this->retainMostRecent($conversations, $retain);
            $transportByConversationId = $transportByConversationId->only($conversations->pluck('id')->all());
        }

        return [
            'count' => $count,
            'conversations' => $conversations,
            'transportByConversationId' => $transportByConversationId,
        ];
    }

    /**
     * Re-rank only the bounded retained set using current ordering columns.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function retainMostRecent(Collection $conversations, int $retain): Collection
    {
        $conversationsById = $conversations->keyBy('id');
        $orderedIds = ConversationQueueQuery::ordered(
            Conversation::query()
                ->select('id')
                ->whereKey($conversationsById->keys()->all())
        )
            ->limit($retain)
            ->pluck('id');

        return $orderedIds
            ->map(fn (int $id): ?Conversation => $conversationsById->get($id))
            ->filter()
            ->values();
    }
}
