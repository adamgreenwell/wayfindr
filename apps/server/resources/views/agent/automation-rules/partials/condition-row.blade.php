@php
    $fieldValue = (string) ($row['field'] ?? 'subject');
    $operatorValue = (string) ($row['operator'] ?? 'contains');
    $textValue = (string) ($row['text_value'] ?? '');
    $selectValue = (string) ($row['select_value'] ?? '');
@endphp

<div class="automation-builder-row" data-rule-row>
    <div class="field">
        <label for="condition-{{ $index }}-field">{{ __('automation_rules.fields.condition_field') }}</label>
        <select id="condition-{{ $index }}-field" name="conditions[{{ $index }}][field]" data-condition-field required>
            @foreach (\App\Enums\AutomationRuleConditionField::cases() as $field)
                @php
                    $supportedEvents = collect(\App\Enums\AutomationRuleEvent::cases())
                        ->filter(fn ($event) => $field->supports($event))
                        ->pluck('value')
                        ->implode(',');
                @endphp
                <option value="{{ $field->value }}" data-events="{{ $supportedEvents }}" @selected($fieldValue === $field->value)>{{ __('automation_rules.condition_fields.'.$field->value) }}</option>
            @endforeach
        </select>
    </div>
    <div class="field">
        <label for="condition-{{ $index }}-operator">{{ __('automation_rules.fields.comparison') }}</label>
        <select id="condition-{{ $index }}-operator" name="conditions[{{ $index }}][operator]" data-condition-operator required>
            @foreach (\App\Enums\AutomationRuleConditionOperator::cases() as $operator)
                <option value="{{ $operator->value }}" data-text-operator="{{ $operator->isTextOperator() ? 'true' : 'false' }}" @selected($operatorValue === $operator->value)>{{ __('automation_rules.operators.'.$operator->value) }}</option>
            @endforeach
        </select>
    </div>
    <div class="field automation-text-value" data-text-value>
        <label for="condition-{{ $index }}-text">{{ __('automation_rules.fields.value') }}</label>
        <input id="condition-{{ $index }}-text" name="conditions[{{ $index }}][text_value]" value="{{ $textValue }}" maxlength="4000" lang="">
    </div>
    <div class="field automation-choice-value" data-choice-value>
        <label for="condition-{{ $index }}-choice">{{ __('automation_rules.fields.value') }}</label>
        <select id="condition-{{ $index }}-choice" name="conditions[{{ $index }}][select_value]">
            <option value="">{{ __('automation_rules.values.choose') }}</option>
            <optgroup label="{{ __('automation_rules.value_groups.sites') }}" data-choice-group="site">
                @foreach ($sites as $site)
                    <option value="site:{{ $site->id }}" @selected($selectValue === 'site:'.$site->id)>{{ $site->name }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('automation_rules.value_groups.agents') }}" data-choice-group="agent">
                <option value="agent:none" @selected($selectValue === 'agent:none')>{{ __('automation_rules.values.unassigned') }}</option>
                @foreach ($agents as $optionAgent)
                    <option value="agent:{{ $optionAgent->id }}" @selected($selectValue === 'agent:'.$optionAgent->id)>{{ $optionAgent->name }}{{ $optionAgent->isDeactivated() ? ' — '.__('automation_rules.values.deactivated') : '' }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('automation_rules.value_groups.priorities') }}" data-choice-group="priority">
                @foreach (\App\Enums\TicketPriority::cases() as $priority)
                    <option value="priority:{{ $priority->value }}" @selected($selectValue === 'priority:'.$priority->value)>{{ __('tickets.priorities.'.$priority->value) }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('automation_rules.value_groups.statuses') }}" data-choice-group="status">
                @foreach (\App\Enums\TicketStatus::cases() as $status)
                    <option value="status:{{ $status->value }}" data-ticket-only="{{ $status === \App\Enums\TicketStatus::Pending ? 'true' : 'false' }}" @selected($selectValue === 'status:'.$status->value)>{{ __('tickets.statuses.'.$status->value) }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('automation_rules.value_groups.categories') }}" data-choice-group="category">
                <option value="category:none" @selected($selectValue === 'category:none')>{{ __('automation_rules.values.uncategorized') }}</option>
                @foreach (\App\Support\TicketCategory::values() as $category)
                    <option value="category:{{ $category }}" @selected($selectValue === 'category:'.$category)>{{ __('tickets.categories.'.$category) }}</option>
                @endforeach
            </optgroup>
        </select>
    </div>
    <div class="automation-row-actions">
        <button class="button secondary" type="button" data-move-row="up">{{ __('automation_rules.builder.move_up') }}</button>
        <button class="button secondary" type="button" data-move-row="down">{{ __('automation_rules.builder.move_down') }}</button>
        <button class="button secondary automation-remove-row" type="button" data-remove-row>{{ __('automation_rules.builder.remove_condition') }}</button>
    </div>
</div>
