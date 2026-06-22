<x-layouts.app title="Agent Dashboard" :agent="$agent" :account="$account">
            <h1>{{ $account->name }}</h1>
            <p class="lede">Signed in as {{ $agent->email }}</p>

            <section class="section" aria-labelledby="support-code-lookup-heading">
                <div class="section-header">
                    <h2 id="support-code-lookup-heading">Find support trail</h2>
                    <span class="lede">Jump by support code, ticket reference, or visitor ID</span>
                </div>

                @if (session('support_code_lookup_status'))
                    <p class="empty">{{ session('support_code_lookup_status') }}</p>
                @endif

                <form class="section-form" method="GET" action="{{ route('dashboard.support-code.lookup') }}">
                    <div class="field">
                        <label for="support_code">Support code, ticket reference, or visitor ID</label>
                        <input id="support_code" name="support_code" type="search" value="{{ old('support_code') }}" placeholder="WF-ABC123, Ticket #123, or anon-visitor" autocomplete="off">
                        <p class="field-help">Open a visible conversation, ticket, or visitor profile from the reference the visitor gives you.</p>
                    </div>

                    <button class="button" type="submit">Find record</button>
                </form>
            </section>

            <section class="section" aria-labelledby="support-queues-heading">
                <div class="section-header">
                    <h2 id="support-queues-heading">Support queues</h2>
                    <span class="lede">Live work has its own room now</span>
                </div>

                <div class="management-list">
                    <a class="management-link" href="{{ route('dashboard.conversations.index') }}">
                        <span>
                            <strong>Conversations</strong>
                            <span class="lede">
                                {{ $supportQueues['open_conversations_count'] }} open
                                · {{ $supportQueues['new_activity_conversations_count'] }} {{ $supportQueues['new_activity_conversations_count'] === 1 ? 'needs' : 'need' }} attention
                                · {{ $supportQueues['cobrowse_attention_conversations_count'] === 1 ? '1 cobrowse session needs attention' : $supportQueues['cobrowse_attention_conversations_count'].' cobrowse sessions need attention' }}
                            </span>
                        </span>
                        <span class="management-action">Open queue</span>
                    </a>
                    <a class="management-link" href="{{ route('dashboard.tickets.index') }}">
                        <span>
                            <strong>Tickets</strong>
                            <span class="lede">
                                {{ $supportQueues['open_tickets_count'] }} open
                                · {{ $supportQueues['unassigned_tickets_count'] }} unassigned
                            </span>
                        </span>
                        <span class="management-action">Open queue</span>
                    </a>
                </div>
            </section>

            <section class="section" aria-labelledby="conversation-next-steps-heading">
                <div class="section-header">
                    <div>
                        <h2 id="conversation-next-steps-heading">Conversation next steps</h2>
                        <p class="lede">
                            {{ $conversationNextSteps['open_count'] }} open {{ \Illuminate\Support\Str::plural('conversation', $conversationNextSteps['open_count']) }} needing movement
                        </p>
                    </div>
                    <a class="button secondary" href="{{ $conversationNextSteps['queue_href'] }}">Open conversation queue</a>
                </div>

                @if ($conversationNextSteps['items'] === [])
                    <p class="empty">No open conversations need movement right now.</p>
                @else
                    <div class="management-list">
                        @foreach ($conversationNextSteps['items'] as $conversationNextStep)
                            <a class="management-link" href="{{ $conversationNextStep['href'] }}">
                                <span>
                                    <strong>{{ $conversationNextStep['title'] }}</strong>
                                    <span class="lede">
                                        {{ $conversationNextStep['count'].' '.\Illuminate\Support\Str::plural($conversationNextStep['label'], $conversationNextStep['count']) }}
                                    </span>
                                    <span class="table-note">{{ $conversationNextStep['detail'] }}</span>
                                </span>
                                <span class="management-action">{{ $conversationNextStep['action'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="ticket-next-steps-heading">
                <div class="section-header">
                    <div>
                        <h2 id="ticket-next-steps-heading">Ticket next steps</h2>
                        <p class="lede">
                            {{ $ticketNextSteps['open_count'] }} open {{ \Illuminate\Support\Str::plural('ticket', $ticketNextSteps['open_count']) }} needing movement
                        </p>
                    </div>
                    <a class="button secondary" href="{{ $ticketNextSteps['queue_href'] }}">Open ticket queue</a>
                </div>

                @if ($ticketNextSteps['items'] === [])
                    <p class="empty">No open tickets need movement right now.</p>
                @else
                    <div class="management-list">
                        @foreach ($ticketNextSteps['items'] as $ticketNextStep)
                            <a class="management-link" href="{{ $ticketNextStep['href'] }}">
                                <span>
                                    <strong>{{ $ticketNextStep['title'] }}</strong>
                                    <span class="lede">
                                        {{ $ticketNextStep['count'].' '.\Illuminate\Support\Str::plural($ticketNextStep['label'], $ticketNextStep['count']) }}
                                    </span>
                                    <span class="table-note">{{ $ticketNextStep['detail'] }}</span>
                                </span>
                                <span class="management-action">{{ $ticketNextStep['action'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="manage-heading">
                <div class="section-header">
                    <h2 id="manage-heading">Workspace shortcuts</h2>
                    <span class="lede">Common workspace paths</span>
                </div>

                <div class="management-list">
                    <a class="management-link" href="{{ route('dashboard.profile.show') }}">
                        <span>
                            <strong>Profile and alerts</strong>
                            <span class="lede">Update your name, password, and notification mode.</span>
                        </span>
                        <span class="management-action">Open</span>
                    </a>
                    <a class="management-link" href="{{ route('dashboard.sites.index') }}">
                        <span>
                            <strong>Sites and widget installs</strong>
                            <span class="lede">Review connected sites, install snippets, privacy rules, and support access.</span>
                        </span>
                        <span class="management-action">Open</span>
                    </a>
                    <a class="management-link" href="{{ route('dashboard.account.show') }}">
                        <span>
                            <strong>Account and team</strong>
                            <span class="lede">See account roles, team access, active agents, and support scope.</span>
                        </span>
                        <span class="management-action">Open</span>
                    </a>
                </div>
            </section>

            <section class="section" aria-labelledby="visitor-support-readiness-heading">
                <div class="section-header">
                    <div>
                        <h2 id="visitor-support-readiness-heading">Ready to support visitors?</h2>
                        <p class="lede">A practical dogfood checklist before real visitor pressure shows up.</p>
                    </div>
                    <div class="section-actions">
                        <span class="readiness-status" data-status="{{ $visitorSupportReadiness['attention_count'] > 0 ? 'attention' : 'ready' }}">
                            {{ $visitorSupportReadiness['label'] }}
                        </span>
                        <span class="lede">
                            {{ $visitorSupportReadiness['ready_count'] }} ready
                            · {{ $visitorSupportReadiness['attention_count'] }} {{ $visitorSupportReadiness['attention_count'] === 1 ? 'needs' : 'need' }} attention
                            · {{ $visitorSupportReadiness['manual_count'] }} {{ \Illuminate\Support\Str::plural('manual check', $visitorSupportReadiness['manual_count']) }}
                        </span>
                    </div>
                </div>

                <div class="readiness-list">
                    @foreach ($visitorSupportReadiness['checks'] as $check)
                        <article class="readiness-check" data-status="{{ $check['status'] }}">
                            <div class="readiness-check-main">
                                <div>
                                    <h3>{{ $check['label'] }}</h3>
                                    <p>{{ $check['summary'] }}</p>
                                </div>
                                <span class="readiness-status" data-status="{{ $check['status'] }}">
                                    {{ $check['status_label'] }}
                                </span>
                            </div>

                            <p class="lede">{{ $check['detail'] }}</p>
                            <p class="readiness-action">
                                @if ($check['href'])
                                    <a class="text-link" href="{{ $check['href'] }}">{{ $check['action'] }}</a>
                                @else
                                    {{ $check['action'] }}
                                @endif
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>

            @if ($adminShortcuts !== [])
                <section class="section" aria-labelledby="admin-command-center-heading">
                    <div class="section-header">
                        <h2 id="admin-command-center-heading">Admin command center</h2>
                        <span class="lede">Account controls for people with keys</span>
                    </div>

                    <div class="management-list">
                        @foreach ($adminShortcuts as $adminShortcut)
                            <a class="management-link" href="{{ $adminShortcut['href'] }}">
                                <span>
                                    <strong>{{ $adminShortcut['label'] }}</strong>
                                    <span class="lede">{{ $adminShortcut['description'] }}</span>
                                </span>
                                <span class="management-action">{{ $adminShortcut['action'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="section" aria-labelledby="data-responsibility-heading">
                <div class="section-header">
                    <h2 id="data-responsibility-heading">Data responsibility</h2>
                    <span class="lede">{{ $dataResponsibility['label'] }}</span>
                </div>

                <div class="notice-copy">
                    <p>{{ $dataResponsibility['message'] }}</p>
                    <p>{{ $dataResponsibility['guidance'] }}</p>
                    <p>
                        <a class="text-link" href="{{ $dataResponsibility['docs_url'] }}" target="_blank" rel="noreferrer">
                            Review the data responsibility docs
                        </a>
                    </p>
                </div>
            </section>

            <section id="alerts" class="section" aria-labelledby="alerts-heading">
                <div class="section-header">
                    <h2 id="alerts-heading">Alerts</h2>
                    <div class="section-actions">
                        <span class="lede">{{ $unreadNotificationCount }} unread</span>
                        <a class="button secondary" href="{{ route('dashboard.alerts.index') }}">Open alert center</a>
                        @if ($unreadNotificationCount > 0)
                            <form method="POST" action="{{ route('dashboard.alerts.read-all') }}">
                                @csrf
                                <button class="button secondary" type="submit">Mark all read</button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($unreadNotifications->isEmpty())
                    <p class="empty">You’re caught up.</p>
                @else
                    @php
                        $hiddenUnreadNotificationCount = max(0, $unreadNotificationCount - $unreadNotifications->count());
                    @endphp
                    @if ($hiddenUnreadNotificationCount > 0)
                        <div class="notice-copy notice-copy-bordered">
                            <p><strong>Showing {{ $unreadNotifications->count() }} latest alerts</strong></p>
                            <p>{{ $hiddenUnreadNotificationCount }} more unread {{ \Illuminate\Support\Str::plural('alert', $hiddenUnreadNotificationCount) }} are waiting outside this panel.</p>
                            <p>Open the linked queue items or mark alerts read once handled.</p>
                            <p><a class="text-link" href="{{ route('dashboard.alerts.index') }}">Open alert center</a></p>
                        </div>
                    @endif

                    <div class="message-list">
                        @foreach ($unreadNotifications as $notification)
                            @include('agent.partials.alert-card', ['notification' => $notification])
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="team-heading">
                <div class="section-header">
                    <h2 id="team-heading">Team</h2>
                    <span class="lede">{{ $agents->count() }} active</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Open conversations</th>
                                <th scope="col">Open tickets</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agents as $accountAgent)
                                <tr>
                                    <td>{{ $accountAgent->name }}</td>
                                    <td>{{ $accountAgent->email }}</td>
                                    <td>{{ $accountAgent->open_assigned_conversations_count }}</td>
                                    <td>{{ $accountAgent->open_assigned_tickets_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="sites" class="section" aria-labelledby="sites-heading">
                @php
                    $sitesNeedingInstallAttention = $sites
                        ->filter(fn ($site) => \App\Support\SiteInstallHealth::fromVisitor($site->latestVisitor)['needs_attention'])
                        ->count();
                    $installAttentionSummary = $sitesNeedingInstallAttention === 1
                        ? '1 site needs setup attention.'
                        : $sitesNeedingInstallAttention.' sites need setup attention.';
                @endphp

                <div class="section-header">
                    <h2 id="sites-heading">Sites</h2>
                    <div class="section-actions">
                        <span class="lede">{{ $sites->count() }} active</span>
                        <a class="button secondary" href="{{ route('dashboard.sites.create') }}">Add site</a>
                    </div>
                </div>

                @if ($sites->isEmpty())
                    <p class="empty">No sites have been connected yet.</p>
                @else
                    @if ($sitesNeedingInstallAttention > 0)
                        <div class="notice-copy notice-copy-bordered">
                            <p><strong>{{ $installAttentionSummary }}</strong></p>
                            <p>Review stale or missing widget check-ins before you send real visitors there.</p>
                            <p>
                                <a class="text-link" href="{{ route('dashboard.sites.index') }}#site-install-health">
                                    Review site installs
                                </a>
                            </p>
                        </div>
                    @endif

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Name</th>
                                    <th scope="col">Domain</th>
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
                                    @endphp
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.sites.show', $site) }}">
                                                {{ $site->name }}
                                            </a>
                                        </td>
                                        <td>{{ $site->domain ?? 'Not set' }}</td>
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

            <section class="section" aria-labelledby="realtime-heading">
                <div class="section-header">
                    <h2 id="realtime-heading">Realtime</h2>
                    <span class="lede">{{ $realtimeHealth['label'] }}</span>
                </div>

                <div class="meta-grid realtime-grid">
                    <div class="meta-item">
                        <span class="meta-label">Broadcast driver</span>
                        <span class="meta-value">{{ $realtimeHealth['driver'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Endpoint</span>
                        <span class="meta-value">{{ $realtimeHealth['endpoint'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Scheme</span>
                        <span class="meta-value">{{ $realtimeHealth['scheme'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">App ID</span>
                        <span class="meta-value">{{ $realtimeHealth['has_app_id'] ? 'Set' : 'Missing' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">App key</span>
                        <span class="meta-value">{{ $realtimeHealth['has_app_key'] ? 'Set' : 'Missing' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Secret</span>
                        <span class="meta-value">{{ $realtimeHealth['has_app_secret'] ? 'Set' : 'Missing' }}</span>
                    </div>
                </div>

                <p class="empty realtime-note">{{ $realtimeHealth['message'] }}</p>
            </section>
</x-layouts.app>
