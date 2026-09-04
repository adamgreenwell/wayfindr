@php
    $selectedSubjectType = old('subject_type', $automationMacro?->subject_type ?? \App\Enums\AutomationMacroSubjectType::Ticket->value);
    $actionRows = old('actions', $actionRows);
    $isEditing = $automationMacro !== null;
@endphp

<x-layouts.app :title="$isEditing ? __('automation_macros.edit.title') : __('automation_macros.create.title')" :agent="$agent" :account="$account">
    <x-page-header
        :title="$isEditing ? __('automation_macros.edit.title_named', ['name' => $automationMacro->name]) : __('automation_macros.create.title')"
        :subtitle="$isEditing ? __('automation_macros.edit.subtitle') : __('automation_macros.create.subtitle')"
        :back-href="route('dashboard.account.automation-rules.index')"
        :back-label="__('automation_macros.edit.back')"
    />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    @if ($errors->any())
        <section class="section automation-validation" aria-labelledby="automation-macro-validation-heading">
            <div class="section-header">
                <h2 id="automation-macro-validation-heading">{{ __('automation_macros.validation.heading') }}</h2>
            </div>
            <ul>
                @foreach ($errors->all() as $error)
                    <li class="field-error">{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="section" aria-labelledby="automation-macro-definition-heading">
        <div class="section-header">
            <div>
                <h2 id="automation-macro-definition-heading">{{ __('automation_macros.builder.heading') }}</h2>
                <p class="lede">{{ __('automation_macros.builder.lede') }}</p>
            </div>
            <span class="readiness-status" data-status="{{ old('is_enabled', $automationMacro?->is_enabled ?? false) ? 'ready' : 'manual' }}">
                {{ old('is_enabled', $automationMacro?->is_enabled ?? false) ? __('automation_rules.status.enabled') : __('automation_rules.status.draft') }}
            </span>
        </div>

        <form
            id="automation-macro-form"
            class="section-form"
            method="POST"
            action="{{ $isEditing ? route('dashboard.account.automation-macros.update', $automationMacro) : route('dashboard.account.automation-macros.store') }}"
            data-automation-macro-form
            data-max-actions="{{ \App\Support\Automation\AutomationMacroForm::MAX_ACTIONS }}"
        >
            @csrf
            @if ($isEditing) @method('PUT') @endif

            <div class="automation-rule-basics">
                <div class="field">
                    <label for="automation-macro-name">{{ __('automation_macros.fields.name') }}</label>
                    <input id="automation-macro-name" name="name" value="{{ old('name', $automationMacro?->name) }}" maxlength="120" lang="" required>
                    <span class="field-help">{{ __('automation_macros.fields.name_help') }}</span>
                </div>
                <div class="field">
                    <label for="automation-macro-subject">{{ __('automation_macros.fields.subject_type') }}</label>
                    <select id="automation-macro-subject" name="subject_type" data-macro-subject required>
                        @foreach ($subjectTypes as $subjectType)
                            <option value="{{ $subjectType->value }}" @selected($selectedSubjectType === $subjectType->value)>{{ __('automation_macros.subject_types.'.$subjectType->value) }}</option>
                        @endforeach
                    </select>
                    <span class="field-help">{{ __('automation_macros.fields.subject_type_help') }}</span>
                </div>
                <div class="field">
                    <label for="automation-macro-position">{{ __('automation_macros.fields.position') }}</label>
                    <input id="automation-macro-position" name="position" type="number" min="0" max="10000" step="1" value="{{ old('position', $defaultPosition) }}" required>
                    <span class="field-help">{{ __('automation_macros.fields.position_help') }}</span>
                </div>
            </div>

            <fieldset class="automation-builder" data-rule-rows="actions">
                <legend>{{ __('automation_rules.builder.actions') }}</legend>
                <p class="lede">{{ __('automation_macros.builder.actions_help') }}</p>
                <div data-rule-row-list>
                    @foreach ($actionRows as $index => $row)
                        @include('agent.automation-rules.partials.action-row', ['index' => $index, 'row' => $row])
                    @endforeach
                </div>
                <button class="button secondary" type="button" data-add-row="action">{{ __('automation_rules.builder.add_action') }}</button>
            </fieldset>

            <input name="is_enabled" type="hidden" value="0">
            <label class="check-row automation-enabled" for="automation-macro-enabled">
                <input id="automation-macro-enabled" name="is_enabled" type="checkbox" value="1" @checked((bool) old('is_enabled', $automationMacro?->is_enabled ?? false))>
                <span>
                    <strong>{{ __('automation_macros.fields.enabled') }}</strong>
                    <span class="lede">{{ __('automation_macros.fields.enabled_help') }}</span>
                </span>
            </label>

            <button class="button" type="submit">{{ $isEditing ? __('automation_macros.edit.save') : __('automation_macros.create.submit') }}</button>
        </form>
    </section>

    @if ($isEditing)
        <section class="section" aria-labelledby="automation-macro-delete-heading">
            <div class="section-header">
                <div>
                    <h2 id="automation-macro-delete-heading">{{ __('automation_macros.delete.heading') }}</h2>
                    <p class="lede">{{ __('automation_macros.delete.lede') }}</p>
                </div>
            </div>
            <form class="section-form" method="POST" action="{{ route('dashboard.account.automation-macros.destroy', $automationMacro) }}">
                @csrf
                @method('DELETE')
                <button class="button danger" type="submit">{{ __('automation_macros.delete.action') }}</button>
            </form>
        </section>
    @endif

    <template data-action-row-template>
        @include('agent.automation-rules.partials.action-row', ['index' => '__INDEX__', 'row' => $form->blankAction()])
    </template>

    <script>
        (() => {
            const form = document.querySelector('[data-automation-macro-form]');
            if (!form) return;

            const subjectSelect = form.querySelector('[data-macro-subject]');
            const actionList = form.querySelector('[data-rule-rows="actions"] [data-rule-row-list]');
            const actionTemplate = document.querySelector('[data-action-row-template]');
            let nextRowIndex = 1000;
            const actionGroups = {
                assign_agent: 'agent',
                add_label: 'label',
                set_priority: 'priority',
                set_status: 'status',
                notify_agent: 'agent',
            };
            const eventName = () => subjectSelect.value === 'ticket' ? 'ticket.updated' : 'conversation.created';

            function syncTypeSelect(select) {
                const event = eventName();
                Array.from(select.options).forEach((option) => {
                    const supported = (option.dataset.events || '').split(',').includes(event);
                    option.hidden = !supported;
                    option.disabled = !supported;
                });
                if (select.selectedOptions[0]?.disabled) {
                    select.value = Array.from(select.options).find((option) => !option.disabled)?.value || '';
                }
            }

            function syncAction(row) {
                const type = row.querySelector('[data-action-type]');
                const textWrap = row.querySelector('[data-text-value]');
                const choiceWrap = row.querySelector('[data-choice-value]');
                const textControl = textWrap?.querySelector('textarea');
                const choiceControl = choiceWrap?.querySelector('select');

                syncTypeSelect(type);
                const showText = type.value === 'post_internal_note';
                const group = actionGroups[type.value];
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
                    const isTicket = subjectSelect.value === 'ticket';
                    option.hidden = !isTicket;
                    option.disabled = !isTicket;
                });
                if (!showText) {
                    const selected = choiceControl.selectedOptions[0];
                    const selectedGroup = selected?.closest('optgroup')?.dataset.choiceGroup;
                    if (selected?.value && (selected.disabled || selectedGroup !== group)) choiceControl.value = '';
                }
            }

            function syncButtons() {
                const rows = Array.from(actionList.querySelectorAll('[data-rule-row]'));
                rows.forEach((row, index) => {
                    row.querySelectorAll('[name]').forEach((control) => {
                        control.name = control.name.replace(/^actions\[[^\]]+\]/, `actions[${index}]`);
                    });
                    row.querySelector('[data-move-row="up"]').disabled = index === 0;
                    row.querySelector('[data-move-row="down"]').disabled = index === rows.length - 1;
                    row.querySelector('[data-remove-row]').disabled = rows.length <= 1;
                });
                form.querySelector('[data-add-row="action"]').disabled = rows.length >= Number(form.dataset.maxActions);
            }

            function syncAll() {
                actionList.querySelectorAll('[data-rule-row]').forEach(syncAction);
                syncButtons();
            }

            form.addEventListener('change', (event) => {
                const row = event.target.closest('[data-rule-row]');
                if (event.target === subjectSelect) syncAll();
                else if (row?.querySelector('[data-action-type]')) syncAction(row);
            });

            form.addEventListener('click', (event) => {
                const addButton = event.target.closest('[data-add-row]');
                const removeButton = event.target.closest('[data-remove-row]');
                const moveButton = event.target.closest('[data-move-row]');

                if (addButton) {
                    const fragment = actionTemplate.content.cloneNode(true);
                    const wrapper = document.createElement('div');
                    wrapper.appendChild(fragment);
                    wrapper.innerHTML = wrapper.innerHTML.replaceAll('__INDEX__', String(nextRowIndex++));
                    const row = wrapper.firstElementChild;
                    actionList.appendChild(row);
                    syncAction(row);
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
