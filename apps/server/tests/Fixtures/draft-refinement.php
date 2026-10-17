<?php

declare(strict_types=1);

use App\Support\Ai\AgentCopilotPrompt;
use App\Support\Ai\AgentCopilotProvider;
use App\Support\Ai\AgentCopilotResult;
use App\Support\Ai\Execution\AiRequestAuthority;
use App\Support\Ai\Execution\AiSupportUsage;
use App\Support\Ai\Refinement\DraftRefinementAction;
use App\Support\Ai\Refinement\DraftRefinementInput;
use App\Support\Ai\Usage\AiUsageLedger;
use Illuminate\Support\Str;

function refinementLifecycleInput(string $draft = 'It may take 3 days.'): DraftRefinementInput
{
    return new DraftRefinementInput($draft, (string) Str::uuid(), DraftRefinementAction::Clarify);
}

function refinementLifecycleProvider(?Closure $during = null, ?Closure $output = null, ?Closure $beforeClaim = null): AgentCopilotProvider
{
    return new class($during, $output, $beforeClaim) implements AgentCopilotProvider
    {
        public int $calls = 0;

        public int $dispatches = 0;

        public function __construct(private ?Closure $during, private ?Closure $output, private ?Closure $beforeClaim) {}

        public function probe(): AgentCopilotResult
        {
            throw new LogicException('No synthetic probe.');
        }

        public function generate(AgentCopilotPrompt $prompt): AgentCopilotResult
        {
            $this->calls++;
            $execution = $prompt->execution;
            $connection = $prompt->connection;
            if ($execution->kind !== 'refinement') {
                throw new LogicException('Private fixture requires scoped refinement authority.');
            }
            $this->beforeClaim?->__invoke($prompt);
            AiSupportUsage::assertActualPrompt($connection, $execution, $prompt);
            $ledger = app(AiUsageLedger::class);
            $authority = app(AiRequestAuthority::class);
            $claim = $ledger->claim($connection, $execution->purpose, $execution->attemptId, fn () => $authority->assertAllowed($connection, $execution));
            $dispatch = $ledger->beginDispatch($connection, $execution->purpose, $execution->attemptId, $claim, fn () => $authority->assertAllowed($connection, $execution));
            $ledger->markUnknown($execution->attemptId, $dispatch->dispatchNonce);
            $this->dispatches++;
            $this->during?->__invoke($prompt);
            $text = $this->output?->__invoke($prompt) ?? json_encode(['candidate' => '  It might take 3 days.  ', 'citations' => [], 'limitation' => null], JSON_THROW_ON_ERROR);
            $session = new AssistPreparationSession($connection, fn ($reason) => $ledger->reconcile($execution->attemptId, $dispatch->dispatchNonce, 3, 4, $reason));

            return new AgentCopilotResult($text, 'synthetic-provider', 'synthetic-A', 3, 4, $session);
        }
    };
}
