<?php

namespace App\Support\Automation;

use App\Enums\AutomationRuleConditionField;
use App\Enums\AutomationRuleConditionOperator;
use App\Enums\AutomationRuleEvent;
use App\Models\AutomationRule;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Ticket;
use App\Models\Visitor;
use InvalidArgumentException;

final class AutomationRuleEvaluator
{
    /**
     * Return the ordered action plans for enabled rules matching one event.
     *
     * @return list<array{
     *     rule_id: int,
     *     rule_name: string,
     *     event: string,
     *     matched: true,
     *     conditions: list<array{field: string, operator: string, expected: mixed, actual: mixed, matched: bool}>,
     *     actions: list<array{type: string, value: mixed}>,
     *     withheld_actions: list<array{type: string, value: mixed}>
     * }>
     */
    public function plan(
        AutomationRuleEvent $event,
        Ticket|Conversation $subject,
        ?ConversationMessage $message = null,
    ): array {
        $accountId = $this->accountId($subject);
        $this->assertContext($event, $subject, $message);

        return AutomationRule::query()
            ->where('account_id', $accountId)
            ->enabled()
            ->forEvent($event)
            ->inEvaluationOrder()
            ->get()
            ->map(fn (AutomationRule $rule): array => $this->preview($rule, $subject, $message))
            ->filter(fn (array $evaluation): bool => $evaluation['matched'])
            ->values()
            ->all();
    }

    /**
     * Evaluate one rule without changing anything, including disabled drafts.
     *
     * @return array{
     *     rule_id: int,
     *     rule_name: string,
     *     event: string,
     *     matched: bool,
     *     conditions: list<array{field: string, operator: string, expected: mixed, actual: mixed, matched: bool}>,
     *     actions: list<array{type: string, value: mixed}>,
     *     withheld_actions: list<array{type: string, value: mixed}>
     * }
     */
    public function preview(
        AutomationRule $rule,
        Ticket|Conversation $subject,
        ?ConversationMessage $message = null,
    ): array {
        $event = $rule->eventEnum();
        $this->assertContext($event, $subject, $message);

        if ((int) $rule->account_id !== $this->accountId($subject)) {
            throw new InvalidArgumentException('The automation rule and subject must belong to the same account.');
        }

        // A withheld action is one that would close the conversation on a
        // visitor who is waiting for a reply. It is left out of what would run
        // -- here and live, since the engine runs exactly this list and logs
        // exactly the withheld one -- instead of failing the whole rule on
        // every event it sees.
        $visitorAwaitingReply = $this->visitorAwaitingReply($event, $subject);
        $withholds = fn (mixed $action): bool => AutomationRuleDefinition::withholdsAction($event, $action, $visitorAwaitingReply);
        $actions = array_values(array_filter($rule->actions, fn (mixed $action): bool => ! $withholds($action)));
        $withheldActions = array_values(array_filter($rule->actions, $withholds));

        AutomationRuleDefinition::assertValid($event, $rule->conditions, $actions);

        $conditions = array_map(function (array $condition) use ($subject, $message): array {
            $field = AutomationRuleConditionField::from($condition['field']);
            $operator = AutomationRuleConditionOperator::from($condition['operator']);
            $actual = $this->actualValue($field, $subject, $message);

            return [
                'field' => $field->value,
                'operator' => $operator->value,
                'expected' => $condition['value'],
                'actual' => $actual,
                'matched' => $this->matches($operator, $actual, $condition['value']),
            ];
        }, $rule->conditions);

        $matched = collect($conditions)->every(fn (array $condition): bool => $condition['matched']);

        return [
            'rule_id' => (int) $rule->id,
            'rule_name' => (string) $rule->name,
            'event' => $event->value,
            'matched' => $matched,
            'conditions' => $conditions,
            'actions' => $matched ? $actions : [],
            'withheld_actions' => $matched ? $withheldActions : [],
        ];
    }

    /**
     * Whether a visitor is waiting for a reply as this event's rules run.
     *
     * Always, for a visitor message. For a new conversation, when it already
     * holds a visitor message: inbound mail stores the conversation and its
     * first message together and announces the creation first, while the
     * widget and the API open an empty conversation that the visitor writes
     * into afterwards. A dry run asks the same question of the conversation as
     * it stands now, as it does of every condition.
     */
    private function visitorAwaitingReply(AutomationRuleEvent $event, Ticket|Conversation $subject): bool
    {
        return match ($event) {
            AutomationRuleEvent::VisitorMessageCreated => true,
            AutomationRuleEvent::ConversationCreated => $subject instanceof Conversation
                && $subject->messages()->where('sender_type', Visitor::class)->exists(),
            default => false,
        };
    }

    private function accountId(Ticket|Conversation $subject): int
    {
        if ($subject instanceof Ticket) {
            return (int) $subject->account_id;
        }

        $subject->loadMissing('site');

        if ($subject->site === null) {
            throw new InvalidArgumentException('A conversation must belong to a site before rules can be evaluated.');
        }

        return (int) $subject->site->account_id;
    }

    private function assertContext(
        AutomationRuleEvent $event,
        Ticket|Conversation $subject,
        ?ConversationMessage $message,
    ): void {
        if ($event->isTicketEvent() !== $subject instanceof Ticket) {
            throw new InvalidArgumentException("{$event->value} cannot be evaluated against this subject type.");
        }

        if ($event !== AutomationRuleEvent::VisitorMessageCreated) {
            return;
        }

        if ($message === null
            || (int) $message->conversation_id !== (int) $subject->id
            || $message->sender_type !== Visitor::class) {
            throw new InvalidArgumentException('A visitor-message rule requires a visitor message from the same conversation.');
        }
    }

    private function actualValue(
        AutomationRuleConditionField $field,
        Ticket|Conversation $subject,
        ?ConversationMessage $message,
    ): mixed {
        return match ($field) {
            AutomationRuleConditionField::Subject => $subject->subject,
            AutomationRuleConditionField::Description => $subject instanceof Ticket ? $subject->description : null,
            AutomationRuleConditionField::Status => $subject->status,
            AutomationRuleConditionField::Priority => $subject->priority,
            AutomationRuleConditionField::Category => $subject instanceof Ticket ? $subject->category : null,
            AutomationRuleConditionField::SiteId => (int) $subject->site_id,
            AutomationRuleConditionField::AssigneeId => $subject instanceof Ticket
                ? ($subject->assignee_id === null ? null : (int) $subject->assignee_id)
                : ($subject->assigned_agent_id === null ? null : (int) $subject->assigned_agent_id),
            AutomationRuleConditionField::MessageBody => $message?->body,
        };
    }

    private function matches(AutomationRuleConditionOperator $operator, mixed $actual, mixed $expected): bool
    {
        return match ($operator) {
            AutomationRuleConditionOperator::Equals => $this->equal($actual, $expected),
            AutomationRuleConditionOperator::NotEquals => ! $this->equal($actual, $expected),
            AutomationRuleConditionOperator::Contains => $this->contains($actual, $expected),
            AutomationRuleConditionOperator::NotContains => ! $this->contains($actual, $expected),
        };
    }

    private function equal(mixed $actual, mixed $expected): bool
    {
        if (is_string($actual) && is_string($expected)) {
            return mb_strtolower($actual) === mb_strtolower($expected);
        }

        return $actual === $expected;
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        return is_string($actual)
            && is_string($expected)
            && mb_stripos($actual, $expected) !== false;
    }
}
