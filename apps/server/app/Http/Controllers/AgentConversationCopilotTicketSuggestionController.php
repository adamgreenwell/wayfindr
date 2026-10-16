<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationCopilotTicketSuggestion;
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

/** Queue and render title, priority, and local-label suggestions for ticket creation. */
final class AgentConversationCopilotTicketSuggestionController extends Controller
{
    private const PURPOSE = 'conversation_ticket_suggestion';

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
            || $conversation->copilotTicketSuggestion()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeShow($request, $conversation, self::PURPOSE);
        }

        abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
        abort_unless($connections->forAccount((int) $conversation->site->account_id) !== null, 404);

        if ($conversation->tickets()->exists()) {
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
        $suggestion = $conversation->copilotTicketSuggestion()->first();
        if ($suggestion !== null && (! $connections->isCurrent((int) $conversation->site->account_id, $suggestion->connection_revision) || ! $this->policyIsCurrent($conversation, $suggestion->policy_revision))) {
            $suggestion = null;
        }
        $suggestedLabels = collect();

        if ($suggestion?->status === ConversationCopilotTicketSuggestion::STATUS_READY) {
            $suggestedLabels = $conversation->site->account->ticketLabels()
                ->whereKey($suggestion->suggestedLabelIds())
                ->orderBy('name')
                ->get();
        }

        return response()->view('agent.conversations.partials.copilot-ticket-suggestion', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotTicketSuggestion' => $suggestion,
            'latestConversationMessageId' => (int) $latestConversationMessageId,
            'suggestedTicketLabels' => $suggestedLabels,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** Return freshly authorized data; this never inserts or sends support text. */
    public function use(Request $request, string $supportCode, AgentCopilotConnectionResolver $connections): JsonResponse
    {
        abort_unless($request->expectsJson(), 406);
        $input = $request->validate(['generation' => ['required', 'uuid']]);
        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        if (app(AiAssistPolicyResolver::class)->forConversation($conversation)->explicit
            || $conversation->copilotTicketSuggestion()->whereNotNull('preparation_fingerprint')->exists()) {
            return app(AgentConversationAiPreparationController::class)->nativeUse($request, $conversation, self::PURPOSE);
        }

        return DB::transaction(function () use ($request, $supportCode, $conversation, $input, $connections): JsonResponse {
            $accountId = (int) $conversation->site->account_id;
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $agent = User::query()->whereKey($request->user()->id)->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversationForAgent($agent, $supportCode);
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
            $suggestion = $conversation->copilotTicketSuggestion()->where('generation', $input['generation'])
                ->where('status', ConversationCopilotTicketSuggestion::STATUS_READY)->lockForUpdate()->first();
            abort_unless($suggestion !== null && $this->policyIsCurrent($conversation, $suggestion->policy_revision)
                && $connections->isCurrent($accountId, $suggestion->connection_revision), 409);
            abort_unless(is_string($suggestion->source_context_fingerprint) && $suggestion->source_context_fingerprint !== ''
                && ! $suggestion->isStaleComparedTo($conversation->messages()->max('id')), 409);
            abort_if($conversation->tickets()->exists(), 409);
            $labels = $conversation->site->account->ticketLabels()->whereKey($suggestion->suggestedLabelIds())
                ->orderBy('id')->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();

            return response()->json(['generation' => $suggestion->generation, 'policy_revision' => $suggestion->policy_revision, 'title' => $suggestion->title, 'priority' => $suggestion->priority, 'label_ids' => $labels])
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

        abort_unless(Gate::forUser($agent)->allows('createTicket', $conversation), 404);

        return $conversation;
    }
}
