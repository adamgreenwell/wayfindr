<x-layouts.app :title="__('alerts.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('alerts.title')">
        <x-slot:subtitleContent><x-translated-feedback :feedback="[
            'key' => 'alerts.subtitle',
            'parameters' => ['account' => $account->name],
            'localized_parameters' => [],
        ]" /></x-slot:subtitleContent>
    </x-page-header>

    <section class="section" aria-labelledby="alert-center-heading">
        @php
            $alertBaseParams = [];

            if ($alertKind !== 'all') {
                $alertBaseParams['alert_kind'] = $alertKind;
            }

            if ($alertSearch !== '') {
                $alertBaseParams['alert_search'] = $alertSearch;
            }

            $hasAlertFilters = $alertKind !== 'all' || $alertSearch !== '';
            $bulkReadLabel = $hasAlertFilters ? __('alerts.center.bulk_matching') : __('alerts.center.bulk_unread');
            $bulkReadHelp = $hasAlertFilters
                ? __('alerts.center.bulk_matching_help')
                : __('alerts.center.bulk_unread_help');
        @endphp

        <div class="section-header">
            <div>
                <h2 id="alert-center-heading">{{ __('alerts.center.heading') }}</h2>
                <p class="lede">{{ __('alerts.center.lede') }}</p>
            </div>
            <div class="section-actions">
                <nav class="wf-lanes" aria-label="{{ __('alerts.center.lanes') }}">
                @foreach (['all' => __('alerts.center.all'), 'unread' => __('alerts.center.unread_only')] as $filterValue => $filterLabel)
                    @php
                        $filterParams = [];

                        if ($filterValue === 'unread') {
                            $filterParams['alert_filter'] = 'unread';
                        }

                        $filterParams = array_merge($filterParams, $alertBaseParams);
                    @endphp
                    <a
                        class="wf-lane"
                        href="{{ route('dashboard.alerts.index', $filterParams) }}"
                        @if ($alertFilter === $filterValue) aria-current="page" @endif
                    >
                        {{ $filterLabel }}
                        @php $laneCount = $alertLaneCounts[$filterValue] ?? 0; @endphp
                        <span
                            class="wf-lane-count"
                            title="{{ $filterLabel }}: {{ $laneCount }}"
                            @if ($filterValue === 'unread' && $laneCount > 0) data-tone="waiting" @endif
                        >{{ $laneCount }}</span>
                    </a>
                @endforeach
                </nav>
                @if ($unreadNotificationCount > 0)
                    <form method="POST" action="{{ route('dashboard.alerts.read-all') }}">
                        @csrf
                        <input type="hidden" name="return_to" value="alerts">
                        @if ($alertFilter === 'unread')
                            <input type="hidden" name="alert_filter" value="unread">
                        @endif
                        @if ($alertKind !== 'all')
                            <input type="hidden" name="alert_kind" value="{{ $alertKind }}">
                        @endif
                        @if ($alertSearch !== '')
                            <input type="hidden" name="alert_search" value="{{ $alertSearch }}">
                        @endif
                        <button class="button secondary" type="submit" aria-describedby="alert-bulk-read-help">{{ $bulkReadLabel }}</button>
                        <span id="alert-bulk-read-help" class="table-note">{{ $bulkReadHelp }}</span>
                    </form>
                @endif
            </div>
        </div>

        <div class="notice-copy notice-copy-bordered" aria-labelledby="alert-delivery-context-heading">
            <p><strong id="alert-delivery-context-heading">{{ __('alerts.delivery.heading') }}</strong></p>
            <p>{{ $alertDeliveryContext['source_detail'] }}</p>
            <div class="meta-grid" aria-label="{{ __('alerts.delivery.region') }}">
                @foreach ($alertDeliveryContext['items'] as $deliveryContextItem)
                    <div class="meta-item">
                        <span class="meta-label">{{ $deliveryContextItem['label'] }}</span>
                        <span class="meta-value">{{ $deliveryContextItem['value'] }}</span>
                        <p class="field-help">{{ $deliveryContextItem['detail'] }}</p>
                    </div>
                @endforeach
            </div>
            <div class="notice-actions">
                <a class="button secondary" href="{{ $alertDeliveryContext['profile_href'] }}">{{ __('alerts.delivery.change_preferences') }}</a>
            </div>
        </div>

        <form class="wf-filters" method="GET" action="{{ route('dashboard.alerts.index') }}" aria-label="{{ __('alerts.filters.region') }}">
            @if ($alertFilter === 'unread')
                <input type="hidden" name="alert_filter" value="unread">
            @endif

            <div class="wf-filter wf-filter-search">
                <label for="alert_search">{{ __('alerts.filters.search_label') }}</label>
                <input
                    id="alert_search"
                    name="alert_search"
                    type="search"
                    value="{{ $alertSearch }}"
                    placeholder="{{ __('alerts.filters.search_placeholder') }}"
                    aria-describedby="alert-search-help"
                >
                <span id="alert-search-help" class="wf-filter-help">{{ __('alerts.filters.search_help') }}</span>
            </div>

            <div class="wf-filter">
                <label for="alert_kind">{{ __('alerts.filters.kind_label') }}</label>
                <select id="alert_kind" name="alert_kind">
                    @foreach (['all' => __('alerts.kinds.all'), 'conversation' => __('alerts.kinds.conversation'), 'ticket' => __('alerts.kinds.ticket')] as $kindValue => $kindLabel)
                        <option value="{{ $kindValue }}" @selected($alertKind === $kindValue)>{{ $kindLabel }}</option>
                    @endforeach
                </select>
            </div>

            <div class="wf-filter-actions">
                <button class="button" type="submit">{{ __('alerts.filters.apply') }}</button>
                @if ($hasAlertFilters)
                    <a class="button secondary" href="{{ route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []) }}">{{ __('alerts.filters.clear') }}</a>
                @endif
            </div>
        </form>

        @php
            $alertKindLabels = [
                'all' => __('alerts.kinds.all'),
                'conversation' => __('alerts.kinds.conversation'),
                'ticket' => __('alerts.kinds.ticket'),
            ];
            $alertFocusItems = [
                ['label' => __('alerts.focus.view'), 'value' => $alertFilter === 'unread' ? __('alerts.center.unread_only') : __('alerts.center.all'), 'authored' => false],
                ['label' => __('alerts.focus.type'), 'value' => $alertKindLabels[$alertKind], 'authored' => false],
                ['label' => __('alerts.focus.visible'), 'value' => trans_choice('alerts.counts.visible', $notificationCount, ['count' => $notificationCount]), 'authored' => false],
                ['label' => __('alerts.focus.unread'), 'value' => trans_choice('alerts.counts.unread', $unreadNotificationCount, ['count' => $unreadNotificationCount]), 'authored' => false],
            ];

            if ($alertSearch !== '') {
                $alertFocusItems[] = ['label' => __('alerts.focus.search'), 'value' => $alertSearch, 'authored' => true];
            }
        @endphp

        <div class="filter-summary" aria-label="{{ __('alerts.focus.region') }}">
            <div>
                <strong>{{ __('alerts.focus.heading') }}</strong>
                <p class="lede">{{ __('alerts.focus.detail') }}</p>
                <p class="lede">{{ $alertCountSummary['heading'] }}</p>
            </div>
            <div class="filter-chips">
                @foreach ($alertFocusItems as $alertFocusItem)
                    <span class="filter-chip">
                        {{ $alertFocusItem['label'] }}: <span @if ($alertFocusItem['authored']) lang="" @endif>{{ $alertFocusItem['value'] }}</span>
                    </span>
                @endforeach
            </div>
        </div>

        @if ($activeAlertFilters !== [])
            <div class="filter-summary" aria-label="{{ __('alerts.filters.active_region') }}">
                <div>
                    <strong>{{ __('alerts.filters.active_heading') }}</strong>
                    <p class="lede">{{ __('alerts.filters.active_detail') }}</p>
                </div>
                <div class="filter-chips">
                    @foreach ($activeAlertFilters as $activeFilter)
                        <a class="filter-chip" href="{{ $activeFilter['href'] }}">
                            <x-translated-feedback :feedback="$activeFilter['feedback']" />
                            <span aria-hidden="true">x</span>
                        </a>
                    @endforeach
                    <a class="filter-chip filter-chip-clear" href="{{ route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []) }}">{{ __('alerts.actions.clear_all_filters') }}</a>
                </div>
            </div>
        @endif

        <div class="meta-grid" aria-label="{{ __('alerts.snapshot.region') }}">
            @foreach ($alertSnapshot as $snapshotItem)
                <div class="meta-item">
                    <span class="meta-label">{{ $snapshotItem['label'] }}</span>
                    <span class="meta-value">{{ $snapshotItem['value'] }}</span>
                    <p class="field-help">{{ $snapshotItem['detail'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($notifications->isEmpty())
            <div class="empty empty-state">
                <strong><x-translated-feedback :feedback="$alertEmptyState['heading']" /></strong>
                <p>{{ $alertEmptyState['detail'] }}</p>
                <div class="empty-state-actions">
                    @foreach ($alertEmptyState['actions'] as $action)
                        <a class="button secondary" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endforeach
                </div>
            </div>
        @else
            <div class="notice-copy notice-copy-bordered">
                <p><strong>{{ $alertCountSummary['heading'] }}</strong></p>
                @if ($alertCountSummary['detail'])
                    <p>{{ $alertCountSummary['detail'] }}</p>
                @endif
                <p>{{ __('alerts.center.privacy') }}</p>
            </div>

            <div class="message-list">
                @foreach ($notifications as $notification)
                    @include('agent.partials.alert-card', [
                        'notification' => $notification,
                        'alertFilter' => $alertFilter,
                        'alertKind' => $alertKind,
                        'alertSearch' => $alertSearch,
                        'alertReturnTo' => 'alerts',
                    ])
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
