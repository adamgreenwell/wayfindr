<?php

namespace App\Support\Automation;

use App\Enums\AutomationRuleActionType;
use App\Enums\AutomationRuleConditionField;
use App\Enums\AutomationRuleConditionOperator;
use App\Enums\AutomationRuleEvent;
use App\Enums\ConversationStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Support\TicketCategory;
use InvalidArgumentException;

final class AutomationRuleDefinition
{
    /**
     * @param  list<mixed>  $conditions
     * @param  list<mixed>  $actions
     */
    public static function assertValid(AutomationRuleEvent $event, array $conditions, array $actions): void
    {
        foreach ($conditions as $index => $condition) {
            self::assertCondition($event, $condition, $index);
        }

        foreach ($actions as $index => $action) {
            self::assertAction($event, $action, $index);
        }
    }

    private static function assertCondition(AutomationRuleEvent $event, mixed $condition, int $index): void
    {
        $path = "conditions.{$index}";
        self::assertObject($condition, ['field', 'operator', 'value'], $path);

        $field = AutomationRuleConditionField::tryFrom(self::stringValue($condition['field'], "{$path}.field"));

        if (! $field instanceof AutomationRuleConditionField) {
            throw new InvalidArgumentException("{$path}.field is not supported.");
        }

        if (! $field->supports($event)) {
            throw new InvalidArgumentException("{$path}.field is not available for {$event->value} rules.");
        }

        $operator = AutomationRuleConditionOperator::tryFrom(self::stringValue($condition['operator'], "{$path}.operator"));

        if (! $operator instanceof AutomationRuleConditionOperator) {
            throw new InvalidArgumentException("{$path}.operator is not supported.");
        }

        if ($operator->isTextOperator() && ! $field->supportsTextOperators()) {
            throw new InvalidArgumentException("{$path}.operator is not available for {$field->value}.");
        }

        self::assertConditionValue($event, $field, $operator, $condition['value'], "{$path}.value");
    }

    private static function assertConditionValue(
        AutomationRuleEvent $event,
        AutomationRuleConditionField $field,
        AutomationRuleConditionOperator $operator,
        mixed $value,
        string $path,
    ): void {
        if ($field === AutomationRuleConditionField::SiteId || $field === AutomationRuleConditionField::AssigneeId) {
            if ($value !== null && (! is_int($value) || $value < 1)) {
                throw new InvalidArgumentException("{$path} must be a positive integer or null.");
            }

            return;
        }

        if ($field === AutomationRuleConditionField::Priority) {
            if (! is_string($value) || TicketPriority::tryFrom($value) === null) {
                throw new InvalidArgumentException("{$path} must be a supported ticket priority.");
            }

            return;
        }

        if ($field === AutomationRuleConditionField::Status) {
            $valid = is_string($value) && ($event->isTicketEvent()
                ? TicketStatus::tryFrom($value) !== null
                : ConversationStatus::tryFrom($value) !== null);

            if (! $valid) {
                throw new InvalidArgumentException("{$path} must be a status supported by {$event->value}.");
            }

            return;
        }

        if ($field === AutomationRuleConditionField::Category) {
            if ($value !== null && (! is_string($value) || ! in_array($value, TicketCategory::values(), true))) {
                throw new InvalidArgumentException("{$path} must be a supported ticket category or null.");
            }

            return;
        }

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("{$path} must be a string or null.");
        }

        if (is_string($value) && mb_strlen($value) > 4000) {
            throw new InvalidArgumentException("{$path} may not be longer than 4000 characters.");
        }

        if ($operator->isTextOperator() && (! is_string($value) || trim($value) === '')) {
            throw new InvalidArgumentException("{$path} must be a non-empty string for {$operator->value}.");
        }
    }

    private static function assertAction(AutomationRuleEvent $event, mixed $action, int $index): void
    {
        $path = "actions.{$index}";
        self::assertObject($action, ['type', 'value'], $path);

        $type = AutomationRuleActionType::tryFrom(self::stringValue($action['type'], "{$path}.type"));

        if (! $type instanceof AutomationRuleActionType) {
            throw new InvalidArgumentException("{$path}.type is not supported.");
        }

        if (! $type->supports($event)) {
            throw new InvalidArgumentException("{$path}.type is not available for {$event->value} rules.");
        }

        $value = $action['value'];

        match ($type) {
            AutomationRuleActionType::AssignAgent,
            AutomationRuleActionType::NotifyAgent,
            AutomationRuleActionType::AddLabel => self::assertPositiveId($value, "{$path}.value"),
            AutomationRuleActionType::SetPriority => self::assertPriority($value, "{$path}.value"),
            AutomationRuleActionType::SetStatus => self::assertStatus($event, $value, "{$path}.value"),
            AutomationRuleActionType::PostInternalNote => self::assertInternalNote($value, "{$path}.value"),
        };
    }

    /** @param list<string> $requiredKeys */
    private static function assertObject(mixed $value, array $requiredKeys, string $path): void
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException("{$path} must be an object.");
        }

        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($requiredKeys);

        if ($actualKeys !== $requiredKeys) {
            throw new InvalidArgumentException("{$path} must contain exactly: ".implode(', ', $requiredKeys).'.');
        }
    }

    private static function stringValue(mixed $value, string $path): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$path} must be a string.");
        }

        return $value;
    }

    private static function assertPositiveId(mixed $value, string $path): void
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("{$path} must be a positive integer.");
        }
    }

    private static function assertPriority(mixed $value, string $path): void
    {
        if (! is_string($value) || TicketPriority::tryFrom($value) === null) {
            throw new InvalidArgumentException("{$path} must be a supported ticket priority.");
        }
    }

    private static function assertStatus(AutomationRuleEvent $event, mixed $value, string $path): void
    {
        $valid = is_string($value) && ($event->isTicketEvent()
            ? TicketStatus::tryFrom($value) !== null
            : ConversationStatus::tryFrom($value) !== null);

        if (! $valid) {
            throw new InvalidArgumentException("{$path} must be a status supported by {$event->value}.");
        }
    }

    private static function assertInternalNote(mixed $value, string $path): void
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$path} must be a non-empty string.");
        }

        if (mb_strlen($value) > 4000) {
            throw new InvalidArgumentException("{$path} may not be longer than 4000 characters.");
        }
    }
}
