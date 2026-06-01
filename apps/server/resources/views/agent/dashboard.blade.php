<x-layouts.app title="Agent Dashboard" :agent="$agent" :account="$account">
            <h1>{{ $account->name }}</h1>
            <p class="lede">Signed in as {{ $agent->email }}</p>

            <section class="section" aria-labelledby="support-code-lookup-heading">
                <div class="section-header">
                    <h2 id="support-code-lookup-heading">Find support record</h2>
                    <span class="lede">Jump by support code</span>
                </div>

                @if (session('support_code_lookup_status'))
                    <p class="empty">{{ session('support_code_lookup_status') }}</p>
                @endif

                <form class="section-form" method="GET" action="{{ route('dashboard.support-code.lookup') }}">
                    <div class="field">
                        <label for="support_code">Support code</label>
                        <input id="support_code" name="support_code" type="search" value="{{ old('support_code') }}" placeholder="WF-ABC123" autocomplete="off">
                        <p class="field-help">Use the visitor's support code to open a visible conversation or linked ticket.</p>
                    </div>

                    <button class="button" type="submit">Find record</button>
                </form>
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

            <section class="section" aria-labelledby="alerts-heading">
                <div class="section-header">
                    <h2 id="alerts-heading">Alerts</h2>
                    <div class="section-actions">
                        <span class="lede">{{ $unreadNotificationCount }} unread</span>
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
                    <div class="message-list">
                        @foreach ($unreadNotifications as $notification)
                            @php
                                $notificationData = $notification->data;
                                $notificationKind = data_get($notificationData, 'kind');
                                $messageCount = max(1, (int) data_get($notificationData, 'message_count', 1));
                            @endphp
                            <article class="message">
                                <div class="message-meta">
                                    <strong>{{ data_get($notificationData, 'subject', $notificationKind === 'ticket_assigned' ? 'Untitled ticket' : 'Untitled conversation') }}</strong>
                                    <span>{{ $notification->created_at->diffForHumans() }}</span>
                                </div>
                                @if ($notificationKind === 'ticket_assigned')
                                    <p class="lede">Ticket assigned</p>
                                    <p class="message-body">{{ data_get($notificationData, 'assigned_by_name', 'Someone') }} assigned this ticket to you.</p>
                                    <p class="lede">
                                        <a class="text-link" href="{{ data_get($notificationData, 'url') }}">
                                            Ticket #{{ data_get($notificationData, 'ticket_id') }}
                                        </a>
                                        on {{ data_get($notificationData, 'site_name', 'Unknown site') }}
                                        · {{ ucfirst((string) data_get($notificationData, 'priority', 'normal')) }} priority
                                    </p>
                                @else
                                    <p class="lede">
                                        {{ $messageCount === 1 ? '1 new message' : $messageCount.' new messages' }}
                                    </p>
                                    <p class="message-body">{{ data_get($notificationData, 'message_preview') }}</p>
                                    <p class="lede">
                                        <a class="text-link" href="{{ data_get($notificationData, 'url') }}">
                                            {{ data_get($notificationData, 'support_code') }}
                                        </a>
                                        on {{ data_get($notificationData, 'site_name', 'Unknown site') }}
                                    </p>
                                @endif
                                <form method="POST" action="{{ route('dashboard.alerts.read', $notification) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Mark read</button>
                                </form>
                            </article>
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

            <section id="tickets" class="section" aria-labelledby="tickets-heading">
                <div class="section-header">
                    <h2 id="tickets-heading">Tickets</h2>
                    <div class="section-actions">
                        <span class="lede">{{ $tickets->count() }} {{ $ticketStatusSummary }}</span>
                        @foreach ($ticketStatusFilters as $filterValue => $filterLabel)
                            @php
                                $statusParams = $ticketQuery;

                                if ($filterValue === 'open') {
                                    unset($statusParams['ticket_status']);
                                } else {
                                    $statusParams['ticket_status'] = $filterValue;
                                }
                            @endphp
                            <a
                                class="button {{ $ticketStatus === $filterValue ? '' : 'secondary' }}"
                                href="{{ route('dashboard', $statusParams) }}"
                                @if ($ticketStatus === $filterValue) aria-current="page" @endif
                            >
                                {{ $filterLabel }}
                            </a>
                        @endforeach
                        @foreach ($ticketFilters as $filterValue => $filterLabel)
                            @php
                                $ownerParams = $ticketQuery;

                                if ($filterValue === 'all') {
                                    unset($ownerParams['ticket_filter']);
                                } else {
                                    $ownerParams['ticket_filter'] = $filterValue;
                                }
                            @endphp
                            <a
                                class="button {{ $ticketFilter === $filterValue ? '' : 'secondary' }}"
                                href="{{ route('dashboard', $ownerParams) }}"
                                @if ($ticketFilter === $filterValue) aria-current="page" @endif
                            >
                                {{ $filterLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <form class="section-form" method="GET" action="{{ route('dashboard') }}">
                    @if ($ticketStatus !== 'open')
                        <input type="hidden" name="ticket_status" value="{{ $ticketStatus }}">
                    @endif

                    @if ($ticketFilter !== 'all')
                        <input type="hidden" name="ticket_filter" value="{{ $ticketFilter }}">
                    @endif

                    <div class="meta-grid">
                        <div class="meta-item">
                            <label class="meta-label" for="ticket_site">Site</label>
                            <select id="ticket_site" name="ticket_site">
                                <option value="">Any site</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}" @selected($ticketSite === $site->id)>
                                        {{ $site->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="ticket_priority">Priority</label>
                            <select id="ticket_priority" name="ticket_priority">
                                @foreach ($ticketPriorityFilters as $filterValue => $filterLabel)
                                    <option value="{{ $filterValue }}" @selected($ticketPriority === $filterValue)>
                                        {{ $filterLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="ticket_category">Category</label>
                            <select id="ticket_category" name="ticket_category">
                                @foreach ($ticketCategoryFilters as $filterValue => $filterLabel)
                                    <option value="{{ $filterValue }}" @selected($ticketCategory === $filterValue)>
                                        {{ $filterLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="ticket_label">Label</label>
                            <select id="ticket_label" name="ticket_label">
                                @foreach ($ticketLabelFilters as $filterValue => $filterLabel)
                                    <option value="{{ $filterValue }}" @selected($ticketLabel === $filterValue)>
                                        {{ $filterLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="ticket_attention">Next step</label>
                            <select id="ticket_attention" name="ticket_attention">
                                @foreach ($ticketAttentionFilters as $filterValue => $filterLabel)
                                    <option value="{{ $filterValue }}" @selected($ticketAttention === $filterValue)>
                                        {{ $filterLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="ticket_search">Search</label>
                            <input id="ticket_search" name="ticket_search" type="search" value="{{ $ticketSearch }}">
                        </div>

                        <div class="meta-item">
                            <span class="meta-label">Queue</span>
                            <button class="button" type="submit">Apply filters</button>
                            @php
                                $clearParams = $ticketQuery;
                                unset($clearParams['ticket_site'], $clearParams['ticket_priority'], $clearParams['ticket_category'], $clearParams['ticket_label'], $clearParams['ticket_attention'], $clearParams['ticket_search']);
                            @endphp
                            <a class="button secondary" href="{{ route('dashboard', $clearParams) }}">Clear filters</a>
                        </div>
                    </div>
                </form>

                @if ($ticketActiveFilters !== [])
                    <div class="filter-summary" aria-label="Active ticket filters">
                        <div>
                            <strong>Active ticket filters</strong>
                            <p class="lede">Queue narrowed to what matches this view.</p>
                        </div>
                        <div class="filter-chips">
                            @foreach ($ticketActiveFilters as $activeFilter)
                                <a class="filter-chip" href="{{ $activeFilter['href'] }}">
                                    {{ $activeFilter['label'] }}
                                    <span aria-hidden="true">x</span>
                                </a>
                            @endforeach
                            <a class="filter-chip filter-chip-clear" href="{{ route('dashboard') }}#tickets">Clear all ticket filters</a>
                        </div>
                    </div>
                @endif

                @if ($tickets->isEmpty())
                    <p class="empty">{{ $ticketEmptyMessage }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Site</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Labels</th>
                                    <th scope="col">Priority</th>
                                    <th scope="col">Assignee</th>
                                    <th scope="col">Next step</th>
                                    <th scope="col">Support Code</th>
                                    <th scope="col">Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.tickets.show', $ticket) }}">
                                                {{ $ticket->subject }}
                                            </a>
                                        </td>
                                        <td>{{ $ticket->site->name }}</td>
                                        <td>{{ ucfirst($ticket->status) }}</td>
                                        <td>{{ $ticket->categoryLabel() }}</td>
                                        <td>
                                            @if ($ticket->labels->isEmpty())
                                                None
                                            @else
                                                <div class="ticket-label-list">
                                                    @foreach ($ticket->labels as $label)
                                                        <x-ticket-label-chip :label="$label" :ticket-status="$ticketStatus" />
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td>{{ ucfirst($ticket->priority) }}</td>
                                        <td>{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
                                        <td>
                                            <strong>{{ $ticket->attentionLabel() }}</strong>
                                            <div class="lede">{{ $ticket->attentionDescription() }}</div>
                                        </td>
                                        <td>{{ $ticket->conversation?->support_code ?? 'Not linked' }}</td>
                                        <td>{{ $ticket->updated_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section id="conversations" class="section" aria-labelledby="conversations-heading">
                <div class="section-header">
                    <h2 id="conversations-heading">Conversations</h2>
                    <div class="section-actions">
                        <span class="lede">
                            {{ $conversations->count() }} open ·
                            {{ $newActivityConversationCount === 1 ? '1 needs attention' : $newActivityConversationCount.' need attention' }}
                        </span>
                        @foreach ($conversationFilters as $filterValue => $filterLabel)
                            <a
                                class="button {{ $conversationFilter === $filterValue ? '' : 'secondary' }}"
                                href="{{ route('dashboard', $filterValue === 'all' ? [] : ['conversation_filter' => $filterValue]) }}"
                                @if ($conversationFilter === $filterValue) aria-current="page" @endif
                            >
                                {{ $filterLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                @if ($conversations->isEmpty())
                    <p class="empty">{{ $conversationEmptyMessage }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Site</th>
                                    <th scope="col">Visitor</th>
                                    <th scope="col">Assigned</th>
                                    <th scope="col">Attention</th>
                                    <th scope="col">Read</th>
                                    <th scope="col">Support Code</th>
                                    <th scope="col">Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($conversations as $conversation)
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.conversations.show', $conversation->support_code) }}">
                                                {{ $conversation->subject ?? 'Untitled conversation' }}
                                            </a>
                                        </td>
                                        <td>{{ $conversation->site->name }}</td>
                                        <td>{{ $conversation->visitor->anonymous_id ?? 'Unknown visitor' }}</td>
                                        <td>{{ $conversation->assignedAgent?->name ?? 'Unassigned' }}</td>
                                        <td>{{ $conversation->attentionLabel() }}</td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ $conversation->hasNewActivityFor($agent) ? 'attention' : 'ready' }}">
                                                {{ $conversation->readStateLabelFor($agent) }}
                                            </span>
                                        </td>
                                        <td>{{ $conversation->support_code }}</td>
                                        <td>{{ $conversation->last_message_at?->diffForHumans() ?? $conversation->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
