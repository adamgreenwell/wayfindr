<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Article;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Ai\Preparation\AiAssistPreparation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Inspect selected, currently published account guidance without generation or insertion. */
final class AgentConversationAiSourceController extends Controller
{
    private const PURPOSE = 'conversation_knowledge_suggestion';

    public function show(Request $request, string $supportCode, string $article, AgentCopilotConnectionResolver $connections,
        AiAssistPolicyResolver $policies, KnowledgeArticleSuggestionMatcher $matcher): Response
    {
        $input = $request->validate(['generation' => ['required', 'uuid'], 'article_revision' => ['required', 'regex:/\A[a-f0-9]{64}\z/'], 'task' => ['nullable', 'in:reply,knowledge']]);
        $actor = User::query()->findOrFail($request->user()->id);
        abort_unless($actor->account_id && ! $actor->isDeactivated(), 404);

        return DB::transaction(function () use ($actor, $supportCode, $article, $input, $connections, $policies, $matcher): Response {
            Account::query()->whereKey($actor->account_id)->lockForUpdate()->firstOrFail();
            $actor = User::query()->whereKey($actor->id)->where('account_id', $actor->account_id)->lockForUpdate()->firstOrFail();
            $conversation = Conversation::query()->with('site.account')->where('support_code', $supportCode)
                ->whereHas('site', fn ($query) => $query->where('account_id', $actor->account_id))->lockForUpdate()->firstOrFail();
            abort_unless(Gate::forUser($actor)->allows('reply', $conversation), 404);
            $reply = ($input['task'] ?? 'knowledge') === 'reply';
            $purpose = $reply ? 'conversation_reply_draft' : self::PURPOSE;
            $suggestion = $reply ? $conversation->copilotReplyDraft()->lockForUpdate()->first()
                : $conversation->copilotKnowledgeSuggestion()->lockForUpdate()->first();
            abort_unless($suggestion !== null, 404);
            try {
                $policy = $policies->forConversation($conversation);
                $current = $connections->forAccount((int) $actor->account_id);
            } catch (Throwable) {
                abort(404);
            }
            abort_unless($policy->allows($purpose) && (! $reply || $policy->articleContextAllowed) && $current !== null, 404);
            $source = Article::query()->where('account_id', $actor->account_id)->whereKey((int) $article)->published()->lockForUpdate()->firstOrFail();
            if ($reply) {
                try {
                    $state = app(AiAssistPreparation::class)->read($actor, $conversation, $purpose);
                } catch (Throwable) {
                    abort(404);
                }
                $selected = collect($state['output']['grounding']['sources'] ?? [])->firstWhere('id', (int) $article);
                abort_unless($selected !== null, 404);
                abort_unless(($state['output_generation'] ?? null) === $input['generation']
                    && hash_equals($selected['revision'], $input['article_revision'])
                    && hash_equals(KnowledgeArticleSuggestionMatcher::revision($source, ''), $input['article_revision']), 409);
            } elseif ($policy->explicit || $suggestion->preparation_fingerprint !== null) {
                try {
                    $state = app(AiAssistPreparation::class)->read($actor, $conversation, self::PURPOSE);
                } catch (Throwable) {
                    abort(404);
                }
                abort_unless(is_array($state['output']['articles'] ?? null), 404);
                abort_unless(($state['output_generation'] ?? null) === $input['generation'], 409);
                $selected = collect($state['output']['articles'])->firstWhere('id', (int) $article);
                abort_unless($selected !== null, 404);
                abort_unless(hash_equals($selected['revision'], $input['article_revision']), 409);
            } else {
                abort_unless($suggestion->status === ConversationCopilotKnowledgeSuggestion::STATUS_READY
                    && $policies->isCurrent((int) $conversation->site_id, self::PURPOSE, $suggestion->policy_revision)
                    && hash_equals($current->revision, (string) $suggestion->connection_revision), 404);
                abort_unless($suggestion->generation === $input['generation'], 409);
                abort_unless(in_array((int) $article, $suggestion->suggestedArticleIds(), true), 404);
                $selected = $matcher->present($conversation->site->account, [(int) $article])->first();
                abort_unless($selected !== null, 404);
                abort_unless(hash_equals(KnowledgeArticleSuggestionMatcher::revision($selected['article'], $selected['snippet']), $input['article_revision']), 409);
            }
            // Bind the exact locked full source rendered below, including edits outside its excerpt.
            if (! $reply) {
                $presented = $matcher->present($conversation->site->account, [(int) $source->id])->first();
                abort_unless($presented !== null
                    && hash_equals(KnowledgeArticleSuggestionMatcher::revision($source, $presented['snippet']), $input['article_revision']), 409);
            }

            return response()->view('agent.conversations.assist-source', [
                'conversation' => $conversation, 'sourceTitle' => $source->title, 'blocks' => $source->blocks(),
            ])->header('Cache-Control', 'no-store, private');
        });
    }
}
