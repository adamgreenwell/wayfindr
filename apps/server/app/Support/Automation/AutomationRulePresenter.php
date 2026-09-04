<?php

namespace App\Support\Automation;

use App\Enums\AutomationRuleActionType;
use App\Enums\AutomationRuleConditionField;
use App\Enums\AutomationRuleEvent;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\Ticket;

final class AutomationRulePresenter
{
    public function event(AutomationRuleEvent|string $event): string
    {
        $value = $event instanceof AutomationRuleEvent ? $event->value : $event;

        return __('automation_rules.events.'.str_replace('.', '_', $value));
    }

    /** @param array{field: string, operator: string, value?: mixed, expected?: mixed, actual?: mixed, matched?: bool} $condition */
    public function condition(array $condition, AutomationRuleEvent|string $event, array $references, string $valueKey = 'value'): string
    {
        $field = AutomationRuleConditionField::from($condition['field']);
        $value = $condition[$valueKey] ?? null;

        return __('automation_rules.condition_sentence', [
            'field' => __('automation_rules.condition_fields.'.$field->value),
            'operator' => __('automation_rules.operators.'.$condition['operator']),
            'value' => $this->conditionValue($field, $value, $event, $references),
        ]);
    }

    /** @param array{field: string, value?: mixed, expected?: mixed, actual?: mixed} $condition */
    public function conditionValueOnly(array $condition, AutomationRuleEvent|string $event, array $references, string $valueKey): string
    {
        return $this->conditionValue(
            AutomationRuleConditionField::from($condition['field']),
            $condition[$valueKey] ?? null,
            $event,
            $references,
        );
    }

    /** @param array{type: string, value: mixed} $action */
    public function action(array $action, AutomationRuleEvent|string $event, array $references): string
    {
        $type = AutomationRuleActionType::from($action['type']);

        return __('automation_rules.action_sentence', [
            'action' => __('automation_rules.actions.'.$type->value),
            'value' => $this->actionValue($type, $action['value'], $event, $references),
        ]);
    }

    /** @param array{type: string, status: string, detail: string} $result */
    public function result(array $result, AutomationRuleEvent|string $event, array $references): string
    {
        $type = AutomationRuleActionType::from($result['type']);

        return __('automation_rules.result_sentence', [
            'action' => __('automation_rules.actions.'.$type->value),
            'status' => __('automation_rules.result_statuses.'.$result['status']),
            'detail' => $this->resultDetail($type, $event, $result['detail'], $references),
        ]);
    }

    public function executionSubject(AutomationRuleExecution $execution): string
    {
        if ($execution->subject instanceof Ticket) {
            return __('automation_rules.subjects.ticket', [
                'id' => $execution->subject->id,
                'subject' => $execution->subject->subject,
            ]);
        }

        if ($execution->subject instanceof Conversation) {
            return __('automation_rules.subjects.conversation', [
                'code' => $execution->subject->support_code,
                'subject' => $execution->subject->subject,
            ]);
        }

        return __('automation_rules.subjects.removed', ['id' => $execution->subject_id]);
    }

    private function conditionValue(
        AutomationRuleConditionField $field,
        mixed $value,
        AutomationRuleEvent|string $event,
        array $references,
    ): string {
        if ($value === null) {
            return __('automation_rules.values.none');
        }

        return match ($field) {
            AutomationRuleConditionField::SiteId => $references['site:'.$value] ?? __('automation_rules.values.removed_id', ['id' => $value]),
            AutomationRuleConditionField::AssigneeId => $references['agent:'.$value] ?? __('automation_rules.values.removed_id', ['id' => $value]),
            AutomationRuleConditionField::Priority => __('tickets.priorities.'.$value),
            AutomationRuleConditionField::Status => $this->status((string) $value, $event),
            AutomationRuleConditionField::Category => __('tickets.categories.'.$value),
            default => '“'.(string) $value.'”',
        };
    }

    private function actionValue(
        AutomationRuleActionType $type,
        mixed $value,
        AutomationRuleEvent|string $event,
        array $references,
    ): string {
        return match ($type) {
            AutomationRuleActionType::AssignAgent,
            AutomationRuleActionType::NotifyAgent => $references['agent:'.$value] ?? __('automation_rules.values.removed_id', ['id' => $value]),
            AutomationRuleActionType::AddLabel => $references['label:'.$value] ?? __('automation_rules.values.removed_id', ['id' => $value]),
            AutomationRuleActionType::SetPriority => __('tickets.priorities.'.$value),
            AutomationRuleActionType::SetStatus => $this->status((string) $value, $event),
            AutomationRuleActionType::PostInternalNote => '“'.(string) $value.'”',
        };
    }

    private function status(string $value, AutomationRuleEvent|string $event): string
    {
        $event = $event instanceof AutomationRuleEvent ? $event : AutomationRuleEvent::from($event);

        return $event->isTicketEvent()
            ? __('tickets.statuses.'.$value)
            : __('conversations.detail.statuses.'.$value);
    }

    private function resultDetail(
        AutomationRuleActionType $type,
        AutomationRuleEvent|string $event,
        string $detail,
        array $references,
    ): string {
        if (str_starts_with($detail, 'agent:')) {
            return $references[$detail] ?? __('automation_rules.values.removed_id', ['id' => substr($detail, 6)]);
        }

        if (str_starts_with($detail, 'label:')) {
            return $references[$detail] ?? __('automation_rules.values.removed_id', ['id' => substr($detail, 6)]);
        }

        if (str_contains($detail, '->')) {
            [$before, $after] = explode('->', $detail, 2);

            return __('automation_rules.result_change', [
                'before' => $this->resultValue($type, $event, $before),
                'after' => $this->resultValue($type, $event, $after),
            ]);
        }

        if (str_starts_with($detail, 'already:')) {
            return __('automation_rules.result_already', [
                'value' => $this->resultValue($type, $event, substr($detail, 8)),
            ]);
        }

        return __('automation_rules.result_details.'.$detail);
    }

    private function resultValue(
        AutomationRuleActionType $type,
        AutomationRuleEvent|string $event,
        string $value,
    ): string {
        return match ($type) {
            AutomationRuleActionType::SetPriority => __('tickets.priorities.'.$value),
            AutomationRuleActionType::SetStatus => $this->status($value, $event),
            default => $value,
        };
    }
}
