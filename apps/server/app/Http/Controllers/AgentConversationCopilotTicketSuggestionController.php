<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateConversationCopilotTicketSuggestion;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotTicketSuggestion;
use App\Models\User;
use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Policy\AiAssistPolicyResolver;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Queue and render title, priority, and local-label suggestions for ticket creation. */
final class AgentConversationCopilotTicketSuggestionController extends Controller
{
    private const PURPOSE = 'conversation_ticket_suggestion';

    public function store(
        Request $request,
        string $supportCode,
        AgentCopilotConnectionResolver $connections,
        ConversationReturnPath $returnPath,
    ): RedirectResponse {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode);
        abort_unless(app(AiAssistPolicyResolver::class)->forConversation($conversation)->allows(self::PURPOSE), 404);
        abort_unless($connections->forAccount((int) $conversation->site->account_id) !== null, 404);
        abort_if($conversation->tickets()->exists(), 404);
        abort_unless($conversation->messages()->whereNotNull('body')->whereRaw("TRIM(body) <> ''")->exists(), 404);

        [$suggestion, $shouldDispatch] = DB::transaction(function () use ($agent, $conversation, $connections): array {
            $accountId = (int) $conversation->site->account_id;
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
            $agent = User::query()->whereKey($agent->id)->where('account_id', $accountId)->lockForUpdate()->firstOrFail();
            $conversation = $this->conversationForAgent($agent, $conversation->support_code);
            $policy = app(AiAssistPolicyResolver::class)->forConversation($conversation);
            abort_unless($policy->allows(self::PURPOSE), 404);
            $connection = $connections->forAccount($accountId);
            abort_unless($connection !== null, 404);

            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            if ($conversation->tickets()->exists()) {
                abort(404);
            }

            $suggestion = ConversationCopilotTicketSuggestion::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            // A running row is also the erasure-visible external-call claim.
            // Keep it intact until that attempt finishes rather than replacing
            // its start marker when a connection changes mid-call.
            if ($suggestion?->hasFreshPendingRequest()
                && $suggestion->status === ConversationCopilotTicketSuggestion::STATUS_RUNNING
                && ($suggestion->connection_revision !== $connection->revision || ! $this->policyIsCurrent($conversation, $suggestion->policy_revision))) {
                abort(409, __('conversations.flash.ai_ticket_suggestion_pending'));
            }

            if ($suggestion?->hasFreshPendingRequest() && $suggestion->connection_revision === $connection->revision && $this->policyIsCurrent($conversation, $suggestion->policy_revision)) {
                return [$suggestion, false];
            }

            $generation = (string) Str::uuid();
            $values = [
                'requested_by_id' => $agent->id,
                'generation' => $generation,
                'connection_revision' => $connection->revision,
                'policy_revision' => $policy->revisionFor(self::PURPOSE),
                'source_context_fingerprint' => null,
                'status' => ConversationCopilotTicketSuggestion::STATUS_PENDING,
                'title' => null,
                'priority' => null,
                'label_ids' => null,
                'source_last_message_id' => null,
                'source_message_count' => 0,
                'provider' => null,
                'model' => null,
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'failure_code' => null,
                'requested_at' => now(),
                'started_at' => null,
                'completed_at' => null,
            ];

            if ($suggestion === null) {
                $suggestion = ConversationCopilotTicketSuggestion::query()->create([
                    'conversation_id' => $conversation->id,
                    ...$values,
                ]);
            } else {
                $suggestion->forceFill($values)->save();
            }

            AuditEvent::query()->create([
                'account_id' => $conversation->site->account_id,
                'site_id' => $conversation->site_id,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'subject_type' => $conversation->getMorphClass(),
                'subject_id' => $conversation->id,
                'action' => 'conversation.ai_ticket_suggestion.requested',
                'metadata' => [
                    'suggestion_id' => $suggestion->id,
                    'generation' => $suggestion->generation,
                    'connection_revision' => $suggestion->connection_revision,
                ],
                'occurred_at' => now(),
            ]);

            return [$suggestion, true];
        });

        if ($shouldDispatch) {
            try {
                app(AiSupportUsage::class)->reserveFor($suggestion);
                GenerateConversationCopilotTicketSuggestion::dispatch($suggestion->id, $suggestion->generation);
            } catch (Throwable $exception) {
                try {
                    app(AiSupportUsage::class)->cancelFor($suggestion);
                } catch (Throwable) {
                    // Bounded expiry recovers an uncertain reservation release.
                }
                Log::warning('Conversation copilot ticket suggestion could not be queued.', [
                    'exception_type' => $exception::class,
                    'suggestion_id' => $suggestion->id,
                    'conversation_id' => $conversation->id,
                ]);
                DB::transaction(function () use ($agent, $conversation, $suggestion): void {
                    $markedFailed = ConversationCopilotTicketSuggestion::query()
                        ->whereKey($suggestion->id)
                        ->where('generation', $suggestion->generation)
                        ->where('status', ConversationCopilotTicketSuggestion::STATUS_PENDING)
                        ->update([
                            'status' => ConversationCopilotTicketSuggestion::STATUS_FAILED,
                            'failure_code' => 'queue',
                            'completed_at' => now(),
                        ]);

                    if ($markedFailed === 1) {
                        AuditEvent::query()->create([
                            'account_id' => $conversation->site->account_id,
                            'site_id' => $conversation->site_id,
                            'actor_type' => $agent->getMorphClass(),
                            'actor_id' => $agent->id,
                            'subject_type' => $conversation->getMorphClass(),
                            'subject_id' => $conversation->id,
                            'action' => 'conversation.ai_ticket_suggestion.failed',
                            'metadata' => [
                                'suggestion_id' => $suggestion->id,
                                'generation' => $suggestion->generation,
                                'failure_code' => 'queue',
                            ],
                            'occurred_at' => now(),
                        ]);
                    }
                });

                return redirect()
                    ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
                    ->with('status', 'conversations.flash.ai_ticket_suggestion_failed');
            }
        }

        return redirect()
            ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
            ->with('status', $shouldDispatch
                ? 'conversations.flash.ai_ticket_suggestion_queued'
                : 'conversations.flash.ai_ticket_suggestion_pending');
    }

    public function show(
        Request $request,
        string $supportCode,
        AgentCopilotConnectionResolver $connections,
        ConversationReturnPath $returnPath,
    ): Response {
        $conversation = $this->conversationForAgent($request->user(), $supportCode);
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
