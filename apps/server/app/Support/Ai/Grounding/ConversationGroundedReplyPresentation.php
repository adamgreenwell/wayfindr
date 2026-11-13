<?php

declare(strict_types=1);

namespace App\Support\Ai\Grounding;

use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\User;
use Throwable;

/** Reauthorize the complete article-derived draft, not merely its citation links. */
final class ConversationGroundedReplyPresentation
{
    public function isCurrent(ConversationCopilotReplyDraft $draft, User $viewer, Conversation $conversation): bool
    {
        try {
            $value = $draft->grounding;
            if (! is_array($value) || ($value['version'] ?? null) !== ConversationGroundedReplyGuard::VERSION
                || ($value['scorer_identity'] ?? null) !== app(ConversationGroundedReplyGuard::class)->identity()
                || ! is_array($value['snapshot'] ?? null) || ! is_array($value['sources'] ?? null) || ! is_array($value['proposal'] ?? null)
                || ! is_string($draft->draft) || ! app(ConversationGroundingSelector::class)->payloadStillCurrent($viewer, $conversation, $value['snapshot'], $value['sources'])) {
                return false;
            }

            return app(ConversationGroundedReplyOutputParser::class)->parse(json_encode($value['proposal'] + ['reply' => $draft->draft], JSON_THROW_ON_ERROR), $value['sources']) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** Called only after isCurrent under the current viewer's access and publication checks. */
    public function output(ConversationCopilotReplyDraft $draft, Conversation $conversation): array
    {
        $value = $draft->grounding;
        $sources = array_column($value['sources'], null, 'ref');
        $proofs = array_column($value['snapshot']['sources'], null, 'ref');
        $citations = [];
        foreach ($value['proposal']['citations'] as $reference) {
            $source = $sources[$reference];
            $proof = $proofs[$reference];
            $citations[] = ['ref' => $reference, 'id' => $proof['article_id'], 'title' => $source['title'],
                'snippet' => $source['passage'], 'revision' => $proof['full_revision'],
                'source_url' => route('dashboard.conversations.assist.sources.show', ['supportCode' => $conversation->support_code,
                    'article' => $proof['article_id'], 'task' => 'reply', 'generation' => $draft->generation, 'article_revision' => $proof['full_revision']])];
        }

        return ['decision' => $value['proposal']['decision'], 'limitation' => $value['proposal']['limitation'],
            'missing_information' => $value['proposal']['missing_information'], 'sources' => $citations];
    }
}
