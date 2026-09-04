<?php

namespace App\Support\Automation;

use App\Enums\AccountPermission;
use App\Enums\AutomationRuleActionType;
use App\Enums\AutomationRuleConditionField;
use App\Enums\AutomationRuleConditionOperator;
use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\TicketLabel;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AutomationRuleForm
{
    public const MAX_CONDITIONS = 10;

    public const MAX_ACTIONS = 10;

    /**
     * @return array{
     *     name: string,
     *     event: string,
     *     conditions: list<array{field: string, operator: string, value: mixed}>,
     *     actions: list<array{type: string, value: mixed}>,
     *     position: int,
     *     is_enabled: bool
     * }
     */
    public function validated(Request $request, Account $account): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event' => ['required', 'string', Rule::enum(AutomationRuleEvent::class)],
            'position' => ['required', 'integer', 'min:0', 'max:10000'],
            'is_enabled' => ['required', 'boolean'],
            'conditions' => ['sometimes', 'array', 'max:'.self::MAX_CONDITIONS],
            'conditions.*' => ['required', 'array'],
            'conditions.*.field' => ['required', 'string', Rule::enum(AutomationRuleConditionField::class)],
            'conditions.*.operator' => ['required', 'string', Rule::enum(AutomationRuleConditionOperator::class)],
            'conditions.*.text_value' => ['nullable', 'string', 'max:4000'],
            'conditions.*.select_value' => ['nullable', 'string', 'max:120'],
            'actions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ACTIONS],
            'actions.*' => ['required', 'array'],
            'actions.*.type' => ['required', 'string', Rule::enum(AutomationRuleActionType::class)],
            'actions.*.text_value' => ['nullable', 'string', 'max:4000'],
            'actions.*.select_value' => ['nullable', 'string', 'max:120'],
        ]);

        $name = Str::of($validated['name'])->squish()->toString();

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('automation_rules.fields.name')]),
            ]);
        }

        $event = AutomationRuleEvent::from($validated['event']);
        $conditions = collect($validated['conditions'] ?? [])
            ->values()
            ->map(fn (array $condition, int $index): array => $this->condition($condition, $event, $index))
            ->all();
        $actions = collect($validated['actions'])
            ->values()
            ->map(fn (array $action, int $index): array => $this->action($action, $event, $index))
            ->all();

        try {
            AutomationRuleDefinition::assertValid($event, $conditions, $actions);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'definition' => __('automation_rules.validation.definition', ['detail' => $exception->getMessage()]),
            ]);
        }

        $this->assertReferencesBelongToAccount(
            $account,
            $event,
            $conditions,
            $actions,
            (bool) $validated['is_enabled'],
        );

        return [
            'name' => $name,
            'event' => $event->value,
            'conditions' => $conditions,
            'actions' => $actions,
            'position' => (int) $validated['position'],
            'is_enabled' => (bool) $validated['is_enabled'],
        ];
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     * @return list<array{field: string, operator: string, text_value: string, select_value: string}>
     */
    public function conditionRows(array $conditions): array
    {
        return array_map(function (array $condition): array {
            $field = AutomationRuleConditionField::from($condition['field']);

            return [
                'field' => $field->value,
                'operator' => $condition['operator'],
                'text_value' => $this->usesTextValue($field) ? (string) ($condition['value'] ?? '') : '',
                'select_value' => $this->usesTextValue($field)
                    ? ''
                    : $this->conditionSelection($field, $condition['value'] ?? null),
            ];
        }, $conditions);
    }

    /**
     * @param  list<array{type: string, value: mixed}>  $actions
     * @return list<array{type: string, text_value: string, select_value: string}>
     */
    public function actionRows(array $actions): array
    {
        return array_map(function (array $action): array {
            $type = AutomationRuleActionType::from($action['type']);

            return [
                'type' => $type->value,
                'text_value' => $type === AutomationRuleActionType::PostInternalNote
                    ? (string) $action['value']
                    : '',
                'select_value' => $type === AutomationRuleActionType::PostInternalNote
                    ? ''
                    : $this->actionSelection($type, $action['value']),
            ];
        }, $actions);
    }

    /** @return array{field: string, operator: string, text_value: string, select_value: string} */
    public function blankCondition(): array
    {
        return [
            'field' => AutomationRuleConditionField::Subject->value,
            'operator' => AutomationRuleConditionOperator::Contains->value,
            'text_value' => '',
            'select_value' => '',
        ];
    }

    /** @return array{type: string, text_value: string, select_value: string} */
    public function blankAction(): array
    {
        return [
            'type' => AutomationRuleActionType::SetPriority->value,
            'text_value' => '',
            'select_value' => 'priority:normal',
        ];
    }

    /** @param array<string, mixed> $condition */
    private function condition(array $condition, AutomationRuleEvent $event, int $index): array
    {
        $field = AutomationRuleConditionField::from($condition['field']);
        $operator = AutomationRuleConditionOperator::from($condition['operator']);
        $path = "conditions.{$index}";

        $value = match ($field) {
            AutomationRuleConditionField::Subject,
            AutomationRuleConditionField::Description,
            AutomationRuleConditionField::MessageBody => trim((string) ($condition['text_value'] ?? '')),
            AutomationRuleConditionField::SiteId => $this->choiceId($condition, 'site', $path),
            AutomationRuleConditionField::AssigneeId => $this->choiceId($condition, 'agent', $path, true),
            AutomationRuleConditionField::Priority => $this->choiceValue($condition, 'priority', $path),
            AutomationRuleConditionField::Category => $this->choiceValue($condition, 'category', $path, true),
            AutomationRuleConditionField::Status => $this->choiceValue($condition, 'status', $path),
        };

        return [
            'field' => $field->value,
            'operator' => $operator->value,
            'value' => $value,
        ];
    }

    /** @param array<string, mixed> $action */
    private function action(array $action, AutomationRuleEvent $event, int $index): array
    {
        $type = AutomationRuleActionType::from($action['type']);
        $path = "actions.{$index}";

        $value = match ($type) {
            AutomationRuleActionType::AssignAgent,
            AutomationRuleActionType::NotifyAgent => $this->choiceId($action, 'agent', $path),
            AutomationRuleActionType::AddLabel => $this->choiceId($action, 'label', $path),
            AutomationRuleActionType::SetPriority => $this->choiceValue($action, 'priority', $path),
            AutomationRuleActionType::SetStatus => $this->choiceValue($action, 'status', $path),
            AutomationRuleActionType::PostInternalNote => trim((string) ($action['text_value'] ?? '')),
        };

        return [
            'type' => $type->value,
            'value' => $value,
        ];
    }

    /** @param array<string, mixed> $row */
    private function choiceId(array $row, string $prefix, string $path, bool $nullable = false): ?int
    {
        $value = $this->choiceValue($row, $prefix, $path, $nullable);

        if ($value === null) {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            $this->invalidChoice($path);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $row */
    private function choiceValue(array $row, string $prefix, string $path, bool $nullable = false): ?string
    {
        $selection = (string) ($row['select_value'] ?? '');

        if ($nullable && $selection === $prefix.':none') {
            return null;
        }

        $expected = $prefix.':';

        if (! str_starts_with($selection, $expected) || strlen($selection) === strlen($expected)) {
            $this->invalidChoice($path);
        }

        return substr($selection, strlen($expected));
    }

    private function invalidChoice(string $path): never
    {
        throw ValidationException::withMessages([
            $path.'.select_value' => __('automation_rules.validation.choice'),
        ]);
    }

    /**
     * @param  list<array{field: string, operator: string, value: mixed}>  $conditions
     * @param  list<array{type: string, value: mixed}>  $actions
     */
    private function assertReferencesBelongToAccount(
        Account $account,
        AutomationRuleEvent $event,
        array $conditions,
        array $actions,
        bool $isEnabled,
    ): void {
        foreach ($conditions as $index => $condition) {
            $field = AutomationRuleConditionField::from($condition['field']);
            $id = $condition['value'];

            if ($field === AutomationRuleConditionField::SiteId
                && ! $account->sites()->whereKey($id)->exists()) {
                $this->invalidReference("conditions.{$index}.select_value");
            }

            if ($field === AutomationRuleConditionField::AssigneeId
                && $id !== null
                && ! $account->agents()->whereKey($id)->exists()) {
                $this->invalidReference("conditions.{$index}.select_value");
            }
        }

        foreach ($actions as $index => $action) {
            $type = AutomationRuleActionType::from($action['type']);
            $id = $action['value'];

            if ($type === AutomationRuleActionType::AddLabel
                && ! TicketLabel::query()->whereKey($id)->where('account_id', $account->id)->exists()) {
                $this->invalidReference("actions.{$index}.select_value");
            }

            if (in_array($type, [AutomationRuleActionType::AssignAgent, AutomationRuleActionType::NotifyAgent], true)) {
                $agent = User::query()
                    ->with('customRole')
                    ->whereKey($id)
                    ->where('account_id', $account->id)
                    ->first();
                $workPermission = $event->isTicketEvent()
                    ? AccountPermission::ManageTickets
                    : AccountPermission::ViewConversations;
                $canAct = $agent instanceof User
                    && (! $isEnabled
                        || ($agent->hasAccountPermission($workPermission)
                            && ($type !== AutomationRuleActionType::NotifyAgent
                                || $agent->hasAccountPermission(AccountPermission::ViewAlerts))
                            && $this->agentCoversMatchingSites($account, $agent, $conditions)));

                if (! $canAct) {
                    $this->invalidReference("actions.{$index}.select_value");
                }
            }
        }
    }

    /** @param list<array{field: string, operator: string, value: mixed}> $conditions */
    private function agentCoversMatchingSites(Account $account, User $agent, array $conditions): bool
    {
        $siteConditions = collect($conditions)
            ->filter(fn (array $condition): bool => $condition['field'] === AutomationRuleConditionField::SiteId->value);
        $includedSiteIds = $siteConditions
            ->where('operator', AutomationRuleConditionOperator::Equals->value)
            ->pluck('value')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $excludedSiteIds = $siteConditions
            ->where('operator', AutomationRuleConditionOperator::NotEquals->value)
            ->pluck('value')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($includedSiteIds->count() > 1
            || ($includedSiteIds->isNotEmpty() && $excludedSiteIds->contains($includedSiteIds->first()))) {
            return true;
        }

        $possibleSites = $account->sites();

        if ($includedSiteIds->isNotEmpty()) {
            $possibleSites->where('sites.id', $includedSiteIds->first());
        } elseif ($excludedSiteIds->isNotEmpty()) {
            $possibleSites->whereNotIn('sites.id', $excludedSiteIds->all());
        }

        $visibleSiteIds = $account->sites()
            ->visibleToAgentIncludingArchived($agent)
            ->select('sites.id');

        return ! $possibleSites->whereNotIn('sites.id', $visibleSiteIds)->exists();
    }

    private function invalidReference(string $path): never
    {
        throw ValidationException::withMessages([
            $path => __('automation_rules.validation.reference'),
        ]);
    }

    private function conditionSelection(AutomationRuleConditionField $field, mixed $value): string
    {
        return match ($field) {
            AutomationRuleConditionField::SiteId => 'site:'.$value,
            AutomationRuleConditionField::AssigneeId => $value === null ? 'agent:none' : 'agent:'.$value,
            AutomationRuleConditionField::Priority => 'priority:'.$value,
            AutomationRuleConditionField::Category => $value === null ? 'category:none' : 'category:'.$value,
            AutomationRuleConditionField::Status => 'status:'.$value,
            default => '',
        };
    }

    private function usesTextValue(AutomationRuleConditionField $field): bool
    {
        return in_array($field, [
            AutomationRuleConditionField::Subject,
            AutomationRuleConditionField::Description,
            AutomationRuleConditionField::MessageBody,
        ], true);
    }

    private function actionSelection(AutomationRuleActionType $type, mixed $value): string
    {
        return match ($type) {
            AutomationRuleActionType::AssignAgent,
            AutomationRuleActionType::NotifyAgent => 'agent:'.$value,
            AutomationRuleActionType::AddLabel => 'label:'.$value,
            AutomationRuleActionType::SetPriority => 'priority:'.$value,
            AutomationRuleActionType::SetStatus => 'status:'.$value,
            AutomationRuleActionType::PostInternalNote => '',
        };
    }
}
