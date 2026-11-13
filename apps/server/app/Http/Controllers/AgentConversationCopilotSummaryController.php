<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Queue and render the latest read-only summary suggestion for a conversation. */
final class AgentConversationCopilotSummaryController extends Controller
{
    private const PURPOSE = 'conversation_summary';

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
            || $conversation->copilotSummary()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeShow($request, $conversation, self::PURPOSE);
        }

        abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
        abort_unless($connections->forAccount((int) $conversation->site->account_id) !== null, 404);
        $latestMessageId = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''")
            ->max('id');

        if ($latestMessageId === null) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        $summary = $conversation->copilotSummary()->first();
        if ($summary !== null && (! $connections->isCurrent((int) $conversation->site->account_id, $summary->connection_revision) || ! $this->policyIsCurrent($conversation, $summary->policy_revision))) {
            $summary = null;
        }

        return response()->view('agent.conversations.partials.copilot-summary', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotSummary' => $summary,
            'latestSummarizableMessageId' => (int) $latestMessageId,
        ])->header('Cache-Control', 'no-store, private');
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

        abort_unless(Gate::forUser($agent)->allows('view', $conversation), 404);

        return $conversation;
    }
}
