<?php

namespace App\Support\Automation;

use App\Enums\AutomationExecutionStatus;
use App\Events\TicketUpdated;
use App\Models\AuditEvent;
use App\Models\AutomationMacro;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class AutomationMacroRunner
{
    public function __construct(
        private AutomationActionExecutor $executor,
        private AutomationMacroAuthorization $authorization,
        private SiteManagerCoverage $siteManagerCoverage,
    ) {}

    public function run(
        User $candidateAgent,
        AutomationMacro $candidateMacro,
        Ticket|Conversation $candidateSubject,
    ): AutomationRuleExecution {
        $startedAt = now();

        try {
            return DB::transaction(function () use ($candidateAgent, $candidateMacro, $candidateSubject, $startedAt): AutomationRuleExecution {
                $accountId = $this->accountId($candidateSubject);
                $this->siteManagerCoverage->lockAccount($accountId);
                $agent = User::query()
                    ->with('customRole')
                    ->whereKey($candidateAgent->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $subject = $this->lockedSubject($candidateSubject, $accountId);
                $macro = AutomationMacro::query()
                    ->whereKey($candidateMacro->id)
                    ->where('account_id', $accountId)
                    ->where('subject_type', $candidateMacro->subject_type)
                    ->enabled()
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless($this->authorization->allows($agent, $macro, $subject), 404);

                $previousTicketRuleState = $subject instanceof Ticket
                    ? $this->ticketRuleState($subject)
                    : null;
                $results = $this->executor->execute(
                    AutomationActionContext::forMacro($macro, $agent),
                    $subject,
                    $macro->actions,
                );
                $execution = AutomationRuleExecution::query()->create([
                    ...$this->executionIdentity($macro, $agent, $subject),
                    'status' => AutomationExecutionStatus::Succeeded,
                    'conditions' => [],
                    'actions' => $macro->actions,
                    'action_results' => $results,
                    'error_message' => null,
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                ]);

                AuditEvent::query()->create([
                    'account_id' => $accountId,
                    'site_id' => $subject->site_id,
                    'actor_type' => $agent->getMorphClass(),
                    'actor_id' => $agent->id,
                    'subject_type' => $macro->getMorphClass(),
                    'subject_id' => $macro->id,
                    'action' => 'automation_macro.applied',
                    'metadata' => [
                        'name' => $macro->name,
                        'subject_type' => $macro->subject_type,
                        'support_subject_type' => $subject->getMorphClass(),
                        'support_subject_id' => $subject->getKey(),
                        'action_count' => count($macro->actions),
                        'execution_id' => $execution->id,
                    ],
                    'occurred_at' => now(),
                ]);

                if ($subject instanceof Ticket) {
                    $this->executor->notifyFinalTicketAssignmentAfterCommit(
                        $subject,
                        $previousTicketRuleState['assignee_id'],
                    );

                    if ($this->ticketRuleState($subject) !== $previousTicketRuleState) {
                        event(new TicketUpdated($subject));
                    }
                }

                return $execution;
            });
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordFailure(
                $candidateAgent,
                $candidateMacro,
                $candidateSubject,
                $startedAt,
                $exception,
            );

            throw new AutomationMacroRunFailed(previous: $exception);
        }
    }

    private function lockedSubject(Ticket|Conversation $subject, int $accountId): Ticket|Conversation
    {
        if ($subject instanceof Ticket) {
            return Ticket::query()
                ->with('site')
                ->whereKey($subject->id)
                ->where('account_id', $accountId)
                ->where('site_id', $subject->site_id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return Conversation::query()
            ->with('site')
            ->whereKey($subject->id)
            ->where('site_id', $subject->site_id)
            ->whereHas('site', fn ($query) => $query->where('account_id', $accountId))
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array{
     *     account_id: int,
     *     automation_rule_id: null,
     *     automation_macro_id: int,
     *     triggered_by_user_id: int,
     *     subject_type: string,
     *     subject_id: int,
     *     rule_name: string,
     *     event: string,
     *     metadata: array<string, int|string>
     * }
     */
    private function executionIdentity(
        AutomationMacro $macro,
        User $agent,
        Ticket|Conversation $subject,
    ): array {
        return [
            'account_id' => (int) $macro->account_id,
            'automation_rule_id' => null,
            'automation_macro_id' => (int) $macro->id,
            'triggered_by_user_id' => (int) $agent->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (int) $subject->getKey(),
            'rule_name' => (string) $macro->name,
            'event' => 'macro.'.$macro->subject_type,
            'metadata' => [
                'source' => 'macro',
                'automation_macro_id' => (int) $macro->id,
                'triggered_by_user_id' => (int) $agent->id,
                'triggered_by_name' => (string) $agent->name,
            ],
        ];
    }

    private function recordFailure(
        User $agent,
        AutomationMacro $macro,
        Ticket|Conversation $subject,
        Carbon $startedAt,
        Throwable $exception,
    ): void {
        try {
            AutomationRuleExecution::query()->create([
                ...$this->executionIdentity($macro, $agent, $subject),
                'status' => AutomationExecutionStatus::Failed,
                'conditions' => [],
                'actions' => $this->rawActions($macro),
                'action_results' => [],
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);
        } catch (Throwable $loggingException) {
            Log::error('Automation macro failed and its execution record could not be stored.', [
                'automation_macro_id' => $macro->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'exception_class' => $exception::class,
                'logging_exception_class' => $loggingException::class,
            ]);
        }

        Log::warning('Automation macro execution failed.', [
            'automation_macro_id' => $macro->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'exception_class' => $exception::class,
        ]);
    }

    /** @return list<mixed> */
    private function rawActions(AutomationMacro $macro): array
    {
        $actions = $macro->getRawOriginal('actions');

        if (! is_string($actions)) {
            return [];
        }

        $decoded = json_decode($actions, true);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    private function accountId(Ticket|Conversation $subject): int
    {
        if ($subject instanceof Ticket) {
            return (int) $subject->account_id;
        }

        $subject->loadMissing('site');

        return (int) $subject->site?->account_id;
    }

    /** @return array{assignee_id: int|null, priority: string, status: string} */
    private function ticketRuleState(Ticket $ticket): array
    {
        return [
            'assignee_id' => $ticket->assignee_id === null ? null : (int) $ticket->assignee_id,
            'priority' => (string) $ticket->priority,
            'status' => (string) $ticket->status,
        ];
    }
}
