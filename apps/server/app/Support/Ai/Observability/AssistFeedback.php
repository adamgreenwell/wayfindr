<?php

declare(strict_types=1);

namespace App\Support\Ai\Observability;

use App\Models\Account;
use App\Models\AiAssistFeedback;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationDraftRefinement;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\ConversationTextContextSelector;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Preparation\AiAssistPreparation;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Usage\AiUsageClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Client observations, never inferred from model state or authorizations. */
final readonly class AssistFeedback
{
    public const PRIVATE_PURPOSE = 'conversation_draft_refinement';

    public const INSERTABLE_PURPOSES = ['conversation_reply_draft', 'conversation_knowledge_suggestion', 'conversation_ticket_suggestion', self::PRIVATE_PURPOSE];

    public function __construct(private AiUsageClock $clock) {}

    /** Issued only by an already fresh Use/Apply boundary; contains no support text. */
    public function receipt(User $actor, Conversation $conversation, string $purpose, string $generation, ?int $expiry = null): string
    {
        if (! in_array($purpose, self::INSERTABLE_PURPOSES, true)) {
            throw new AiNetworkException('execution_identity_invalid');
        }

        return Crypt::encryptString(json_encode(['v' => 1, 'account' => (int) $actor->account_id, 'site' => (int) $conversation->site_id,
            'conversation' => (int) $conversation->id, 'actor' => (int) $actor->id, 'purpose' => $purpose, 'generation' => $generation, 'source' => $this->source($conversation),
            'expires' => min($expiry ?? PHP_INT_MAX, $this->clock->now()->getTimestamp() + 60)], JSON_THROW_ON_ERROR));
    }

    public function record(User $actor, Conversation $hint, string $purpose, #[\SensitiveParameter] array $input): array
    {
        if (! $actor->account_id) {
            throw new AiNetworkException('access_revoked');
        }

        if ($input['event'] === 'used' && ! in_array($purpose, self::INSERTABLE_PURPOSES, true)) {
            throw new AiNetworkException('execution_identity_invalid');
        }

        return DB::transaction(function () use ($actor, $hint, $purpose, $input): array {
            // Observations fence account lifecycle without acquiring installation usage locks.
            if (Account::query()->whereKey($actor->account_id)->lockForUpdate()->first() === null) {
                throw new AiNetworkException('access_revoked');
            }
            $actor = User::query()->whereKey($actor->id)->where('account_id', $actor->account_id)->lockForUpdate()->first();
            $conversation = Conversation::query()->with('site')->whereKey($hint->id)->lockForUpdate()->first();
            if ($actor === null || $actor->isDeactivated() || $conversation === null || (int) $conversation->site?->account_id !== (int) $actor->account_id
             || ! Gate::forUser($actor)->allows($purpose === 'conversation_summary' ? 'view' : ($purpose === 'conversation_ticket_suggestion' ? 'createTicket' : 'reply'), $conversation)) {
                throw new AiNetworkException('access_revoked');
            }
            $generation = $input['generation'];
            $event = $input['event'];
            if ($purpose === self::PRIVATE_PURPOSE) {
                $row = ConversationDraftRefinement::query()->where('account_id', $actor->account_id)->where('conversation_id', $conversation->id)
                    ->where('requested_by_id', $actor->id)->where('generation', $generation)->lockForUpdate()->first();
                if ($row === null || ! $row->expires_at->isAfter($this->clock->now()) || ! in_array($row->status, ['ready', 'applied'], true)
                  || ! app(AgentCopilotConnectionResolver::class)->isCurrent((int) $actor->account_id, $row->connection_revision)
                  || ! app(AiAssistPolicyResolver::class)->isCurrent((int) $conversation->site_id, $purpose, $row->policy_revision)) {
                    throw new AiNetworkException('context_changed');
                }
                if ($event === 'viewed' && $row->status !== 'ready') {
                    throw new AiNetworkException('context_changed');
                }
                if ($row->status === 'ready' && ! app(DraftRefinements::class)->previewIsCurrent($row)) {
                    throw new AiNetworkException('context_changed');
                }
            } else {
                try {
                    $state = app(AiAssistPreparation::class)->read($actor, $conversation, $purpose);
                } catch (AuthorizationException) {
                    throw new AiNetworkException('access_revoked');
                }
                if (! $state['can_use'] || $state['generation'] !== $generation || ($purpose === 'conversation_knowledge_suggestion' && ($state['output']['articles'] ?? []) === [])) {
                    throw new AiNetworkException('context_changed');
                }
            }
            if ($event === 'used') {
                try {
                    $receipt = json_decode(Crypt::decryptString($input['receipt']), true, 8, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new AiNetworkException('context_changed');
                }
                $expected = ['v' => 1, 'account' => (int) $actor->account_id, 'site' => (int) $conversation->site_id, 'conversation' => (int) $conversation->id,
                    'actor' => (int) $actor->id, 'purpose' => $purpose, 'generation' => $generation, 'source' => $this->source($conversation)];
                if (! is_array($receipt) || array_diff_key($receipt, $expected + ['expires' => 0]) !== [] || array_intersect_key($receipt, $expected) !== $expected
                  || ! is_int($receipt['expires'] ?? null) || $receipt['expires'] <= $this->clock->now()->getTimestamp()
                  || $receipt['expires'] > $this->clock->now()->getTimestamp() + 60) {
                    throw new AiNetworkException('context_changed');
                }
                if ($purpose === self::PRIVATE_PURPOSE && $row->status !== 'applied') {
                    throw new AiNetworkException('context_changed');
                }
            }
            $feedback = AiAssistFeedback::query()->where('conversation_id', $conversation->id)->where('purpose', $purpose)
                ->where('generation', $generation)->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($feedback !== null && $purpose === self::PRIVATE_PURPOSE && ! hash_equals($feedback->source_revision, $this->source($conversation))) {
                throw new AiNetworkException('context_changed');
            }
            if ($event === 'assessment' && $feedback === null) {
                throw new AiNetworkException('context_changed');
            }
            $feedback ??= new AiAssistFeedback(['account_id' => $actor->account_id, 'site_id' => $conversation->site_id, 'conversation_id' => $conversation->id,
                'user_id' => $actor->id, 'purpose' => $purpose, 'generation' => $generation, 'source_revision' => $this->source($conversation), 'revision' => 0]);
            if ($event === 'assessment') {
                $usefulness = $input['usefulness'];
                $correction = $input['correction'];
                if ($feedback->viewed_at === null || ($correction !== 'unassessed' && $feedback->used_at === null)) {
                    throw new AiNetworkException('context_changed');
                }
                // Identical retries succeed even after their original revision has advanced.
                if ($feedback->usefulness !== $usefulness || $feedback->correction !== $correction) {
                    if ((int) $feedback->revision !== $input['expected_revision']) {
                        throw new AiNetworkException('context_changed');
                    }
                    $feedback->forceFill(['usefulness' => $usefulness, 'correction' => $correction, 'revision' => (int) $feedback->revision + 1]);
                }
            } else {
                $column = $event.'_at';
                if ($feedback->$column === null) {
                    $feedback->$column = $this->clock->now();
                }
            }
            if ($feedback->isDirty()) {
                $feedback->save();
            }

            return ['generation' => $generation, 'viewed' => $feedback->viewed_at !== null, 'used' => $feedback->used_at !== null,
                'rejected' => $feedback->rejected_at !== null, 'usefulness' => $feedback->usefulness, 'correction' => $feedback->correction, 'revision' => (int) $feedback->revision];
        });
    }

    private function source(Conversation $conversation): string
    {
        $fresh = $conversation->fresh(['site.account']);
        $context = app(ConversationTextContextSelector::class)->select($fresh);
        $articles = app(AiAssistPolicyResolver::class)->forConversation($fresh)->articleContextAllowed;

        return hash_hmac('sha256', json_encode([$fresh->id, $fresh->site_id, $context?->sourceRevision, $fresh->messages()->max('id'),
            $articles ? $fresh->site->account->ai_article_catalogue_revision : null,
            $articles ? Article::query()->where('account_id', $fresh->site->account_id)->published()->max('published_at') : null], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
