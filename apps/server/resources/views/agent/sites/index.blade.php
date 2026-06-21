<x-layouts.app title="Sites" :agent="$agent" :account="$account">
            <div class="section-header">
                <div>
                    <h1>Sites</h1>
                    <p class="lede">Manage widget installs, support access, privacy rules, and issue routing.</p>
                </div>
                <div class="section-actions">
                    <span class="lede">{{ $sites->count() }} visible</span>
                    <a class="button secondary" href="{{ route('dashboard.sites.create') }}">Add site</a>
                </div>
            </div>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section id="site-install-health" class="section" aria-labelledby="sites-heading">
                <div class="section-header">
                    <h2 id="sites-heading">Connected sites</h2>
                    <span class="lede">Visible to your support role</span>
                </div>

                @if ($sites->isEmpty())
                    <div class="notice-copy">
                        <p>No sites are visible to you yet. Add the first site to get a public key and widget install snippet.</p>
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
