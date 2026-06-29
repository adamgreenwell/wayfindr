<x-layouts.app title="Sites" :agent="$agent" :account="$account">
            <x-page-header title="Sites" subtitle="Manage widget installs, support access, privacy rules, and issue routing.">
                <x-slot:actions>
                    <span class="lede">{{ $siteFilters['summary_label'] }}</span>
                    <a class="button secondary" href="{{ route('dashboard.sites.create') }}">Add site</a>
                </x-slot:actions>
            </x-page-header>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="site-operations-snapshot-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-operations-snapshot-heading">Site operations snapshot</h2>
                        <p class="lede">A quick read on the sites your support role can currently reach.</p>
                    </div>
                </div>

                <div class="meta-grid" aria-label="Site operations metrics">
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
                        <h2 id="site-filters-heading">Site filters</h2>
                        <p class="lede">Narrow connected sites by support work, install health, or name.</p>
                    </div>
                    @if ($siteFilters['has_active_filters'])
                        <a class="button secondary" href="{{ route('dashboard.sites.index') }}">Clear filters</a>
                    @endif
                </div>

                <form class="section-form" method="GET" action="{{ route('dashboard.sites.index') }}">
                    <div class="meta-grid">
                        <div class="meta-item">
                            <label class="meta-label" for="site_search">Search</label>
                            <input id="site_search" name="site_search" type="search" value="{{ $siteFilters['search'] }}" placeholder="Site name or domain" autocomplete="off">
                        </div>
                        <div class="meta-item">
                            <label class="meta-label" for="site_workload">Workload</label>
                            <select id="site_workload" name="site_workload">
                                @foreach ($siteFilters['workload_options'] as $value => $label)
                                    <option value="{{ $value }}" @selected($siteFilters['workload'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="meta-item">
                            <label class="meta-label" for="site_install">Install health</label>
                            <select id="site_install" name="site_install">
                                @foreach ($siteFilters['install_options'] as $value => $label)
                                    <option value="{{ $value }}" @selected($siteFilters['install'] === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Results</span>
                            <span class="meta-value">{{ $siteFilters['summary_label'] }}</span>
                            <button class="button secondary" type="submit">Apply filters</button>
                        </div>
                    </div>
                </form>

                <div class="filter-summary" aria-label="Active site filters">
                    <div>
                        <strong>{{ $siteFilters['has_active_filters'] ? 'Filtered sites' : 'All visible sites' }}</strong>
                        <p class="lede">{{ $siteFilters['summary_label'] }}</p>
                    </div>
                    <div class="filter-chips">
                        @forelse ($siteFilters['active'] as $filter)
                            <span class="filter-chip">{{ $filter['label'] }}: {{ $filter['value'] }}</span>
                        @empty
                            <span class="filter-chip">No filters applied</span>
                        @endforelse
                    </div>
                </div>
            </section>

            <section id="site-install-health" class="section" aria-labelledby="sites-heading">
                <div class="section-header">
                    <h2 id="sites-heading">Connected sites</h2>
                    <span class="lede">Visible to your support role</span>
                </div>

                @if ($sites->isEmpty())
                    <div class="empty empty-state">
                        <strong>{{ $siteEmptyState['heading'] }}</strong>
                        <p>{{ $siteEmptyState['detail'] }}</p>
                        <div class="empty-state-actions">
                            @foreach ($siteEmptyState['actions'] as $action)
                                <a class="button secondary" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Site</th>
                                    <th scope="col">Workload</th>
                                    <th scope="col">Access</th>
                                    <th scope="col">Install health</th>
                                    <th scope="col">Last page</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sites as $site)
                                    @php
                                        $latestVisitor = $site->latestVisitor;
                                        $installHealth = \App\Support\SiteInstallHealth::fromVisitor($latestVisitor);
                                        $lastPageUrl = data_get($latestVisitor?->metadata, 'last_page_url');
                                        $openConversationCount = (int) $site->open_conversations_count;
                                        $openTicketCount = (int) $site->open_tickets_count;
                                        $pendingTicketCount = (int) $site->pending_tickets_count;
                                        $hasWorkload = $openConversationCount > 0 || $openTicketCount > 0 || $pendingTicketCount > 0;
                                        $supportAgentCount = (int) $site->support_agents_count;
                                        $supportAgentNames = $site->supportAgents->pluck('name')->values();
                                        $supportCoverageSummary = $supportAgentNames
                                            ->take(3)
                                            ->join(', ');

                                        if ($supportAgentNames->count() > 3) {
                                            $supportCoverageSummary .= ' + '.($supportAgentNames->count() - 3).' more';
                                        }
                                    @endphp
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.sites.show', $site) }}">{{ $site->name }}</a>
                                            <span class="lede">{{ $site->domain ?? 'Not set' }}</span>
                                            <div class="lede"><a class="text-link" href="{{ route('dashboard.sites.tester', $site) }}">Open tester</a></div>
                                        </td>
                                        <td>
                                            @if ($hasWorkload)
                                                @if ($openConversationCount > 0)
                                                    <a class="text-link" href="{{ route('dashboard.conversations.index', ['conversation_site' => $site->id]) }}">
                                                        {{ $openConversationCount }} open {{ \Illuminate\Support\Str::plural('conversation', $openConversationCount) }}
                                                    </a>
                                                @endif
                                                @if ($openTicketCount > 0)
                                                    <a class="table-note text-link" href="{{ route('dashboard.tickets.index', ['ticket_site' => $site->id]) }}">
                                                        {{ $openTicketCount }} open {{ \Illuminate\Support\Str::plural('ticket', $openTicketCount) }}
                                                    </a>
                                                @endif
                                                @if ($pendingTicketCount > 0)
                                                    <a class="table-note text-link" href="{{ route('dashboard.tickets.index', ['ticket_status' => 'pending', 'ticket_site' => $site->id]) }}">
                                                        {{ $pendingTicketCount }} pending {{ \Illuminate\Support\Str::plural('ticket', $pendingTicketCount) }}
                                                    </a>
                                                @endif
                                            @else
                                                <span class="lede">No active support work</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($supportAgentCount > 0)
                                                <strong>Explicit access</strong>
                                                <span class="lede">{{ $supportAgentCount }} assigned</span>
                                                <span class="table-note">Assigned support</span>
                                                <span>{{ $supportCoverageSummary }}</span>
                                            @else
                                                <strong>Account-wide fallback</strong>
                                                <span class="lede">All account agents</span>
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
                                        <td>{{ $lastPageUrl ?: 'Not reported' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
