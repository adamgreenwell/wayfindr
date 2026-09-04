<?php

namespace App\Support\Automation;

use App\Enums\AutomationMacroSubjectType;
use App\Enums\AutomationRuleActionType;
use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class AutomationMacroForm
{
    public const MAX_ACTIONS = AutomationRuleForm::MAX_ACTIONS;

    public const MAX_POSITION = AutomationRuleForm::MAX_POSITION;

    public function __construct(private AutomationRuleForm $ruleForm) {}

    /**
     * @return array{
     *     name: string,
     *     subject_type: string,
     *     actions: list<array{type: string, value: mixed}>,
     *     position: int,
     *     is_enabled: bool
     * }
     */
    public function validated(Request $request, Account $account): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject_type' => ['required', 'string', Rule::enum(AutomationMacroSubjectType::class)],
            'position' => ['required', 'integer', 'min:0', 'max:'.self::MAX_POSITION],
            'is_enabled' => ['required', 'boolean'],
            'actions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ACTIONS],
            'actions.*' => ['required', 'array'],
            'actions.*.type' => ['required', 'string', Rule::enum(AutomationRuleActionType::class)],
            'actions.*.text_value' => ['nullable', 'string', 'max:4000'],
            'actions.*.select_value' => ['nullable', 'string', 'max:120'],
        ]);

        $name = Str::of($validated['name'])->squish()->toString();

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('automation_macros.fields.name')]),
            ]);
        }

        $subjectType = AutomationMacroSubjectType::from($validated['subject_type']);
        $actions = $this->ruleForm->normalizeActions($validated['actions']);

        try {
            AutomationRuleDefinition::assertActionsForSubjectType($subjectType, $actions);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'definition' => __('automation_macros.validation.definition', ['detail' => $exception->getMessage()]),
            ]);
        }

        $this->ruleForm->assertActionReferences(
            $account,
            $subjectType->event(),
            $actions,
            (bool) $validated['is_enabled'],
        );

        return [
            'name' => $name,
            'subject_type' => $subjectType->value,
            'actions' => $actions,
            'position' => (int) $validated['position'],
            'is_enabled' => (bool) $validated['is_enabled'],
        ];
    }

    /**
     * @param  list<array{type: string, value: mixed}>  $actions
     * @return list<array{type: string, text_value: string, select_value: string}>
     */
    public function actionRows(array $actions): array
    {
        return $this->ruleForm->actionRows($actions);
    }

    /** @return array{type: string, text_value: string, select_value: string} */
    public function blankAction(): array
    {
        return $this->ruleForm->blankAction();
    }
}
