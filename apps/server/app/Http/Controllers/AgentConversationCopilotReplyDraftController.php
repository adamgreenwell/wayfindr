<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationCopilotReplyDraft;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Queue and render the latest agent-reviewed reply suggestion for a conversation. */
final class AgentConversationCopilotReplyDraftController extends Controller
{
    private const PURPOSE = 'conversation_reply_draft';

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
    ): Response {
        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        if (app(AiAssistPolicyResolver::class)->forConversation($conversation)->explicit
            || $conversation->copilotReplyDraft()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeShow($request, $conversation, self::PURPOSE);
        }

        abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
        abort_unless($connections->forAccount((int) $conversation->site->account_id) !== null, 404);
        $latestTextMessageId = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''")
            ->max('id');

        if ($latestTextMessageId === null) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        $latestConversationMessageId = $conversation->messages()->max('id');

        $draft = $conversation->copilotReplyDraft()->first();
        if ($draft !== null && (! $connections->isCurrent((int) $conversation->site->account_id, $draft->connection_revision) || ! $this->policyIsCurrent($conversation, $draft->policy_revision))) {
            $draft = null;
        }

        return response()->view('agent.conversations.partials.copilot-reply-draft', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotReplyDraft' => $draft,
            'latestConversationMessageId' => (int) $latestConversationMessageId,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Return freshly authorized data; this never inserts or sends support text. */
    public function use(Request $request, string $supportCode, AgentCopilotConnectionResolver $connections): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $input = $request->validate(['generation' => ['required', 'uuid']]);
        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        if (app(AiAssistPolicyResolver::class)->forConversation($conversation)->explicit
            || $conversation->copilotReplyDraft()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeUse($request, $conversation, self::PURPOSE);
        }

        return DB::transaction(function () use ($request, $supportCode, $conversation, $input, $connections): JsonResponse {
            $accountId = (int) $conversation->site->account_id;
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $agent = User::query()->whereKey($request->user()->id)->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversationForAgent($agent, $supportCode);
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
            $draft = $conversation->copilotReplyDraft()->where('generation', $input['generation'])
                ->where('status', ConversationCopilotReplyDraft::STATUS_READY)->lockForUpdate()->first();
            abort_unless($draft !== null && $this->policyIsCurrent($conversation, $draft->policy_revision)
                && $connections->isCurrent($accountId, $draft->connection_revision), 409);
            abort_unless(is_string($draft->source_context_fingerprint) && $draft->source_context_fingerprint !== ''
                && ! $draft->isStaleComparedTo($conversation->messages()->max('id')), 409);

            return response()->json(['generation' => $draft->generation, 'policy_revision' => $draft->policy_revision, 'text' => $draft->draft])
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
            ->with('site')
            ->where('support_code', $supportCode)
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows('reply', $conversation), 404);

        return $conversation;
    }
}
