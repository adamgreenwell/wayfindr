<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Conversation;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Read-only presentation scalars. Never serialize a resolved connection or its configuration. */
final readonly class ConversationAssistPresentation
{
    /** @return array{provider: string, model: string, source: string, upstream: ?string, max_characters: int, article_context_allowed: bool}|null */
    public function processor(Conversation $conversation): ?array
    {
        try {
            $conversation = Conversation::query()->with('site')->find($conversation->id);
            if ($conversation?->site === null) {
                return null;
            }
            $connection = app(AgentCopilotConnectionResolver::class)->forAccount((int) $conversation->site->account_id);
            if ($connection === null) {
                return null;
            }
            $policy = app(AiAssistPolicyResolver::class)->forConversation($conversation);

            return ['provider' => $connection->provider, 'model' => $connection->model, 'source' => $connection->source,
                'upstream' => $connection->provider === 'openrouter' && $connection->openRouterProvider !== '' ? $connection->openRouterProvider : null,
                'max_characters' => min(20000, $policy->maxContextCharacters), 'article_context_allowed' => $policy->articleContextAllowed];
        } catch (Throwable) {
            return null;
        }
    }

    /** Caller first establishes readable output and current viewer authority.
     * @return array{source_position: int, source_ordinal: int, source_message_count: int, source_url: string}|null
     */
    public function provenance(Conversation $conversation, ?Model $artifact): ?array
    {
        if ($artifact === null || (int) $artifact->conversation_id !== (int) $conversation->id
            || ! is_int($artifact->source_last_message_id) || ! is_int($artifact->source_message_count)
            || $artifact->source_message_count < 1) {
            return null;
        }
        $message = $conversation->messages()->whereKey($artifact->source_last_message_id)->first(['id', 'created_at']);
        if ($message === null) {
            return null;
        }
        // Match the actual transcript's created_at/id ordering; global IDs are not human positions.
        $createdAt = $message->getRawOriginal('created_at');
        $ordinal = $conversation->messages()->where(function ($query) use ($createdAt, $message): void {
            $query->where('created_at', '<', $createdAt)->orWhere(function ($query) use ($createdAt, $message): void {
                $query->where('created_at', $createdAt)->where('id', '<=', $message->id);
            });
        })->count();

        return ['source_position' => (int) $message->id, 'source_ordinal' => $ordinal,
            'source_message_count' => $artifact->source_message_count,
            'source_url' => route('dashboard.conversations.show', ['supportCode' => $conversation->support_code]).'#conversation-message-'.$message->id];
    }
}
