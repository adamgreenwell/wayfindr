<?php

namespace App\Support\Automation;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Ticket;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final readonly class AutomationRuleEngine
{
    public function __construct(
        private AutomationExecutionGuard $guard,
        private AutomationRuleEvaluator $evaluator,
        private AutomationActionExecutor $executor,
        private SiteManagerCoverage $siteManagerCoverage,
    ) {}

    public function handle(
        AutomationRuleEvent $event,
        Ticket|Conversation $subject,
        ?ConversationMessage $message = null,
    ): void {
        if (! $this->guard->enter($subject)) {
            return;
        }

        try {
            $accountId = $this->accountId($subject);
            $previousTicketAssigneeId = $subject instanceof Ticket && $subject->assignee_id !== null
                ? (int) $subject->assignee_id
                : null;
            $rules = AutomationRule::query()
                ->where('account_id', $accountId)
                ->enabled()
                ->forEvent($event)
                ->inEvaluationOrder()
                ->get();
            $executed = false;

            foreach ($rules as $rule) {
                $executed = $this->runRule($event, $rule, $subject, $message, $accountId) || $executed;
            }

            if ($executed) {
                $subject->refresh();

                if ($subject instanceof Ticket) {
                    $this->executor->notifyFinalTicketAssignmentAfterCommit($subject, $previousTicketAssigneeId);
                }
            }
        } catch (Throwable $exception) {
            // Automation is an enhancement to support work, never permission
            // to make creating or updating that work fail. Per-rule failures
            // are captured below; this outer catch protects the event itself.
            Log::error('Automation event evaluation failed.', [
                'event' => $event->value,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'exception_class' => $exception::class,
            ]);
        } finally {
            $this->guard->leave($subject);
        }
    }

    private function runRule(
        AutomationRuleEvent $event,
        AutomationRule $candidate,
        Ticket|Conversation $subject,
        ?ConversationMessage $message,
        int $accountId,
    ): bool {
        $startedAt = now();

        try {
            return DB::transaction(function () use ($accountId, $candidate, $event, $message, $startedAt, $subject): bool {
                $this->siteManagerCoverage->lockAccount($accountId);
                $rule = AutomationRule::query()
                    ->whereKey($candidate->id)
                    ->where('account_id', $accountId)
                    ->enabled()
                    ->forEvent($event)
                    ->lockForUpdate()
                    ->first();

                if (! $rule instanceof AutomationRule) {
                    return false;
                }

                $lockedSubject = $this->lockedSubject($subject, $accountId);
                $lockedMessage = $this->lockedMessage($event, $message, $lockedSubject);
                $preview = $this->evaluator->preview($rule, $lockedSubject, $lockedMessage);

                if (! $preview['matched']) {
                    return false;
                }

                $results = $this->executor->execute(
                    AutomationActionContext::forRule($rule),
                    $lockedSubject,
                    $preview['actions'],
                );

                AutomationRuleExecution::query()->create([
                    ...$this->executionIdentity($rule, $event, $lockedSubject, $lockedMessage),
                    'status' => AutomationExecutionStatus::Succeeded,
                    'conditions' => $rule->conditions,
                    'actions' => $rule->actions,
                    'action_results' => $results,
                    'error_message' => null,
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            $this->recordFailure($candidate, $event, $subject, $message, $startedAt, $exception, $accountId);

            return false;
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
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockedMessage(
        AutomationRuleEvent $event,
        ?ConversationMessage $message,
        Ticket|Conversation $subject,
    ): ?ConversationMessage {
        if ($event !== AutomationRuleEvent::VisitorMessageCreated || ! $subject instanceof Conversation) {
            return null;
        }

        return ConversationMessage::query()
            ->whereKey($message?->id)
            ->where('conversation_id', $subject->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function recordFailure(
        AutomationRule $rule,
        AutomationRuleEvent $event,
        Ticket|Conversation $subject,
        ?ConversationMessage $message,
        Carbon $startedAt,
        Throwable $exception,
        int $accountId,
    ): void {
        try {
            AutomationRuleExecution::query()->create([
                ...$this->executionIdentity($rule, $event, $subject, $message),
                'account_id' => $accountId,
                'status' => AutomationExecutionStatus::Failed,
                'conditions' => $this->rawList($rule, 'conditions'),
                'actions' => $this->rawList($rule, 'actions'),
                'action_results' => [],
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'started_at' => $startedAt,
                'completed_at' => now(),
            ]);
        } catch (Throwable $loggingException) {
            Log::error('Automation failed and its execution record could not be stored.', [
                'automation_rule_id' => $rule->id,
                'event' => $event->value,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'exception_class' => $exception::class,
                'logging_exception_class' => $loggingException::class,
            ]);

            return;
        }

        Log::warning('Automation rule execution failed without interrupting support work.', [
            'automation_rule_id' => $rule->id,
            'event' => $event->value,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'exception_class' => $exception::class,
        ]);
    }

    /**
     * @return array{
     *     account_id: int,
     *     automation_rule_id: int,
     *     subject_type: string,
     *     subject_id: int,
     *     rule_name: string,
     *     event: string,
     *     metadata: array{message_id: int|null}
     * }
     */
    private function executionIdentity(
        AutomationRule $rule,
        AutomationRuleEvent $event,
        Ticket|Conversation $subject,
        ?ConversationMessage $message,
    ): array {
        return [
            'account_id' => (int) $rule->account_id,
            'automation_rule_id' => (int) $rule->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (int) $subject->getKey(),
            'rule_name' => (string) $rule->name,
            'event' => $event->value,
            'metadata' => ['message_id' => $message?->id],
        ];
    }

    /** @return list<mixed> */
    private function rawList(AutomationRule $rule, string $field): array
    {
        try {
            $decoded = json_decode((string) $rule->getRawOriginal($field), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function accountId(Ticket|Conversation $subject): int
    {
        if ($subject instanceof Ticket) {
            return (int) $subject->account_id;
        }

        $subject->loadMissing('site');

        return (int) $subject->site?->account_id;
    }
}
