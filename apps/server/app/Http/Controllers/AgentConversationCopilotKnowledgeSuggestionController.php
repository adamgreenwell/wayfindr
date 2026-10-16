<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationCopilotKnowledgeSuggestion;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\KnowledgeArticleSuggestionMatcher;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Queue and render agent-reviewed published-article suggestions. */
final class AgentConversationCopilotKnowledgeSuggestionController extends Controller
{
    private const PURPOSE = 'conversation_knowledge_suggestion';

    public function store(Request $request, string $supportCode, ConversationReturnPath $returnPath): RedirectResponse
    {
        $conversation = $this->conversationForAgent($request->user(), $supportCode);

        return app(AgentConversationAiPreparationController::class)->nativeStore($request, $conversation, self::PURPOSE, $returnPath);
    }

    public function show(
        Request $request,
        string $supportCode,
        AgentCopilotConnectionResolver $connections,
        ConversationReturnPath $returnPath,
        KnowledgeArticleSuggestionMatcher $articleMatcher,
    ): Response {
        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        if (app(AiAssistPolicyResolver::class)->forConversation($conversation)->explicit
            || $conversation->copilotKnowledgeSuggestion()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeShow($request, $conversation, self::PURPOSE);
        }

        abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
        abort_unless($connections->forAccount((int) $conversation->site->account_id) !== null, 404);
        $account = $conversation->site->account;

        if (! $account->articles()->published()->exists()) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        $latestTextMessageId = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''")
            ->max('id');

        if ($latestTextMessageId === null) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        $latestConversationMessageId = $conversation->messages()->max('id');
        $suggestion = $conversation->copilotKnowledgeSuggestion()->first();
        if ($suggestion !== null && (! $connections->isCurrent((int) $conversation->site->account_id, $suggestion->connection_revision) || ! $this->policyIsCurrent($conversation, $suggestion->policy_revision))) {
            $suggestion = null;
        }
        $suggestedKnowledgeArticles = $suggestion?->status === ConversationCopilotKnowledgeSuggestion::STATUS_READY
            ? $articleMatcher->present($account, $suggestion->suggestedArticleIds())
            : collect();

        return response()->view('agent.conversations.partials.copilot-knowledge-suggestion', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotKnowledgeSuggestion' => $suggestion,
            'latestConversationMessageId' => (int) $latestConversationMessageId,
            'suggestedKnowledgeArticles' => $suggestedKnowledgeArticles,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Return freshly authorized data; this never inserts or sends support text. */
    public function use(Request $request, string $supportCode, AgentCopilotConnectionResolver $connections, KnowledgeArticleSuggestionMatcher $articleMatcher): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $input = $request->validate(['generation' => ['required', 'uuid'], 'article_id' => ['required', 'integer', 'min:1'], 'article_revision' => ['required', 'regex:/\A[a-f0-9]{64}\z/']]);
        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        if (app(AiAssistPolicyResolver::class)->forConversation($conversation)->explicit
            || $conversation->copilotKnowledgeSuggestion()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeUse($request, $conversation, self::PURPOSE);
        }

        return DB::transaction(function () use ($request, $supportCode, $conversation, $input, $connections, $articleMatcher): JsonResponse {
            $accountId = (int) $conversation->site->account_id;
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $agent = User::query()->whereKey($request->user()->id)->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversationForAgent($agent, $supportCode);
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
            $suggestion = $conversation->copilotKnowledgeSuggestion()->where('generation', $input['generation'])
                ->where('status', ConversationCopilotKnowledgeSuggestion::STATUS_READY)->lockForUpdate()->first();
            abort_unless($suggestion !== null && $this->policyIsCurrent($conversation, $suggestion->policy_revision)
                && $connections->isCurrent($accountId, $suggestion->connection_revision), 409);
            abort_unless(is_string($suggestion->source_context_fingerprint) && $suggestion->source_context_fingerprint !== ''
                && ! $suggestion->isStaleComparedTo($conversation->messages()->max('id')), 409);
            $articleId = (int) $input['article_id'];
            abort_unless(in_array($articleId, $suggestion->suggestedArticleIds(), true), 404);
            $selected = $articleMatcher->present($conversation->site->account, [$articleId])->first();
            abort_unless($selected !== null && hash_equals(KnowledgeArticleSuggestionMatcher::revision($selected['article'], $selected['snippet']), $input['article_revision']), 409);

            return response()->json(['generation' => $suggestion->generation, 'policy_revision' => $suggestion->policy_revision, 'article_id' => $articleId, 'text' => $selected['snippet']])
                ->header('Cache-Control', 'no-store, private');
        });
    }

    private function policyIsCurrent(Conversation $conversation, ?string $revision): bool
    {
        try {
            return app(AiAssistPolicyResolver::class)->isCurrent((int) $conversation->site_id, self::PURPOSE, $revision);
        } catch (Throwable) {
            return false;
        }
    }

    private function conversationForAgent(User $agent, string $supportCode): Conversation
    {
        abort_unless($agent->account_id, 403);

        $conversation = Conversation::query()
            ->with('site.account')
            ->where('support_code', $supportCode)
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows('reply', $conversation), 404);

        return $conversation;
    }
}
