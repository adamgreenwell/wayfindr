@php
    $reportTabs = [
        ['id' => 'volume', 'label' => __('reports.tabs.volume')],
        ['id' => 'speed', 'label' => __('reports.tabs.speed')],
        ['id' => 'tickets', 'label' => __('reports.tabs.tickets')],
        ['id' => 'agents', 'label' => __('reports.tabs.agents')],
        // Its own tab, not a row under Speed. Every other tab answers how fast
        // the desk moved; this one answers whether that helped, and a desk can
        // improve all of the others while getting worse at this.
        ['id' => 'satisfaction', 'label' => __('reports.tabs.satisfaction')],
    ];
    $activeSites = $sites->reject->isArchived();
    $archivedSites = $sites->filter->isArchived();
@endphp

<x-layouts.app :title="__('reports.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('reports.title')" :subtitle="__('reports.subtitle')">
        <x-slot:actions>
            <span class="lede">{{ trans_choice('reports.range.last_days', $window->days, ['count' => \App\Support\ReaderNumber::count($window->days)]) }}</span>
        </x-slot:actions>
    </x-page-header>

    <section class="section" aria-labelledby="report-filters-heading">
        <div class="section-header">
            <h2 id="report-filters-heading">{{ __('reports.range.heading') }}</h2>
            <span class="lede">{{ $siteId ? __('reports.range.one_site') : __('reports.range.all_sites') }}</span>
        </div>
        <form class="section-form" method="GET" action="{{ route('dashboard.reports.index') }}">
            <div class="meta-grid">
                <div class="meta-item">
                    <label class="meta-label" for="report_days">{{ __('reports.range.period') }}</label>
                    <select id="report_days" name="report_days">
                        @foreach ($windowChoices as $choice)
                            <option value="{{ $choice }}" @selected($window->days === $choice)>{{ trans_choice('reports.range.last_days', $choice, ['count' => \App\Support\ReaderNumber::count($choice)]) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="report_site">{{ __('reports.range.site') }}</label>
                    <select id="report_site" name="report_site">
                        <option value="">{{ __('reports.range.all_sites') }}</option>
                        @foreach ($activeSites as $site)
                            <option lang="" value="{{ $site->id }}" @selected($siteId === $site->id)>{{ $site->name }}</option>
                        @endforeach
                        @if ($archivedSites->isNotEmpty())
                            <optgroup label="{{ __('reports.range.archived_sites') }}">
                                @foreach ($archivedSites as $site)
                                    <option lang="" value="{{ $site->id }}" @selected($siteId === $site->id)>{{ $site->name }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('reports.range.report') }}</span>
                    <button class="button" type="submit">{{ __('reports.range.apply') }}</button>
                    <a class="button secondary" href="{{ route('dashboard.reports.index') }}">{{ __('reports.range.reset') }}</a>
                </div>
            </div>
        </form>
    </section>

    @if ($historyIsPartial)
        <section class="section" aria-labelledby="report-history-heading">
            <div class="section-header">
                <h2 id="report-history-heading">{{ __('reports.history.heading') }}</h2>
                <span class="lede">{{ __('reports.history.lede') }}</span>
            </div>
            <div class="notice-copy">
                <p><strong>{{ __('reports.history.opened') }}</strong> {{ __('reports.history.opened_detail') }} <strong>{{ __('reports.history.first_response') }}</strong> {{ __('reports.history.first_response_detail') }}</p>
                @if ($historyBeganAt)
                    <p><strong>{{ __('reports.history.lifecycle') }}</strong> {{ __('reports.history.lifecycle_with_date', ['date' => \App\Support\ReaderClock::date($historyBeganAt)]) }}</p>
                @else
                    <p><strong>{{ __('reports.history.lifecycle') }}</strong> {{ __('reports.history.lifecycle_without_date') }}</p>
                @endif
                <p>{{ __('reports.history.purge') }}</p>
            </div>
        </section>
    @endif

    <x-tabs id="support-report" :label="__('reports.tabs.region')" :tabs="$reportTabs">
        <x-tab-panel id="volume" :active="true">
            <section class="section" aria-labelledby="report-volume-heading">
                <div class="section-header">
                    <h2 id="report-volume-heading">{{ __('reports.conversations.volume.heading') }}</h2>
                    <span class="lede">
                        {{ trans_choice('reports.counts.opened', $volume['opened_total'], ['count' => \App\Support\ReaderNumber::count($volume['opened_total'])]) }}
                        &middot; {{ trans_choice('reports.counts.closed', $volume['closed_total'], ['count' => \App\Support\ReaderNumber::count($volume['closed_total'])]) }}
                        &middot; {{ trans_choice('reports.counts.open_now', $volume['open_now'], ['count' => \App\Support\ReaderNumber::count($volume['open_now'])]) }}
                    </span>
                </div>

                @if ($chart['max'] === 0)
                    <p class="empty">{{ __('reports.conversations.volume.empty') }}</p>
                @else
                    <div class="chart-scroll">
                        <div
                            class="chart"
                            role="img"
                            aria-label="{{ __('reports.conversations.volume.chart_aria', [
                                'opened' => \App\Support\ReaderNumber::count($volume['opened_total']),
                                'closed' => \App\Support\ReaderNumber::count($volume['closed_total']),
                                'days' => \App\Support\ReaderNumber::count($window->days),
                                'date' => \App\Support\ReaderClock::date($window->endsOn()),
                                'busiest' => \App\Support\ReaderNumber::count($chart['max']),
                            ]) }}"
                        >
                            @foreach ($chart['days'] as $day)
                                <div class="chart__day" title="{{ __('reports.conversations.volume.day_title', [
                                    'date' => $day['label'],
                                    'opened' => \App\Support\ReaderNumber::count($day['opened']),
                                    'closed' => \App\Support\ReaderNumber::count($day['closed']),
                                ]) }}">
                                    <div class="chart__bars">
                                        {{-- A day with nothing on it gets no bar at all. The minimum
                                             height that keeps a single conversation visible would
                                             otherwise draw a sliver on every empty day. --}}
                                        <div class="chart__bar chart__bar--opened @if ($day['opened'] === 0) chart__bar--none @endif" style="height: {{ round(($day['opened'] / $chart['max']) * 100, 2) }}%"></div>
                                        <div class="chart__bar chart__bar--closed @if ($day['closed'] === 0) chart__bar--none @endif" style="height: {{ round(($day['closed'] / $chart['max']) * 100, 2) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <p class="chart-legend">
                        <span class="chart-key chart-key--opened"></span> {{ __('reports.counts.opened_label') }}
                        <span class="chart-key chart-key--closed"></span> {{ __('reports.counts.closed_label') }}
                        <span class="lede">{{ __('reports.charts.tallest_day', ['count' => \App\Support\ReaderNumber::count($chart['max'])]) }}</span>
                    </p>
                    <p class="lede">
                        <a href="{{ route('dashboard.reports.export', $reportQuery + ['report_export' => 'daily']) }}">{{ __('reports.conversations.volume.export') }}</a>
                    </p>
                @endif
            </section>

            <section class="section" aria-labelledby="report-queue-heading">
                <div class="section-header">
                    <h2 id="report-queue-heading">{{ __('reports.conversations.queue.heading') }}</h2>
                    <span class="lede">{{ __('reports.conversations.queue.lede') }}</span>
                </div>
                @if ($queueHealth['needs_reply'] === 0)
                    <p class="empty">{{ __('reports.conversations.queue.empty') }}</p>
                @else
                    <p>{!! trans_choice('reports.conversations.queue.waiting', $queueHealth['needs_reply'], [
                        'count' => '<strong>'.e(\App\Support\ReaderNumber::count($queueHealth['needs_reply'])).'</strong>',
                        'duration' => e($durationLabels['queue_oldest']),
                    ]) !!}</p>
                @endif
                <p class="lede">{{ trans_choice('reports.conversations.queue.threshold', $queueHealth['threshold_minutes'], ['count' => \App\Support\ReaderNumber::count($queueHealth['threshold_minutes'])]) }}</p>
            </section>
        </x-tab-panel>

        <x-tab-panel id="speed">
            <section class="section" aria-labelledby="report-response-heading">
                <div class="section-header">
                    <h2 id="report-response-heading">{{ __('reports.conversations.response.heading') }}</h2>
                    <span class="lede">{{ trans_choice('reports.counts.measured', $firstResponse['summary']->count, ['count' => \App\Support\ReaderNumber::count($firstResponse['summary']->count)]) }}</span>
                </div>
                @if ($firstResponse['summary']->isEmpty())
                    <p class="empty">{{ __('reports.conversations.response.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.median') }}</th>
                                    <td>{{ $durationLabels['first_response_median'] }}</td>
                                    <td class="lede">{{ __('reports.conversations.response.median_detail') }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.p90') }}</th>
                                    <td>{{ $durationLabels['first_response_p90'] }}</td>
                                    <td class="lede">{{ __('reports.conversations.response.p90_detail') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
                @if ($firstResponse['awaiting'] > 0)
                    <p class="lede">{{ trans_choice('reports.conversations.response.awaiting', $firstResponse['awaiting'], ['count' => \App\Support\ReaderNumber::count($firstResponse['awaiting'])]) }}</p>
                @endif
            </section>

            <section class="section" aria-labelledby="report-resolution-heading">
                <div class="section-header">
                    <h2 id="report-resolution-heading">{{ __('reports.conversations.resolution.heading') }}</h2>
                    <span class="lede">{{ trans_choice('reports.counts.closes_measured', $resolution['summary']->count, ['count' => \App\Support\ReaderNumber::count($resolution['summary']->count)]) }}</span>
                </div>
                @if ($resolution['summary']->isEmpty() && $resolution['unmeasurable'] > 0)
                    {{-- An upgraded install whose closes are all older than the
                         recording boundary lands here. Saying "nothing was
                         closed" would be false, and would hide the explanation
                         in exactly the case that needs it most. --}}
                    <p class="empty">{{ trans_choice('reports.conversations.resolution.unmeasurable_empty', $resolution['unmeasurable'], ['count' => \App\Support\ReaderNumber::count($resolution['unmeasurable'])]) }}</p>
                @elseif ($resolution['summary']->isEmpty())
                    <p class="empty">{{ __('reports.conversations.resolution.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.median') }}</th>
                                    <td>{{ $durationLabels['resolution_median'] }}</td>
                                    <td class="lede">{{ __('reports.conversations.resolution.median_detail') }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.p90') }}</th>
                                    <td>{{ $durationLabels['resolution_p90'] }}</td>
                                    <td class="lede">{{ __('reports.metrics.slowest_tenth') }}</td>
                                </tr>
                                @if ($resolution['unmeasurable'] > 0)
                                    <tr>
                                        <th scope="row">{{ __('reports.metrics.unmeasured') }}</th>
                                        <td>{{ \App\Support\ReaderNumber::count($resolution['unmeasurable']) }}</td>
                                        <td class="lede">{{ __('reports.conversations.resolution.unmeasured_detail') }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.reopened') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($resolution['reopened']) }}</td>
                                    <td class="lede">{{ __('reports.metrics.reopened_detail') }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.conversations.resolution.reopened_by_visitor') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($resolution['reopened_by_visitor']) }}</td>
                                    <td class="lede">{{ __('reports.conversations.resolution.reopened_by_visitor_detail') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </x-tab-panel>

        <x-tab-panel id="tickets">
            <section class="section" aria-labelledby="report-ticket-volume-heading">
                <div class="section-header">
                    <div>
                        <h2 id="report-ticket-volume-heading">{{ __('reports.tickets.volume.heading') }}</h2>
                        <p class="lede">
                            {{ trans_choice('reports.counts.tickets_created', $ticketVolume['opened_total'], ['count' => \App\Support\ReaderNumber::count($ticketVolume['opened_total'])]) }} ·
                            {{ trans_choice('reports.counts.tickets_closed', $ticketVolume['closed_total'], ['count' => \App\Support\ReaderNumber::count($ticketVolume['closed_total'])]) }} ·
                            {{ trans_choice('reports.counts.tickets_open_now', $ticketVolume['open_now'], ['count' => \App\Support\ReaderNumber::count($ticketVolume['open_now'])]) }}
                        </p>
                    </div>
                </div>

                @if ($ticketVolume['opened_total'] === 0 && $ticketVolume['closed_total'] === 0)
                    <div class="notice-copy">
                        @if ($ticketHistoryBeganAt && $window->start->lessThan($ticketHistoryBeganAt))
                            {{-- The resolution section below already refuses to
                                 claim nothing was closed when the range reaches
                                 back past the boundary. Saying it plainly here
                                 put two answers to the same question on one
                                 tab. --}}
                            <p>{{ __('reports.tickets.volume.empty_before_history', ['date' => \App\Support\ReaderClock::date($ticketHistoryBeganAt)]) }}</p>
                        @else
                            <p>{{ __('reports.tickets.volume.empty') }}</p>
                        @endif
                    </div>
                @else
                    {{-- The same chart the conversation tab draws, INCLUDING its
                         scrolling wrapper. Its classes are the ones with CSS
                         behind them; a second set invented here would render as
                         an unstyled column of divs, and omitting `chart-scroll`
                         makes ninety days of bars widen the whole page instead
                         of scrolling inside their own box. --}}
                    <div class="chart-scroll">
                        <div
                            class="chart"
                            role="img"
                            aria-label="{{ __('reports.tickets.volume.chart_aria', [
                                'created' => \App\Support\ReaderNumber::count($ticketVolume['opened_total']),
                                'closed' => \App\Support\ReaderNumber::count($ticketVolume['closed_total']),
                                'days' => \App\Support\ReaderNumber::count($window->days),
                                'date' => \App\Support\ReaderClock::date($window->endsOn()),
                                'busiest' => \App\Support\ReaderNumber::count($ticketChart['max']),
                            ]) }}"
                        >
                            @foreach ($ticketChart['days'] as $day)
                                <div class="chart__day" title="{{ __('reports.tickets.volume.day_title', [
                                    'date' => $day['label'],
                                    'created' => \App\Support\ReaderNumber::count($day['opened']),
                                    'closed' => \App\Support\ReaderNumber::count($day['closed']),
                                ]) }}">
                                    <div class="chart__bars">
                                        <div class="chart__bar chart__bar--opened @if ($day['opened'] === 0) chart__bar--none @endif" style="height: {{ $ticketChart['max'] === 0 ? 0 : round(($day['opened'] / $ticketChart['max']) * 100, 2) }}%"></div>
                                        <div class="chart__bar chart__bar--closed @if ($day['closed'] === 0) chart__bar--none @endif" style="height: {{ $ticketChart['max'] === 0 ? 0 : round(($day['closed'] / $ticketChart['max']) * 100, 2) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        </div>
                    <p class="chart-legend">
                        <span class="chart-key chart-key--opened"></span> {{ __('reports.counts.created_label') }}
                        <span class="chart-key chart-key--closed"></span> {{ __('reports.counts.tickets_closed_label') }}
                        <span class="lede">{{ __('reports.charts.tallest_day', ['count' => \App\Support\ReaderNumber::count($ticketChart['max'])]) }}</span>
                    </p>
                @endif
            </section>

            <section class="section" aria-labelledby="report-ticket-resolution-heading">
                <div class="section-header">
                    <div>
                        <h2 id="report-ticket-resolution-heading">{{ __('reports.tickets.resolution.heading') }}</h2>
                        <p class="lede">
                            {{ trans_choice('reports.counts.closes_measured', $ticketResolution['summary']->count, ['count' => \App\Support\ReaderNumber::count($ticketResolution['summary']->count)]) }}
                        </p>
                    </div>
                </div>

                @if ($ticketResolution['summary']->count === 0 && $ticketResolution['unmeasurable'] > 0)
                    {{-- Same shape as the conversation half: an upgraded install
                         whose ticket closes all predate the boundary lands here,
                         and "nothing was closed" would be false. --}}
                    <div class="notice-copy">
                        <p>{{ trans_choice('reports.tickets.resolution.unmeasurable_empty', $ticketResolution['unmeasurable'], ['count' => \App\Support\ReaderNumber::count($ticketResolution['unmeasurable'])]) }}</p>
                        @if ($ticketResolution['reopened'] > 0)
                            <p>{{ trans_choice('reports.tickets.resolution.reopened_unmeasurable', $ticketResolution['reopened'], ['count' => \App\Support\ReaderNumber::count($ticketResolution['reopened'])]) }}</p>
                        @endif
                    </div>
                @elseif ($ticketResolution['summary']->count === 0)
                    {{-- A reopen needs no close to be worth reporting. A ticket
                         closed before the range and reopened inside it is a
                         resolution that did not hold, and it stays open -- so
                         it never reaches the table below, and the figure the
                         backend now counts correctly would still be invisible
                         on the page. --}}
                    @if ($ticketResolution['reopened'] > 0)
                        <div class="table-wrap">
                            <table>
                                <tbody>
                                    <tr>
                                        <th scope="row">{{ __('reports.metrics.reopened') }}</th>
                                        <td>{{ \App\Support\ReaderNumber::count($ticketResolution['reopened']) }}</td>
                                        <td class="lede">{{ __('reports.tickets.resolution.reopened_without_close') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="notice-copy">
                        @if ($ticketHistoryBeganAt && $window->start->lessThan($ticketHistoryBeganAt))
                            {{-- The window reaches back past the boundary, so
                                 "nothing was closed" is a claim this install
                                 cannot make: closes before it are unknowable,
                                 not absent. --}}
                            <p>{{ __('reports.tickets.resolution.empty_before_history', ['date' => \App\Support\ReaderClock::date($ticketHistoryBeganAt)]) }}</p>
                        @else
                            <p>{{ __('reports.tickets.resolution.empty') }}</p>
                        @endif
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.median') }}</th>
                                    <td>{{ $durationLabels['ticket_resolution_median'] }}</td>
                                    <td class="lede">{{ __('reports.tickets.resolution.median_detail') }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.p90') }}</th>
                                    <td>{{ $durationLabels['ticket_resolution_p90'] }}</td>
                                    <td class="lede">{{ __('reports.metrics.slowest_tenth') }}</td>
                                </tr>
                                @if ($ticketResolution['unmeasurable'] > 0)
                                    <tr>
                                        <th scope="row">{{ __('reports.metrics.unmeasured') }}</th>
                                        <td>{{ \App\Support\ReaderNumber::count($ticketResolution['unmeasurable']) }}</td>
                                        <td class="lede">{{ __('reports.tickets.resolution.unmeasured_detail') }}</td>
                                    </tr>
                                @endif
                                <tr>
                                    <th scope="row">{{ __('reports.metrics.reopened') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($ticketResolution['reopened']) }}</td>
                                    <td class="lede">{{ __('reports.tickets.resolution.reopened_detail') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                @endif

                @if ($ticketHistoryBeganAt)
                    <div class="notice-copy">
                        <p>{{ __('reports.tickets.resolution.history', ['date' => \App\Support\ReaderClock::date($ticketHistoryBeganAt)]) }}</p>
                            {{-- States this install's own date and nothing about how it compares to the
                                 existed the migration stamps today, which can be the same day as the
                                 conversation boundary -- so "long before" would be false exactly where a
                                 reader most needs the figure to be trustworthy. --}}
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="report-ticket-agents-heading">
                <div class="section-header">
                    <div>
                        <h2 id="report-ticket-agents-heading">{{ __('reports.tickets.agents.heading') }}</h2>
                    </div>
                </div>

                @if ($ticketAgentActivity === [])
                    <div class="notice-copy">
                        <p>{{ __('reports.tickets.agents.empty') }}</p>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('reports.tables.agent') }}</th>
                                    <th scope="col">{{ __('reports.tables.replies') }}</th>
                                    <th scope="col">{{ __('reports.tables.tickets_closed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ticketAgentActivity as $row)
                                    <tr>
                                        <td @if ($row['agent']) lang="" @endif>{{ $row['agent'] ? $row['name'] : __('reports.agents.removed') }}</td>
                                        <td>{{ \App\Support\ReaderNumber::count($row['replies']) }}</td>
                                        <td>{{ \App\Support\ReaderNumber::count($row['closes']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </x-tab-panel>

        <x-tab-panel id="agents">
            <section class="section" aria-labelledby="report-agents-heading">
                <div class="section-header">
                    <h2 id="report-agents-heading">{{ __('reports.agents.heading') }}</h2>
                    <span class="lede">{{ trans_choice('reports.counts.agents', count($agentActivity), ['count' => \App\Support\ReaderNumber::count(count($agentActivity))]) }}</span>
                </div>
                @if ($agentActivity === [])
                    <p class="empty">{{ __('reports.agents.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('reports.tables.agent') }}</th>
                                    <th scope="col">{{ __('reports.tables.replies') }}</th>
                                    <th scope="col">{{ __('reports.tables.conversations_closed') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($agentActivity as $row)
                                    <tr>
                                        <td>
                                            @if ($row['agent'])
                                                <span lang="">{{ $row['name'] }}</span>
                                            @else
                                                {{ __('reports.agents.removed') }}
                                            @endif
                                            @if ($row['agent']?->isDeactivated())
                                                <span class="lede">{{ __('reports.agents.deactivated') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ \App\Support\ReaderNumber::count($row['replies']) }}</td>
                                        <td>{{ \App\Support\ReaderNumber::count($row['closes']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="lede">{{ __('reports.agents.deactivated_detail') }}
                        <a href="{{ route('dashboard.reports.export', $reportQuery + ['report_export' => 'agents']) }}">{{ __('reports.agents.export') }}</a>
                    </p>
                @endif
            </section>
        </x-tab-panel>

        <x-tab-panel id="satisfaction">
            <section class="section" aria-labelledby="report-satisfaction-heading">
                <div class="section-header">
                    <h2 id="report-satisfaction-heading">{{ __('reports.satisfaction.heading') }}</h2>
                    <span class="lede">{{ trans_choice('reports.satisfaction.summary', $satisfaction['closed'], [
                        'answered' => \App\Support\ReaderNumber::count($satisfaction['answered']),
                        'closed' => \App\Support\ReaderNumber::count($satisfaction['closed']),
                    ]) }}</span>
                </div>
                @if ($satisfaction['answered'] === 0)
                    {{-- Never a zero or a dash where a percentage goes. Nobody
                         answering is not the same as everybody answering badly,
                         and a 0% here would be read as the second. --}}
                    <p class="empty">
                        @if ($satisfaction['closed'] === 0)
                            {{ __('reports.satisfaction.no_closes') }}
                        @else
                            {{ __('reports.satisfaction.no_answers_before') }}
                            <strong>{{ __('reports.satisfaction.setting') }}</strong>
                            {{ __('reports.satisfaction.no_answers_after') }}
                        @endif
                    </p>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">{{ __('reports.satisfaction.good') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($satisfaction['good']) }}</td>
                                    <td class="lede">{{ __('reports.satisfaction.good_detail', ['percentage' => \App\Support\ReaderNumber::percentage($satisfaction['positive'], 1)]) }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.satisfaction.ok') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($satisfaction['ok']) }}</td>
                                    <td class="lede">{{ __('reports.satisfaction.ok_detail') }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.satisfaction.bad') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($satisfaction['bad']) }}</td>
                                    <td class="lede">{{ __('reports.satisfaction.bad_detail') }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">{{ __('reports.satisfaction.answered') }}</th>
                                    <td>{{ \App\Support\ReaderNumber::count($satisfaction['answered']) }}</td>
                                    <td class="lede">{{ trans_choice('reports.satisfaction.answered_detail', $satisfaction['closed'], ['count' => \App\Support\ReaderNumber::count($satisfaction['closed'])]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    @if ($satisfaction['answered'] < 10)
                        <p class="lede">{{ __('reports.satisfaction.small_sample') }}</p>
                    @endif
                @endif
            </section>

            <section class="section" aria-labelledby="report-comments-heading">
                <div class="section-header">
                    <h2 id="report-comments-heading">{{ __('reports.comments.heading') }}</h2>
                    <span class="lede">{{ trans_choice('reports.counts.comments', count($ratingComments), ['count' => \App\Support\ReaderNumber::count(count($ratingComments))]) }}</span>
                </div>

                @if ($ratingComments === [])
                    <p class="empty">{{ __('reports.comments.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('reports.comments.score') }}</th>
                                    <th scope="col">{{ __('reports.comments.said') }}</th>
                                    <th scope="col">{{ __('reports.comments.conversation') }}</th>
                                    <th scope="col">{{ __('reports.comments.when') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ratingComments as $comment)
                                    <tr>
                                        <td>{{ __('reports.satisfaction.'.$comment['score']) }}</td>
                                        {{-- Visitor-authored, so escaped like any other visitor text and
                                             never used as a link label. --}}
                                        <td lang="">{{ $comment['comment'] }}</td>
                                        <td>
                                            <a lang="" href="{{ route('dashboard.conversations.show', $comment['support_code']) }}">{{ $comment['support_code'] }}</a>
                                        </td>
                                        <td>{{ $comment['rated_at']->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="lede">{{ trans_choice('reports.comments.latest', count($ratingComments), ['count' => \App\Support\ReaderNumber::count(count($ratingComments))]) }}</p>
                @endif
            </section>
        </x-tab-panel>
    </x-tabs>
</x-layouts.app>
