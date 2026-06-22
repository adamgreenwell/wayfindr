<x-layouts.app title="Alerts" :agent="$agent" :account="$account">
    <p><a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a></p>
    <h1>Alert center</h1>
    <p class="lede">Visible support alerts for {{ $account->name }}.</p>

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
            $bulkReadLabel = $hasAlertFilters ? 'Mark matching read' : 'Mark unread alerts read';
            $bulkReadHelp = $hasAlertFilters
                ? 'All unread alerts matching this view, including alerts outside the current display, will be marked read.'
                : 'All unread alerts you can still access, including alerts outside the current display, will be marked read.';
        @endphp

        <div class="section-header">
            <div>
                <h2 id="alert-center-heading">Recent alerts</h2>
                <p class="lede">Unread alerts stay here until the related work is opened or marked read.</p>
            </div>
            <div class="section-actions">
                @foreach (['all' => 'All alerts', 'unread' => 'Unread only'] as $filterValue => $filterLabel)
                    @php
                        $filterParams = [];

                        if ($filterValue === 'unread') {
                            $filterParams['alert_filter'] = 'unread';
                        }

                        $filterParams = array_merge($filterParams, $alertBaseParams);
                    @endphp
                    <a
                        class="button {{ $alertFilter === $filterValue ? '' : 'secondary' }}"
                        href="{{ route('dashboard.alerts.index', $filterParams) }}"
                        @if ($alertFilter === $filterValue) aria-current="page" @endif
                    >
                        {{ $filterLabel }}
                    </a>
                @endforeach
                <span class="lede">{{ $notificationCount }} visible</span>
                <span class="lede">{{ $unreadNotificationCount }} unread</span>
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

        <form class="section-form compact-form" method="GET" action="{{ route('dashboard.alerts.index') }}" aria-label="Filter alerts">
            @if ($alertFilter === 'unread')
                <input type="hidden" name="alert_filter" value="unread">
            @endif

            <label class="meta-label" for="alert_kind">Alert type</label>
            <select id="alert_kind" name="alert_kind">
                @foreach (['all' => 'All alerts', 'conversation' => 'Conversation alerts', 'ticket' => 'Ticket alerts'] as $kindValue => $kindLabel)
                    <option value="{{ $kindValue }}" @selected($alertKind === $kindValue)>{{ $kindLabel }}</option>
                @endforeach
            </select>

            <label class="meta-label" for="alert_search">Search alerts</label>
            <input
                id="alert_search"
                name="alert_search"
                type="search"
                value="{{ $alertSearch }}"
                placeholder="Support code, ticket #, subject, site, or visitor"
                aria-describedby="alert-search-help"
            >

            <button class="button secondary" type="submit">Apply</button>
            @if ($hasAlertFilters)
                <a class="button secondary" href="{{ route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []) }}">Clear filters</a>
            @endif

            <span id="alert-search-help" class="table-note">Search visible alert context only; restricted support work stays hidden.</span>
        </form>

        @if ($activeAlertFilters !== [])
            <div class="filter-summary" aria-label="Active alert filters">
                <div>
                    <strong>Active alert filters</strong>
                    <p class="lede">Alerts narrowed to the support work matching this view.</p>
                </div>
                <div class="filter-chips">
                    @foreach ($activeAlertFilters as $activeFilter)
                        <a class="filter-chip" href="{{ $activeFilter['href'] }}">
                            {{ $activeFilter['label'] }}
                            <span aria-hidden="true">x</span>
                        </a>
                    @endforeach
                    <a class="filter-chip filter-chip-clear" href="{{ route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []) }}">Clear all alert filters</a>
                </div>
            </div>
        @endif

        <div class="meta-grid" aria-label="Alert snapshot">
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
                <strong>{{ $alertEmptyState['heading'] }}</strong>
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
                <p>Alerts you can no longer access are hidden so old notifications do not leak restricted support work.</p>
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
