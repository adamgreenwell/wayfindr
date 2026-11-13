<?php

declare(strict_types=1);

namespace App\Support\Ai\Preparation;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\ConversationKnowledgeSuggestionPromptBuilder;
use App\Support\Ai\ConversationReplyDraftPromptBuilder;
use App\Support\Ai\ConversationSummaryPromptBuilder;
use App\Support\Ai\ConversationTicketSuggestionPromptBuilder;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Read-only input snapshot for the future locked ensure/admission coordinator. */
final readonly class AiAssistPreparationIdentityResolver
{
    private const VERSION = 'assist-preparation-v3';

    public function __construct(private AiAssistPolicyResolver $policies, private AgentCopilotConnectionResolver $connections) {}

    public function forTask(User $actor, Conversation $conversation, string $purpose): AiAssistPreparationIdentity
    {
        $builder = match ($purpose) {
            'conversation_summary' => ConversationSummaryPromptBuilder::class,
            'conversation_reply_draft' => ConversationReplyDraftPromptBuilder::class,
            'conversation_knowledge_suggestion' => ConversationKnowledgeSuggestionPromptBuilder::class,
            'conversation_ticket_suggestion' => ConversationTicketSuggestionPromptBuilder::class,
            // Personalized unsent draft refinement must never join shared work.
            default => throw new AiNetworkException('execution_identity_invalid'),
        };
        $actor = User::query()->find($actor->id);
        if ($actor === null || ! $actor->account_id || $actor->isDeactivated()) {
            throw new AuthorizationException;
        }
        $conversation = Conversation::query()->with('site.account')->whereKey($conversation->id)
            ->whereHas('site', fn ($query) => $query->where('account_id', $actor->account_id))->first();
        $ability = match ($purpose) {
            'conversation_reply_draft', 'conversation_knowledge_suggestion' => 'reply',
            'conversation_ticket_suggestion' => 'createTicket',
            default => 'view',
        };
        if ($conversation === null || ! Gate::forUser($actor)->allows($ability, $conversation)) {
            throw new AuthorizationException;
        }
        $policy = $this->policies->forConversation($conversation);
        if (! $policy->allows($purpose)) {
            throw new AiNetworkException('access_revoked');
        }
        if ($purpose === 'conversation_ticket_suggestion' && $conversation->tickets()->exists()) {
            throw new AiNetworkException('context_changed');
        }
        $accountId = (int) $conversation->site->account_id;
        $connection = $this->connections->forAccount($accountId);
        if ($connection === null) {
            throw new AiNetworkException('connection_changed');
        }
        // Capture all activity first, including attachment-only follow-ups.
        // Attachment bytes, metadata and profiles never enter the source proof.
        $lastMessageId = $conversation->messages()->max('id');
        $context = $purpose === 'conversation_ticket_suggestion'
            ? app($builder)->build($conversation, matchLabels: false)
            : ($purpose === 'conversation_reply_draft' ? app($builder)->build($conversation, $actor, selectSources: false) : app($builder)->build($conversation));
        if ($context !== null && (! is_string($context->sourceRevision)
            || preg_match('/\A[a-f0-9]{64}\z/', $context->sourceRevision) !== 1)) {
            throw new AiNetworkException('execution_identity_invalid');
        }
        $sourceRevision = $context === null ? $this->hash(['no-text', $conversation->subject, $lastMessageId]) : $context->sourceRevision;
        $catalogueRevision = null;
        $catalogueEmpty = false;
        if ($context !== null && (in_array($purpose, ['conversation_knowledge_suggestion', 'conversation_ticket_suggestion'], true)
            || $purpose === 'conversation_reply_draft' && $policy->articleContextAllowed)) {
            [$catalogueRevision, $catalogueEmpty] = $this->catalogue($accountId, $purpose === 'conversation_reply_draft' ? 'conversation_knowledge_suggestion' : $purpose);
        }
        $state = $context === null || ($purpose === 'conversation_knowledge_suggestion' && $catalogueEmpty)
            ? 'insufficient_context' : 'eligible';
        $policyRevision = $policy->revisionFor($purpose);
        $fingerprint = $this->hash([
            self::VERSION, $accountId, (int) $conversation->site_id, (int) $conversation->id, $purpose,
            'shared-conversation-text-v1', $policyRevision, $policy->maxContextCharacters,
            $connection->source, $connection->revision, $connection->provider, $connection->model,
            $sourceRevision, $lastMessageId, $state, $catalogueRevision,
            $context === null ? null : [$context->prompt->instructions,
                AiSupportUsage::sanitizedInput($context->prompt, $policy->maxContextCharacters), $context->prompt->timeoutSeconds],
        ]);

        // Requester identity belongs to the reservation/audit, not equivalence.
        return new AiAssistPreparationIdentity($accountId, (int) $conversation->site_id, (int) $conversation->id,
            $purpose, $policy->mode, $state, $policyRevision, $connection->revision, $sourceRevision,
            $catalogueRevision, $lastMessageId === null ? null : (int) $lastMessageId,
            $policy->maxContextCharacters, $fingerprint, ($context?->sourceSnapshot ?? []) + ['catalogue_revision' => $catalogueRevision]);
    }

    /** Constant-size metadata reads; never scan article bodies or label rows here. */
    private function catalogue(int $accountId, string $purpose): array
    {
        $knowledge = $purpose === 'conversation_knowledge_suggestion';
        $column = $knowledge ? 'ai_article_catalogue_revision' : 'ai_ticket_label_catalogue_revision';
        $revision = DB::table('accounts')->where('id', $accountId)->value($column);
        // The existing (account_id, published_at) index makes this a single
        // watermark lookup. Scheduled publication advances it without a write.
        $published = $knowledge ? DB::table('articles')->where('account_id', $accountId)
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at')->value('published_at') : null;

        return [$this->hash([self::VERSION, $accountId, $purpose, $revision, $published]), $knowledge && $published === null];
    }

    private function hash(array $identity): string
    {
        return hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
