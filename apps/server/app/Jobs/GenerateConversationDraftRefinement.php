<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Ai\AgentCopilotConnectionResolver;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\Network\AiNetworkException;
use App\Support\Ai\Refinement\DraftRefinementOutputParser;
use App\Support\Ai\Refinement\DraftRefinements;
use App\Support\Ai\Refinement\DraftRefinementSafetyGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Queue identifiers only; never serialize unsent text, context, sources or previews. */
final class GenerateConversationDraftRefinement implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 85;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    public function __construct(private readonly int $refinementId, private readonly string $generation) {}

    public function uniqueId(): string
    {
        return $this->refinementId.':'.$this->generation;
    }

    public function handle(DraftRefinements $refinements, AgentCopilotProvider $provider, AgentCopilotConnectionResolver $connections): void
    {
        $result = null;
        $claimed = false;
        try {
            $row = $refinements->claim($this->refinementId, $this->generation);
            if ($row === null) {
                return;
            }
            $claimed = true;
            $context = $refinements->context($row);
            $connection = $connections->forAccount((int) $row->account_id);
            if ($connection === null) {
                throw new AiNetworkException('connection_changed');
            }
            $result = $provider->generate($context->providerPrompt()->withExecution($connection, $refinements->execution($row)));
            $proposal = app(DraftRefinementOutputParser::class)->parse($result->text, $context->providerSources());
            if ($proposal === null || ! app(DraftRefinementSafetyGuard::class)->permits($context, $proposal)) {
                $result->settle('invalid_output');
                $refinements->fail($this->refinementId, $this->generation, 'invalid_output');

                return;
            }
            $refinements->complete($this->refinementId, $this->generation, $result, $proposal);
        } catch (Throwable $exception) {
            try {
                $result?->settle('invalid_output');
            } catch (Throwable) {
                // Withhold all output when usage settlement is unavailable.
            }
            $reason = $exception instanceof AiNetworkException && in_array($exception->reason,
                ['connection_changed', 'access_revoked', 'context_changed', 'usage_token_limit', 'usage_request_limit', 'usage_concurrency_limit'], true)
                ? $exception->reason : 'provider_failed';
            $refinements->fail($this->refinementId, $this->generation, $reason);
        } finally {
            // The bounded provider call has returned; cancellation never removes this claim early.
            if ($claimed) {
                DB::table('conversation_draft_refinement_sends')->where('generation', $this->generation)->delete();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(DraftRefinements::class)->fail($this->refinementId, $this->generation, 'provider_failed');
        // An interrupted transport is uncertain: the 180-second claim window remains.
    }
}
