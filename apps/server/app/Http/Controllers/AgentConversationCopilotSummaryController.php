<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateConversationCopilotSummary;
use App\Models\AuditEvent;
use App\Models\Conversation;
use App\Models\ConversationCopilotSummary;
use App\Models\User;
use App\Support\Ai\AgentCopilotConfiguration;
use App\Support\Conversations\ConversationReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Queue and render the latest read-only summary suggestion for a conversation. */
final class AgentConversationCopilotSummaryController extends Controller
{
    public function store(
        Request $request,
        string $supportCode,
        AgentCopilotConfiguration $configuration,
        ConversationReturnPath $returnPath,
    ): RedirectResponse {
        abort_unless($configuration->isReady(), 404);

        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode);
        abort_unless($conversation->messages()->whereNotNull('body')->whereRaw("TRIM(body) <> ''")->exists(), 404);

        [$summary, $shouldDispatch] = DB::transaction(function () use ($agent, $conversation): array {
            // Serialize the first request too. Locking only a summary row that
            // does not exist lets two clicks race into the unique constraint.
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $summary = ConversationCopilotSummary::query()
                ->where('conversation_id', $conversation->id)
                ->lockForUpdate()
                ->first();

            if ($summary?->hasFreshPendingRequest()) {
                return [$summary, false];
            }

            $generation = (string) Str::uuid();
            $values = [
                'requested_by_id' => $agent->id,
                'generation' => $generation,
                'status' => ConversationCopilotSummary::STATUS_PENDING,
                'summary' => null,
                'source_last_message_id' => null,
                'source_message_count' => 0,
                'provider' => null,
                'model' => null,
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'failure_code' => null,
                'requested_at' => now(),
                'completed_at' => null,
            ];

            if ($summary === null) {
                $summary = ConversationCopilotSummary::query()->create([
                    'conversation_id' => $conversation->id,
                    ...$values,
                ]);
            } else {
                $summary->forceFill($values)->save();
            }

            AuditEvent::query()->create([
                'account_id' => $conversation->site->account_id,
                'site_id' => $conversation->site_id,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'subject_type' => $conversation->getMorphClass(),
                'subject_id' => $conversation->id,
                'action' => 'conversation.ai_summary.requested',
                'metadata' => ['summary_id' => $summary->id],
                'occurred_at' => now(),
            ]);

            return [$summary, true];
        });

        if ($shouldDispatch) {
            try {
                GenerateConversationCopilotSummary::dispatch($summary->id, $summary->generation);
            } catch (Throwable $exception) {
                Log::warning('Conversation copilot summary could not be queued.', [
                    'exception_type' => $exception::class,
                    'summary_id' => $summary->id,
                    'conversation_id' => $conversation->id,
                ]);
                $markedFailed = ConversationCopilotSummary::query()
                    ->whereKey($summary->id)
                    ->where('generation', $summary->generation)
                    ->where('status', ConversationCopilotSummary::STATUS_PENDING)
                    ->update([
                        'status' => ConversationCopilotSummary::STATUS_FAILED,
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
                        'action' => 'conversation.ai_summary.failed',
                        'metadata' => [
                            'summary_id' => $summary->id,
                            'failure_code' => 'queue',
                        ],
                        'occurred_at' => now(),
                    ]);
                }

                return redirect()
                    ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
                    ->with('status', 'conversations.flash.ai_summary_failed');
            }
        }

        return redirect()
            ->route('dashboard.conversations.show', $returnPath->routeParameters($conversation, $request))
            ->with('status', $shouldDispatch
                ? 'conversations.flash.ai_summary_queued'
                : 'conversations.flash.ai_summary_pending');
    }

    public function show(
        Request $request,
        string $supportCode,
        AgentCopilotConfiguration $configuration,
        ConversationReturnPath $returnPath,
    ): Response {
        abort_unless($configuration->isReady(), 404);

        $conversation = $this->conversationForAgent($request->user(), $supportCode);
        $latestMessageId = $conversation->messages()
            ->whereNotNull('body')
            ->whereRaw("TRIM(body) <> ''")
            ->max('id');

        if ($latestMessageId === null) {
            return response('', 204)->header('Cache-Control', 'no-store, private');
        }

        return response()->view('agent.conversations.partials.copilot-summary', [
            'conversation' => $conversation,
            'conversationReturnQuery' => $returnPath->query($request),
            'copilotSummary' => $conversation->copilotSummary()->first(),
            'latestSummarizableMessageId' => (int) $latestMessageId,
        ])->header('Cache-Control', 'no-store, private');
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
