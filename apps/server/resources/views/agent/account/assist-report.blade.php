<x-layouts.account :title="__('assist_observability.report.heading')">
    <x-page-header :title="__('assist_observability.report.heading')" :subtitle="__('assist_observability.report.disclosure')" />
    <form class="wf-filters" method="get" action="{{ route('dashboard.account.ai-assist.report') }}">
        <div class="wf-filter">
            <label for="report-task">{{ __('assist_observability.report.task') }}</label>
            <select id="report-task" name="task">
                @foreach(['all','summary','reply','knowledge','ticket','refinement'] as $task)
                    <option value="{{ $task }}" @selected($report['task'] === $task)>{{ __('assist_observability.report.tasks.'.$task) }}</option>
                @endforeach
            </select>
        </div>
        <div class="wf-filter">
            <label for="report-days">{{ __('assist_observability.report.days') }}</label>
            <input id="report-days" name="days" type="number" min="1" max="31" value="{{ $report['days'] }}">
        </div>
        <div class="wf-filter-actions"><button class="button" type="submit">{{ __('assist_observability.report.refresh') }}</button></div>
    </form>
    @if (!$report['complete'])
        <p role="status">{{ __('assist_observability.report.incomplete') }}</p>
    @else
        @foreach (['feedback','work','usage'] as $section)
            <section class="section" aria-labelledby="assist-report-{{ $section }}">
                <div class="section-header"><h2 id="assist-report-{{ $section }}">{{ __('assist_observability.report.'.$section) }}</h2></div>
                <div class="meta-grid">
                    @foreach ($report[$section] as $key => $value)
                        @if (!is_array($value) || array_key_exists('rate',$value))
                            <div class="meta-item">
                                <span class="meta-label">{{ __('assist_observability.metrics.'.$key) }}</span>
                                <span class="meta-value">@include('agent.account.partials.assist-report-value')</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
        <section class="section" aria-labelledby="assist-report-outcomes">
            <div class="section-header"><h2 id="assist-report-outcomes">{{ __('assist_observability.report.outcomes') }}</h2></div>
            <p class="lede">{{ __('assist_observability.report.outcome_disclosure') }}</p>
            @foreach ($report['outcomes'] as $cohort => $values)
                <h3>{{ __('assist_observability.report.'.$cohort) }}</h3>
                <div class="meta-grid">
                    @foreach ($values as $key => $value)
                        <div class="meta-item">
                            <span class="meta-label">{{ __('assist_observability.metrics.'.$key) }}</span>
                            <span class="meta-value">
                                @if ($key === 'scores')
                                    @foreach ($value as $score => $count)
                                        {{ __('reports.satisfaction.'.$score) }}: {{ \App\Support\ReaderNumber::count($count) }}
                                    @endforeach
                                @else
                                    @include('agent.account.partials.assist-report-value')
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>
    @endif
</x-layouts.account>
