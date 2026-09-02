<?php

declare(strict_types=1);

namespace App\Support\Conversations;

use App\Models\Conversation;
use App\Support\CobrowseConsentState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Evaluate cobrowse transport attention without holding every candidate model.
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

        return $this->scan($query, retain: 0, countAll: true)['count'];
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
        return $this->scan(clone $query, retain: $limit, countAll: true);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Collection<int, Conversation>
     */
    public function take(Builder $query, int $limit): Collection
    {
        return $this->scan(clone $query, retain: $limit, countAll: false)['conversations'];
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return array{
     *     count: int,
     *     conversations: Collection<int, Conversation>,
     *     transportByConversationId: Collection<int, array<string, mixed>>
     * }
     */
    private function scan(Builder $query, int $retain, bool $countAll): array
    {
        $count = 0;
        $conversations = collect();
        $transportByConversationId = collect();

        foreach ($query->with('latestCobrowseSession')->lazy(self::CHUNK_SIZE) as $conversation) {
            $transport = $this->cobrowseConsentState->queueTransportForConversation($conversation);

            if (! $this->cobrowseConsentState->transportNeedsAttention($transport)) {
                continue;
            }

            $count++;

            if ($conversations->count() < $retain) {
                $conversations->push($conversation);
                $transportByConversationId->put($conversation->id, $transport);
            }

            if (! $countAll && $conversations->count() >= $retain) {
                break;
            }
        }

        return [
            'count' => $count,
            'conversations' => $conversations,
            'transportByConversationId' => $transportByConversationId,
        ];
    }
}
