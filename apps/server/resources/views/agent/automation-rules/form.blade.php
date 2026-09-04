@php
    $presenter = app(\App\Support\Automation\AutomationRulePresenter::class);
    $selectedEvent = old('event', $automationRule?->event ?? \App\Enums\AutomationRuleEvent::TicketCreated->value);
    $conditionRows = old('conditions', $conditionRows);
    $actionRows = old('actions', $actionRows);
    $isEditing = $automationRule !== null;
@endphp

<x-layouts.app :title="$isEditing ? __('automation_rules.edit.title') : __('automation_rules.create.title')" :agent="$agent" :account="$account">
    <x-page-header
        :title="$isEditing ? __('automation_rules.edit.title_named', ['name' => $automationRule->name]) : __('automation_rules.create.title')"
        :subtitle="$isEditing ? __('automation_rules.edit.subtitle') : __('automation_rules.create.subtitle')"
        :back-href="route('dashboard.account.automation-rules.index')"
        :back-label="__('automation_rules.edit.back')"
    />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    @if ($errors->any())
        <section class="section automation-validation" aria-labelledby="automation-validation-heading">
            <div class="section-header">
                <h2 id="automation-validation-heading">{{ __('automation_rules.validation.heading') }}</h2>
            </div>
            <ul>
                @foreach ($errors->all() as $error)
                    <li class="field-error">{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="section" aria-labelledby="automation-definition-heading">
        <div class="section-header">
            <div>
                <h2 id="automation-definition-heading">{{ __('automation_rules.builder.heading') }}</h2>
                <p class="lede">{{ __('automation_rules.builder.lede') }}</p>
            </div>
            <span class="readiness-status" data-status="{{ old('is_enabled', $automationRule?->is_enabled ?? false) ? 'ready' : 'manual' }}">
                {{ old('is_enabled', $automationRule?->is_enabled ?? false) ? __('automation_rules.status.enabled') : __('automation_rules.status.draft') }}
            </span>
        </div>

        <form
            id="automation-rule-form"
            class="section-form"
            method="POST"
            action="{{ $isEditing ? route('dashboard.account.automation-rules.update', $automationRule) : route('dashboard.account.automation-rules.store') }}"
            data-automation-rule-form
            data-max-conditions="{{ \App\Support\Automation\AutomationRuleForm::MAX_CONDITIONS }}"
            data-max-actions="{{ \App\Support\Automation\AutomationRuleForm::MAX_ACTIONS }}"
        >
            @csrf
            @if ($isEditing) @method('PUT') @endif

            <div class="automation-rule-basics">
                <div class="field">
                    <label for="automation-name">{{ __('automation_rules.fields.name') }}</label>
                    <input id="automation-name" name="name" value="{{ old('name', $automationRule?->name) }}" maxlength="120" lang="" required>
                    <span class="field-help">{{ __('automation_rules.fields.name_help') }}</span>
                </div>
                <div class="field">
                    <label for="automation-event">{{ __('automation_rules.fields.event') }}</label>
                    <select id="automation-event" name="event" data-rule-event required>
                        @foreach ($events as $event)
                            <option value="{{ $event->value }}" @selected($selectedEvent === $event->value)>{{ $presenter->event($event) }}</option>
                        @endforeach
                    </select>
                    <span class="field-help">{{ __('automation_rules.fields.event_help') }}</span>
                </div>
                <div class="field">
                    <label for="automation-position">{{ __('automation_rules.fields.position') }}</label>
                    <input id="automation-position" name="position" type="number" min="0" max="10000" step="1" value="{{ old('position', $defaultPosition) }}" required>
                    <span class="field-help">{{ __('automation_rules.fields.position_help') }}</span>
                </div>
            </div>

            <fieldset class="automation-builder" data-rule-rows="conditions">
                <legend>{{ __('automation_rules.builder.conditions') }}</legend>
                <p class="lede">{{ __('automation_rules.builder.conditions_help') }}</p>
                <div data-rule-row-list>
                    @foreach ($conditionRows as $index => $row)
                        @include('agent.automation-rules.partials.condition-row', ['index' => $index, 'row' => $row])
                    @endforeach
                </div>
                <button class="button secondary" type="button" data-add-row="condition">{{ __('automation_rules.builder.add_condition') }}</button>
            </fieldset>

            <fieldset class="automation-builder" data-rule-rows="actions">
                <legend>{{ __('automation_rules.builder.actions') }}</legend>
                <p class="lede">{{ __('automation_rules.builder.actions_help') }}</p>
                <div data-rule-row-list>
                    @foreach ($actionRows as $index => $row)
                        @include('agent.automation-rules.partials.action-row', ['index' => $index, 'row' => $row])
                    @endforeach
                </div>
                <button class="button secondary" type="button" data-add-row="action">{{ __('automation_rules.builder.add_action') }}</button>
            </fieldset>

            <input name="is_enabled" type="hidden" value="0">
            <label class="check-row automation-enabled" for="automation-enabled">
                <input id="automation-enabled" name="is_enabled" type="checkbox" value="1" @checked((bool) old('is_enabled', $automationRule?->is_enabled ?? false))>
                <span>
                    <strong>{{ __('automation_rules.fields.enabled') }}</strong>
                    <span class="lede">{{ __('automation_rules.fields.enabled_help') }}</span>
                </span>
            </label>

            <button class="button" type="submit">{{ $isEditing ? __('automation_rules.edit.save') : __('automation_rules.create.submit') }}</button>
        </form>
    </section>

    @if ($isEditing)
        <section class="section" aria-labelledby="automation-preview-heading">
            <div class="section-header">
                <div>
                    <h2 id="automation-preview-heading">{{ __('automation_rules.preview.heading') }}</h2>
                    <p class="lede">{{ __('automation_rules.preview.lede') }}</p>
                </div>
                <span class="readiness-status" data-status="manual">{{ __('automation_rules.preview.no_changes') }}</span>
            </div>

            @if ($previewOptions->isEmpty())
                <p class="empty">{{ __('automation_rules.preview.no_subjects') }}</p>
            @else
                <form class="section-form" method="POST" action="{{ route('dashboard.account.automation-rules.preview', $automationRule) }}">
                    @csrf
                    <div class="field">
                        <label for="preview-subject">{{ __('automation_rules.preview.subject') }}</label>
                        <select id="preview-subject" name="preview_subject" required>
                            <option value="">{{ __('automation_rules.preview.choose') }}</option>
                            @foreach ($previewOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="button secondary" type="submit">{{ __('automation_rules.preview.run') }}</button>
                </form>
            @endif

            @if ($preview)
                <div class="automation-preview-result" aria-live="polite">
                    <div class="section-header">
                        <div>
                            <h3>{{ __('automation_rules.preview.result') }}</h3>
                            <p class="lede" lang="">{{ $preview['subject_label'] }}</p>
                        </div>
                        <span class="readiness-status" data-status="{{ $preview['matched'] ? 'ready' : 'manual' }}">
                            {{ $preview['matched'] ? __('automation_rules.preview.matched') : __('automation_rules.preview.not_matched') }}
                        </span>
                    </div>
                    @if ($preview['conditions'] === [])
                        <p>{{ __('automation_rules.values.every_event') }}</p>
                    @else
                        <ol class="automation-definition-list">
                            @foreach ($preview['conditions'] as $condition)
                                <li>
                                    <strong>{{ $condition['matched'] ? __('automation_rules.preview.condition_matched') : __('automation_rules.preview.condition_not_matched') }}</strong>
                                    {{ $presenter->condition($condition, $preview['event'], $referenceLabels, 'expected') }}
                                    <span class="table-note">{{ __('automation_rules.preview.actual', ['value' => $presenter->conditionValueOnly($condition, $preview['event'], $referenceLabels, 'actual')]) }}</span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($preview['matched'])
                        <strong>{{ __('automation_rules.preview.would_run') }}</strong>
                        <ol class="automation-definition-list">
                            @foreach ($preview['actions'] as $action)
                                <li>{{ $presenter->action($action, $preview['event'], $referenceLabels ?? []) }}</li>
                            @endforeach
                        </ol>
                    @else
                        <p class="lede">{{ __('automation_rules.preview.no_actions') }}</p>
                    @endif
                </div>
            @endif
        </section>

        <section class="section" aria-labelledby="automation-delete-heading">
            <div class="section-header">
                <div>
                    <h2 id="automation-delete-heading">{{ __('automation_rules.delete.heading') }}</h2>
                    <p class="lede">{{ __('automation_rules.delete.lede') }}</p>
                </div>
            </div>
            <form class="section-form" method="POST" action="{{ route('dashboard.account.automation-rules.destroy', $automationRule) }}">
                @csrf
                @method('DELETE')
                <button class="button danger" type="submit">{{ __('automation_rules.delete.action') }}</button>
            </form>
        </section>
    @endif

    <template data-condition-row-template>
        @include('agent.automation-rules.partials.condition-row', ['index' => '__INDEX__', 'row' => $form->blankCondition()])
    </template>
    <template data-action-row-template>
        @include('agent.automation-rules.partials.action-row', ['index' => '__INDEX__', 'row' => $form->blankAction()])
    </template>

    <script>
        (() => {
            const form = document.querySelector('[data-automation-rule-form]');
            if (!form) return;

            const eventSelect = form.querySelector('[data-rule-event]');
            const conditionList = form.querySelector('[data-rule-rows="conditions"] [data-rule-row-list]');
            const actionList = form.querySelector('[data-rule-rows="actions"] [data-rule-row-list]');
            const conditionTemplate = document.querySelector('[data-condition-row-template]');
            const actionTemplate = document.querySelector('[data-action-row-template]');
            let nextRowIndex = 1000;

            const textFields = ['subject', 'description', 'message_body'];
            const textOperatorFields = ['subject', 'description', 'category', 'message_body'];
            const conditionGroups = {
                site_id: 'site',
                assignee_id: 'agent',
                priority: 'priority',
                status: 'status',
                category: 'category',
            };
            const actionGroups = {
                assign_agent: 'agent',
                add_label: 'label',
                set_priority: 'priority',
                set_status: 'status',
                notify_agent: 'agent',
            };

            const supportsEvent = (option, event) => (option.dataset.events || '').split(',').includes(event);

            function syncTypeSelect(select) {
                const event = eventSelect.value;
                Array.from(select.options).forEach((option) => {
                    const supported = supportsEvent(option, event);
                    option.hidden = !supported;
                    option.disabled = !supported;
                });
                if (select.selectedOptions[0]?.disabled) {
                    select.value = Array.from(select.options).find((option) => !option.disabled)?.value || '';
                }
            }

            function syncChoice(row, group, showText) {
                const textWrap = row.querySelector('[data-text-value]');
                const choiceWrap = row.querySelector('[data-choice-value]');
                const textControl = textWrap?.querySelector('input, textarea');
                const choiceControl = choiceWrap?.querySelector('select');

                if (textWrap) textWrap.hidden = !showText;
                if (choiceWrap) choiceWrap.hidden = showText;
                if (textControl) {
                    textControl.disabled = !showText;
                    textControl.required = showText;
                }
                if (!choiceControl) return;

                choiceControl.disabled = showText;
                choiceControl.required = !showText;
                Array.from(choiceControl.querySelectorAll('optgroup')).forEach((optgroup) => {
                    const visible = !showText && optgroup.dataset.choiceGroup === group;
                    optgroup.hidden = !visible;
                    optgroup.disabled = !visible;
                });
                Array.from(choiceControl.querySelectorAll('[data-ticket-only="true"]')).forEach((option) => {
                    const ticketEvent = eventSelect.value.startsWith('ticket.');
                    option.hidden = !ticketEvent;
                    option.disabled = !ticketEvent;
                });
                if (!showText) {
                    const selected = choiceControl.selectedOptions[0];
                    const selectedGroup = selected?.closest('optgroup')?.dataset.choiceGroup;
                    if (selected?.value && (selected.disabled || selectedGroup !== group)) choiceControl.value = '';
                }
            }

            function syncCondition(row) {
                const field = row.querySelector('[data-condition-field]');
                const operator = row.querySelector('[data-condition-operator]');
                syncTypeSelect(field);
                Array.from(operator.options).forEach((option) => {
                    const supported = option.dataset.textOperator !== 'true' || textOperatorFields.includes(field.value);
                    option.hidden = !supported;
                    option.disabled = !supported;
                });
                if (operator.selectedOptions[0]?.disabled) operator.value = 'equals';
                syncChoice(row, conditionGroups[field.value], textFields.includes(field.value));
            }

            function syncAction(row) {
                const type = row.querySelector('[data-action-type]');
                syncTypeSelect(type);
                syncChoice(row, actionGroups[type.value], type.value === 'post_internal_note');
            }

            function syncButtons() {
                [[conditionList, 'conditions'], [actionList, 'actions']].forEach(([list, name]) => {
                    Array.from(list.querySelectorAll('[data-rule-row]')).forEach((row, index) => {
                        row.querySelectorAll('[name]').forEach((control) => {
                            control.name = control.name.replace(/^(conditions|actions)\[[^\]]+\]/, `${name}[${index}]`);
                        });
                    });
                });
                form.querySelector('[data-add-row="condition"]').disabled = conditionList.children.length >= Number(form.dataset.maxConditions);
                form.querySelector('[data-add-row="action"]').disabled = actionList.children.length >= Number(form.dataset.maxActions);
                [conditionList, actionList].forEach((list) => {
                    const rows = Array.from(list.querySelectorAll('[data-rule-row]'));
                    rows.forEach((row, index) => {
                        row.querySelector('[data-move-row="up"]').disabled = index === 0;
                        row.querySelector('[data-move-row="down"]').disabled = index === rows.length - 1;
                        row.querySelector('[data-remove-row]').disabled = list === actionList && rows.length <= 1;
                    });
                });
            }

            function syncAll() {
                conditionList.querySelectorAll('[data-rule-row]').forEach(syncCondition);
                actionList.querySelectorAll('[data-rule-row]').forEach(syncAction);
                syncButtons();
            }

            form.addEventListener('change', (event) => {
                const row = event.target.closest('[data-rule-row]');
                if (event.target === eventSelect) syncAll();
                else if (row?.querySelector('[data-condition-field]')) syncCondition(row);
                else if (row?.querySelector('[data-action-type]')) syncAction(row);
            });

            form.addEventListener('click', (event) => {
                const addButton = event.target.closest('[data-add-row]');
                const removeButton = event.target.closest('[data-remove-row]');
                const moveButton = event.target.closest('[data-move-row]');

                if (addButton) {
                    const isCondition = addButton.dataset.addRow === 'condition';
                    const template = isCondition ? conditionTemplate : actionTemplate;
                    const list = isCondition ? conditionList : actionList;
                    const fragment = template.content.cloneNode(true);
                    const wrapper = document.createElement('div');
                    wrapper.appendChild(fragment);
                    wrapper.innerHTML = wrapper.innerHTML.replaceAll('__INDEX__', String(nextRowIndex++));
                    const row = wrapper.firstElementChild;
                    list.appendChild(row);
                    isCondition ? syncCondition(row) : syncAction(row);
                    syncButtons();
                }

                if (removeButton && !removeButton.disabled) {
                    removeButton.closest('[data-rule-row]').remove();
                    syncButtons();
                }

                if (moveButton && !moveButton.disabled) {
                    const row = moveButton.closest('[data-rule-row]');
                    const sibling = moveButton.dataset.moveRow === 'up' ? row.previousElementSibling : row.nextElementSibling;
                    if (moveButton.dataset.moveRow === 'up') row.parentElement.insertBefore(row, sibling);
                    else row.parentElement.insertBefore(sibling, row);
                    syncButtons();
                }
            });

            syncAll();
        })();
    </script>
</x-layouts.app>
