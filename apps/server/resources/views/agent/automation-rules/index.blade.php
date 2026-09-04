@php($presenter = app(\App\Support\Automation\AutomationRulePresenter::class))

<x-layouts.app :title="__('automation_rules.title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('automation_rules.title')" :subtitle="__('automation_rules.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('automation_rules.back')">
        <x-slot:actions>
            <a class="button secondary" href="{{ route('dashboard.account.automation-macros.create') }}">{{ __('automation_macros.create.action') }}</a>
            <a class="button" href="{{ route('dashboard.account.automation-rules.create') }}">{{ __('automation_rules.create.action') }}</a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="automation-safety-heading">
        <div class="section-header">
            <h2 id="automation-safety-heading">{{ __('automation_rules.safety.heading') }}</h2>
            <span class="lede">{{ __('automation_rules.safety.lede') }}</span>
        </div>
        <div class="notice-copy">
            <p>{{ __('automation_rules.safety.drafts') }}</p>
            <p>{{ __('automation_rules.safety.order') }}</p>
            <p>{{ __('automation_rules.safety.visitor') }}</p>
        </div>
    </section>

    <section class="section" aria-labelledby="automation-macros-heading">
        <div class="section-header">
            <h2 id="automation-macros-heading">{{ __('automation_macros.list.heading') }}</h2>
            <span class="lede">{{ trans_choice('automation_macros.list.count', $macros->count(), ['count' => \App\Support\ReaderNumber::count($macros->count())]) }}</span>
        </div>

        @if ($macros->isEmpty())
            <div class="empty empty-state">
                <strong>{{ __('automation_macros.empty.heading') }}</strong>
                {{ __('automation_macros.empty.body') }}
                <div class="empty-state-actions">
                    <a class="button secondary" href="{{ route('dashboard.account.automation-macros.create') }}">{{ __('automation_macros.empty.action') }}</a>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('automation_macros.list.macro') }}</th>
                            <th scope="col">{{ __('automation_macros.list.work_type') }}</th>
                            <th scope="col">{{ __('automation_macros.list.actions') }}</th>
                            <th scope="col">{{ __('automation_macros.list.order') }}</th>
                            <th scope="col">{{ __('automation_macros.list.status') }}</th>
                            <th scope="col">{{ __('automation_macros.list.manage') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($macros as $macro)
                            <tr>
                                <td><strong lang="">{{ $macro->name }}</strong></td>
                                <td>{{ __('automation_macros.subject_types.'.$macro->subject_type) }}</td>
                                <td>{{ trans_choice('automation_rules.list.action_count', count($macro->actions), ['count' => \App\Support\ReaderNumber::count(count($macro->actions))]) }}</td>
                                <td>{{ $macro->position }}</td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $macro->is_enabled ? 'ready' : 'manual' }}">
                                        {{ $macro->is_enabled ? __('automation_rules.status.enabled') : __('automation_rules.status.draft') }}
                                    </span>
                                </td>
                                <td><a class="button secondary" href="{{ route('dashboard.account.automation-macros.edit', $macro) }}">{{ __('automation_macros.list.edit') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="section" aria-labelledby="automation-rules-heading">
        <div class="section-header">
            <h2 id="automation-rules-heading">{{ __('automation_rules.list.heading') }}</h2>
            <span class="lede">{{ trans_choice('automation_rules.list.count', $rules->count(), ['count' => \App\Support\ReaderNumber::count($rules->count())]) }}</span>
        </div>

        @if ($rules->isEmpty())
            <div class="empty empty-state">
                <strong>{{ __('automation_rules.empty.heading') }}</strong>
                {{ __('automation_rules.empty.body') }}
                <div class="empty-state-actions">
                    <a class="button secondary" href="{{ route('dashboard.account.automation-rules.create') }}">{{ __('automation_rules.empty.action') }}</a>
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('automation_rules.list.rule') }}</th>
                            <th scope="col">{{ __('automation_rules.list.event') }}</th>
                            <th scope="col">{{ __('automation_rules.list.definition') }}</th>
                            <th scope="col">{{ __('automation_rules.list.order') }}</th>
                            <th scope="col">{{ __('automation_rules.list.status') }}</th>
                            <th scope="col">{{ __('automation_rules.list.manage') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $rule)
                            <tr>
                                <td><strong lang="">{{ $rule->name }}</strong></td>
                                <td>{{ $presenter->event($rule->event) }}</td>
                                <td>
                                    <span>{{ trans_choice('automation_rules.list.condition_count', count($rule->conditions), ['count' => \App\Support\ReaderNumber::count(count($rule->conditions))]) }}</span>
                                    <span class="table-note">{{ trans_choice('automation_rules.list.action_count', count($rule->actions), ['count' => \App\Support\ReaderNumber::count(count($rule->actions))]) }}</span>
                                </td>
                                <td>{{ $rule->position }}</td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $rule->is_enabled ? 'ready' : 'manual' }}">
                                        {{ $rule->is_enabled ? __('automation_rules.status.enabled') : __('automation_rules.status.draft') }}
                                    </span>
                                </td>
                                <td><a class="button secondary" href="{{ route('dashboard.account.automation-rules.edit', $rule) }}">{{ __('automation_rules.list.edit') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="section" aria-labelledby="automation-executions-heading">
        <div class="section-header">
            <h2 id="automation-executions-heading">{{ __('automation_rules.executions.heading') }}</h2>
            <span class="lede">{{ __('automation_rules.executions.lede') }}</span>
        </div>

        @if ($executions->isEmpty())
            <p class="empty">{{ __('automation_rules.executions.empty') }}</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('automation_rules.executions.when') }}</th>
                            <th scope="col">{{ __('automation_rules.executions.rule') }}</th>
                            <th scope="col">{{ __('automation_rules.executions.work') }}</th>
                            <th scope="col">{{ __('automation_rules.executions.outcome') }}</th>
                            <th scope="col">{{ __('automation_rules.executions.details') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($executions as $execution)
                            @php($isMacroExecution = data_get($execution->metadata, 'source') === 'macro')
                            <tr>
                                <td>
                                    {{ $execution->completed_at === null ? '' : \App\Support\ReaderClock::dateTime($execution->completed_at, $agent) }}
                                    <span class="table-note">{{ $isMacroExecution ? __('automation_macros.execution.kind', ['type' => __('automation_macros.subject_types.'.($execution->event === 'macro.ticket' ? 'ticket' : 'conversation'))]) : $presenter->event($execution->event) }}</span>
                                </td>
                                <td><strong lang="">{{ $execution->rule_name }}</strong></td>
                                <td>
                                    @if ($executionLinks[$execution->id])
                                        <a class="text-link" href="{{ $executionLinks[$execution->id] }}">{{ $presenter->executionSubject($execution) }}</a>
                                    @elseif ($execution->subject !== null)
                                        {{ __('automation_rules.subjects.restricted') }}
                                    @else
                                        {{ $presenter->executionSubject($execution) }}
                                    @endif
                                </td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $execution->status === 'succeeded' ? 'ready' : 'attention' }}">
                                        {{ __('automation_rules.execution_statuses.'.$execution->status) }}
                                    </span>
                                </td>
                                <td class="automation-log-details">
                                    <x-details-disclosure :summary="__('automation_rules.executions.open_details')">
                                        <div>
                                            <strong>{{ $isMacroExecution ? __('automation_macros.execution.trigger') : __('automation_rules.executions.conditions') }}</strong>
                                            @if ($isMacroExecution)
                                                <p class="lede">
                                                    {{ __('automation_macros.execution.triggered_by') }}
                                                    @if (data_get($execution->metadata, 'triggered_by_name'))
                                                        <span lang="">{{ data_get($execution->metadata, 'triggered_by_name') }}</span>.
                                                    @else
                                                        {{ __('account_audit.references.system') }}.
                                                    @endif
                                                </p>
                                            @elseif ($execution->conditions === [])
                                                <p class="lede">{{ __('automation_rules.values.every_event') }}</p>
                                            @else
                                                <ol class="automation-definition-list">
                                                    @foreach ($execution->conditions as $condition)
                                                        <li>{{ $presenter->condition($condition, $execution->event, $referenceLabels) }}</li>
                                                    @endforeach
                                                </ol>
                                            @endif
                                        </div>
                                        <div>
                                            <strong>{{ __('automation_rules.executions.actions') }}</strong>
                                            <ol class="automation-definition-list">
                                                @foreach ($execution->actions as $action)
                                                    <li>{{ $presenter->action($action, $execution->event, $referenceLabels) }}</li>
                                                @endforeach
                                            </ol>
                                        </div>
                                        @if ($execution->action_results !== [])
                                            <div>
                                                <strong>{{ __('automation_rules.executions.results') }}</strong>
                                                <ol class="automation-definition-list">
                                                    @foreach ($execution->action_results as $result)
                                                        <li>{{ $presenter->result($result, $execution->event, $referenceLabels) }}</li>
                                                    @endforeach
                                                </ol>
                                            </div>
                                        @endif
                                        @if ($execution->error_message)
                                            <div>
                                                <strong>{{ __('automation_rules.executions.error') }}</strong>
                                                <p class="field-error" lang="">{{ $execution->error_message }}</p>
                                            </div>
                                        @endif
                                    </x-details-disclosure>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $executions->links() }}
        @endif
    </section>
</x-layouts.app>
