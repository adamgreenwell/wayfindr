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

                    if ($retain > 0) {
                        $conversations = ConversationQueueQuery::sortModels($conversations)
                            ->take($retain)
                            ->values();
                        $transportByConversationId = $transportByConversationId->only($conversations->pluck('id')->all());
                    }
                }, 'conversations.id', 'id');
        }

        return [
            'count' => $count,
            'conversations' => $conversations,
            'transportByConversationId' => $transportByConversationId,
        ];
    }
}
