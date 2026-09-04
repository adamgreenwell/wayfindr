@php
    $typeValue = (string) ($row['type'] ?? 'set_priority');
    $textValue = (string) ($row['text_value'] ?? '');
    $selectValue = (string) ($row['select_value'] ?? '');
@endphp

<div class="automation-builder-row" data-rule-row>
    <div class="field">
        <label for="action-{{ $index }}-type">{{ __('automation_rules.fields.action_type') }}</label>
        <select id="action-{{ $index }}-type" name="actions[{{ $index }}][type]" data-action-type required>
            @foreach (\App\Enums\AutomationRuleActionType::cases() as $type)
                @php
                    $supportedEvents = collect(\App\Enums\AutomationRuleEvent::cases())
                        ->filter(fn ($event) => $type->supports($event))
                        ->pluck('value')
                        ->implode(',');
                @endphp
                <option value="{{ $type->value }}" data-events="{{ $supportedEvents }}" @selected($typeValue === $type->value)>{{ __('automation_rules.actions.'.$type->value) }}</option>
            @endforeach
        </select>
    </div>
    <div class="field automation-text-value" data-text-value>
        <label for="action-{{ $index }}-text">{{ __('automation_rules.fields.internal_note') }}</label>
        <textarea id="action-{{ $index }}-text" name="actions[{{ $index }}][text_value]" rows="3" maxlength="4000" lang="">{{ $textValue }}</textarea>
    </div>
    <div class="field automation-choice-value" data-choice-value>
        <label for="action-{{ $index }}-choice">{{ __('automation_rules.fields.value') }}</label>
        <select id="action-{{ $index }}-choice" name="actions[{{ $index }}][select_value]">
            <option value="">{{ __('automation_rules.values.choose') }}</option>
            <optgroup label="{{ __('automation_rules.value_groups.agents') }}" data-choice-group="agent">
                @foreach ($agents as $optionAgent)
                    <option value="agent:{{ $optionAgent->id }}" @selected($selectValue === 'agent:'.$optionAgent->id)>{{ $optionAgent->name }}{{ $optionAgent->isDeactivated() ? ' — '.__('automation_rules.values.deactivated') : '' }}</option>
                @endforeach
            </optgroup>
            <optgroup label="{{ __('automation_rules.value_groups.labels') }}" data-choice-group="label">
                @foreach ($labels as $label)
                    <option value="label:{{ $label->id }}" @selected($selectValue === 'label:'.$label->id)>{{ $label->name }}</option>
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
        </select>
    </div>
    <div class="automation-row-actions">
        <button class="button secondary" type="button" data-move-row="up">{{ __('automation_rules.builder.move_up') }}</button>
        <button class="button secondary" type="button" data-move-row="down">{{ __('automation_rules.builder.move_down') }}</button>
        <button class="button secondary automation-remove-row" type="button" data-remove-row>{{ __('automation_rules.builder.remove_action') }}</button>
    </div>
</div>
