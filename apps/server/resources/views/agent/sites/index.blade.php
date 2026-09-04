<x-layouts.app :title="__('sites.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('sites.title')" :subtitle="__('sites.subtitle')">
        <x-slot:actions>
            <span class="lede">{{ $siteFilters['summary_label'] }}</span>
            @if ($canCreateSite)
                <a class="button secondary" href="{{ route('dashboard.sites.create') }}">{{ __('sites.add_site') }}</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($siteStatusFeedback)
        <p class="status-message">
            @if (is_array($siteStatusFeedback))
                <x-translated-feedback :feedback="$siteStatusFeedback" />
            @else
                {{ __($siteStatusFeedback) }}
            @endif
        </p>
    @endif

    <section class="section" aria-labelledby="site-operations-snapshot-heading">
        <div class="section-header">
            <div>
                <h2 id="site-operations-snapshot-heading">{{ __('sites.index.snapshot.heading') }}</h2>
                <p class="lede">{{ __('sites.index.snapshot.lede') }}</p>
            </div>
        </div>

        <div class="meta-grid" aria-label="{{ __('sites.index.snapshot.aria') }}">
            @foreach ($siteOperationsSnapshot as $snapshotItem)
                <div class="meta-item">
                    <span class="meta-label">{{ $snapshotItem['label'] }}</span>
                    <span class="meta-value">{{ $snapshotItem['value'] }}</span>
                    <p class="lede">{{ $snapshotItem['detail'] }}</p>
                    @if ($snapshotItem['href'] && $snapshotItem['action'])
                        <a class="text-link table-note" href="{{ $snapshotItem['href'] }}">{{ $snapshotItem['action'] }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="section" aria-labelledby="site-filters-heading">
        <div class="section-header">
            <div>
                <h2 id="site-filters-heading">{{ __('sites.index.filters.heading') }}</h2>
                <p class="lede">{{ __('sites.index.filters.lede') }}</p>
            </div>
            @if ($siteFilters['has_active_filters'])
                <a class="button secondary" href="{{ route('dashboard.sites.index') }}">{{ __('sites.index.filters.clear') }}</a>
            @endif
        </div>

        <form class="wf-filters" method="GET" action="{{ route('dashboard.sites.index') }}">
            <div class="wf-filter wf-filter-search">
                <label for="site_search">{{ __('sites.index.filters.search') }}</label>
                <input
                    id="site_search"
                    name="site_search"
                    type="search"
                    value="{{ $siteFilters['search'] }}"
                    placeholder="{{ __('sites.index.filters.placeholder') }}"
                    autocomplete="off"
                    @if ($siteFilters['search'] !== '') lang="" @endif
                >
            </div>

            @foreach ([
                ['id' => 'site_workload', 'label' => __('sites.index.filters.workload'), 'options' => $siteFilters['workload_options'], 'selected' => $siteFilters['workload'], 'requires_support' => true],
                ['id' => 'site_install', 'label' => __('sites.index.filters.install_health'), 'options' => $siteFilters['install_options'], 'selected' => $siteFilters['install']],
                ['id' => 'site_state', 'label' => __('sites.index.filters.state'), 'options' => $siteFilters['state_options'], 'selected' => $siteFilters['state']],
            ] as $siteSelectFilter)
                @continue(($siteSelectFilter['requires_support'] ?? false) && ! $canViewSupportWork)
                <div class="wf-filter">
                    <label for="{{ $siteSelectFilter['id'] }}">{{ $siteSelectFilter['label'] }}</label>
                    <select id="{{ $siteSelectFilter['id'] }}" name="{{ $siteSelectFilter['id'] }}">
                        @foreach ($siteSelectFilter['options'] as $value => $label)
                            <option value="{{ $value }}" @selected($siteSelectFilter['selected'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endforeach

            <div class="wf-filter-actions">
                <button class="button" type="submit">{{ __('sites.index.filters.apply') }}</button>
                <span class="wf-filter-help">{{ $siteFilters['summary_label'] }}</span>
            </div>
        </form>

        <div class="filter-summary" aria-label="{{ __('sites.index.filters.active_aria') }}">
            <div>
                <strong>{{ $siteFilters['has_active_filters'] ? __('sites.index.filters.filtered') : __('sites.index.filters.all_visible') }}</strong>
                <p class="lede">{{ $siteFilters['summary_label'] }}</p>
            </div>
            <div class="filter-chips">
                @forelse ($siteFilters['active'] as $filter)
                    <span class="filter-chip">
                        {{ $filter['label'] }}:
                        @if ($filter['value_is_authored'])
                            <span lang="">{{ $filter['value'] }}</span>
                        @else
                            {{ $filter['value'] }}
                        @endif
                    </span>
                @empty
                    <span class="filter-chip">{{ __('sites.index.filters.none') }}</span>
                @endforelse
            </div>
        </div>
    </section>

    <section id="site-install-health" class="section" aria-labelledby="sites-heading">
        <div class="section-header">
            <h2 id="sites-heading">{{ __('sites.index.list.heading') }}</h2>
            <span class="lede">{{ __('sites.index.list.lede') }}</span>
        </div>

        @if ($sites->isEmpty())
            <div class="empty empty-state">
                <strong><x-translated-feedback :feedback="$siteEmptyState['heading']" /></strong>
                <p>{{ $siteEmptyState['detail'] }}</p>
                <div class="empty-state-actions">
                    @foreach ($siteEmptyState['actions'] as $action)
                        <a class="button secondary" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                    @endforeach
                </div>
            </div>
        @else
            <div class="table-wrap">
                <table class="wf-queue">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('sites.index.list.columns.site') }}</th>
                            @if ($canViewSupportWork)
                                <th scope="col">{{ __('sites.index.list.columns.workload') }}</th>
                            @endif
                            <th scope="col">{{ __('sites.index.list.columns.access') }}</th>
                            <th scope="col">{{ __('sites.index.list.columns.install_health') }}</th>
                            @if ($canViewSupportWork)
                                <th scope="col">{{ __('sites.index.list.columns.last_page') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            @php
                                $latestVisitor = $site->latestVisitor;
                                $installHealth = $siteInstallHealth[$site->id];
                                $lastPageUrl = $canViewSupportWork
                                    ? data_get($latestVisitor?->metadata, 'last_page_url')
                                    : null;
                                $openConversationCount = (int) $site->open_conversations_count;
                                $openTicketCount = (int) $site->open_tickets_count;
                                $pendingTicketCount = (int) $site->pending_tickets_count;
                                $hasWorkload = ($canViewConversations && $openConversationCount > 0)
                                    || ($canManageTickets && ($openTicketCount > 0 || $pendingTicketCount > 0));
                                $supportAgentCount = (int) $site->support_agents_count;
                                $supportAgentNames = $site->supportAgents->pluck('name')->values();
                            @endphp
                            <tr>
                                <td class="wf-queue-subject" style="--wf-row-site: var({{ $site->resolvedColor()->cssVariable() }})">
                                    <a href="{{ route('dashboard.sites.show', $site) }}" lang="">{{ $site->name }}</a>
                                    @if ($site->isArchived())
                                        <span class="readiness-status">{{ __('sites.index.state.archived') }}</span>
                                    @endif
                                    <span class="lede">
                                        @if ($site->domain)
                                            <span lang="">{{ $site->domain }}</span>
                                        @else
                                            {{ __('sites.index.common.not_set') }}
                                        @endif
                                    </span>
                                    <div class="lede"><a class="text-link" href="{{ route('dashboard.sites.tester', $site) }}">{{ __('sites.index.list.open_tester') }}</a></div>
                                </td>
                                @if ($canViewSupportWork)
                                    <td>
                                    @if ($hasWorkload)
                                        @if ($canViewConversations && $openConversationCount > 0)
                                            <a class="text-link" href="{{ route('dashboard.conversations.index', ['conversation_site' => $site->id]) }}">
                                                {{ trans_choice('sites.index.counts.open_conversations', $openConversationCount, ['count' => \App\Support\ReaderNumber::count($openConversationCount)]) }}
                                            </a>
                                        @endif
                                        @if ($canManageTickets && $openTicketCount > 0)
                                            <a class="table-note text-link" href="{{ route('dashboard.tickets.index', ['ticket_site' => $site->id]) }}">
                                                {{ trans_choice('sites.index.counts.open_tickets', $openTicketCount, ['count' => \App\Support\ReaderNumber::count($openTicketCount)]) }}
                                            </a>
                                        @endif
                                        @if ($canManageTickets && $pendingTicketCount > 0)
                                            <a class="table-note text-link" href="{{ route('dashboard.tickets.index', ['ticket_status' => 'pending', 'ticket_site' => $site->id]) }}">
                                                {{ trans_choice('sites.index.counts.pending_tickets', $pendingTicketCount, ['count' => \App\Support\ReaderNumber::count($pendingTicketCount)]) }}
                                            </a>
                                        @endif
                                    @else
                                        <span class="lede">{{ __('sites.index.workload.none') }}</span>
                                    @endif
                                    </td>
                                @endif
                                <td>
                                    @if ($supportAgentCount > 0)
                                        <strong>{{ __('sites.index.access.explicit') }}</strong>
                                        <span class="lede">{{ trans_choice('sites.index.counts.assigned', $supportAgentCount, ['count' => \App\Support\ReaderNumber::count($supportAgentCount)]) }}</span>
                                        <span class="table-note">{{ __('sites.index.access.assigned_support') }}</span>
                                        <span>
                                            @foreach ($supportAgentNames->take(3) as $supportAgentName)
                                                @if (! $loop->first), @endif<span lang="">{{ $supportAgentName }}</span>
                                            @endforeach
                                            @if ($supportAgentNames->count() > 3)
                                                {{ trans_choice('sites.index.counts.more', $supportAgentNames->count() - 3, ['count' => \App\Support\ReaderNumber::count($supportAgentNames->count() - 3)]) }}
                                            @endif
                                        </span>
                                    @else
                                        <strong>{{ __('sites.index.access.fallback') }}</strong>
                                        <span class="lede">{{ __('sites.index.access.all_agents') }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="readiness-status" data-status="{{ $installHealth['tone'] }}">{{ $installHealth['label'] }}</span>
                                    <div class="lede">{{ $installHealth['detail'] }}</div>
                                    @if ($installHealth['needs_attention'])
                                        <a class="health-action text-link" href="{{ route('dashboard.sites.show', $site) }}#install-verification">
                                            {{ $installHealth['action_label'] }}
                                        </a>
                                    @endif
                                </td>
                                @if ($canViewSupportWork)
                                    <td>
                                        @if ($lastPageUrl)
                                            <span lang="">{{ $lastPageUrl }}</span>
                                        @else
                                            {{ __('sites.index.common.not_reported') }}
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
