<x-layouts.app title="Conversations" :agent="$agent" :account="$account">
            <x-page-header title="Conversations" :subtitle="($conversationFilter === 'closed' ? 'Closed visitor conversations' : 'Active visitor conversations').' for '.$account->name.'.'" :back-href="route('dashboard')" back-label="Back to dashboard" />

            <section id="conversations" class="section" aria-labelledby="conversations-heading">
                <div class="section-header">
                    <h2 id="conversations-heading">Conversation queue</h2>
                    <div class="section-actions">
                        <span class="lede">{{ $conversationQueueCountSummary['heading'] }}</span>
                        @foreach ($conversationFilters as $filterValue => $filterLabel)
                            @php
                                $filterParams = $conversationQuery;

                                if ($filterValue === 'all') {
                                    unset($filterParams['conversation_filter']);
                                } else {
                                    $filterParams['conversation_filter'] = $filterValue;
                                }
                            @endphp
                            <a
                                class="button {{ $conversationFilter === $filterValue ? '' : 'secondary' }}"
                                href="{{ route('dashboard.conversations.index', $filterParams) }}"
                                @if ($conversationFilter === $filterValue) aria-current="page" @endif
                            >
                                {{ $filterLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <form class="section-form" method="GET" action="{{ route('dashboard.conversations.index') }}">
                    @if ($conversationFilter !== 'all')
                        <input type="hidden" name="conversation_filter" value="{{ $conversationFilter }}">
                    @endif

                    <div class="meta-grid">
                        <div class="meta-item">
                            <label class="meta-label" for="conversation_site">Site</label>
                            <select id="conversation_site" name="conversation_site">
                                <option value="">Any site</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site->id }}" @selected($conversationSite === $site->id)>
                                        {{ $site->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="conversation_presence">Presence</label>
                            <select id="conversation_presence" name="conversation_presence">
                                @foreach ($conversationPresenceFilters as $presenceValue => $presenceLabel)
                                    <option value="{{ $presenceValue === 'all' ? '' : $presenceValue }}" @selected($conversationPresence === $presenceValue)>
                                        {{ $presenceLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="meta-item">
                            <label class="meta-label" for="conversation_search">Search</label>
                            <input
                                id="conversation_search"
                                name="conversation_search"
                                type="search"
                                value="{{ $conversationSearch }}"
                                placeholder="Subject, support code, or visitor"
                            >
                            <p class="field-help">Search by subject, support code, visitor ID, visitor name, or visitor email.</p>
                        </div>

                        <div class="meta-item">
                            <span class="meta-label">Queue</span>
                            <button class="button" type="submit">Search conversations</button>
                            @php
                                $clearParams = $conversationQuery;
                                unset($clearParams['conversation_search'], $clearParams['conversation_site'], $clearParams['conversation_presence']);
                            @endphp
                            <a class="button secondary" href="{{ route('dashboard.conversations.index', $clearParams) }}">Clear filters</a>
                        </div>
                    </div>
                </form>

                @php
                    $conversationQueueFocusItems = [
                        ['label' => 'Lane', 'value' => $conversationFilters[$conversationFilter]],
                    ];
                    $focusedConversationSite = $conversationSite ? $sites->firstWhere('id', $conversationSite) : null;

                    if ($focusedConversationSite) {
                        $conversationQueueFocusItems[] = ['label' => 'Site', 'value' => $focusedConversationSite->name];
                    }

                    $conversationQueueFocusItems[] = ['label' => 'Presence', 'value' => $conversationPresenceFilters[$conversationPresence]];

                    if ($conversationSearch !== '') {
                        $conversationQueueFocusItems[] = ['label' => 'Search', 'value' => $conversationSearch];
                    }
                @endphp

                <div class="filter-summary" aria-label="Conversation queue focus">
                    <div>
                        <strong>Queue focus</strong>
                        <p class="lede">What this conversation queue is showing before you open a row.</p>
                        <p class="lede">{{ $conversationQueueCountSummary['detail'] }}</p>
                    </div>
                    <div class="filter-chips">
                        @foreach ($conversationQueueFocusItems as $conversationQueueFocusItem)
                            <span class="filter-chip">
                                {{ $conversationQueueFocusItem['label'] }}: {{ $conversationQueueFocusItem['value'] }}
                            </span>
                        @endforeach
                    </div>
                </div>

                @if ($activeConversationFilters !== [])
                    <div class="filter-summary" aria-label="Active conversation filters">
                        <div>
                            <strong>Active conversation filters</strong>
                            <p class="lede">Queue narrowed to conversations matching this view.</p>
                        </div>
                        <div class="filter-chips">
                            @foreach ($activeConversationFilters as $activeFilter)
                                <a class="filter-chip" href="{{ $activeFilter['href'] }}">
                                    {{ $activeFilter['label'] }}
                                    <span aria-hidden="true">x</span>
                                </a>
                            @endforeach
                            <a class="filter-chip filter-chip-clear" href="{{ route('dashboard.conversations.index') }}">Clear all conversation filters</a>
                        </div>
                    </div>
                @endif

                @if (collect($conversationQueueSummary)->sum('count') > 0)
                    <div class="filter-summary" aria-label="Conversation queue snapshot">
                        <div>
                            <strong>Queue snapshot</strong>
                            <p class="lede">Support-lane counts respect the current site, search, and presence context before the table narrows further.</p>
                            <p class="lede">{{ $conversationQueueCountSummary['detail'] }}</p>
                        </div>
                        <div class="filter-chips">
                            @foreach ($conversationQueueSummary as $conversationSummary)
                                <a
                                    class="filter-chip"
                                    href="{{ $conversationSummary['href'] }}"
                                    @if ($conversationSummary['active']) aria-current="page" @endif
                                >
                                    {{ $conversationSummary['label'] }}: {{ $conversationSummary['count'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($conversations->isEmpty())
                    <div class="empty empty-state">
                        <strong>{{ $conversationEmptyState['heading'] }}</strong>
                        <p class="lede">{{ $conversationEmptyState['detail'] }}</p>

                        @if ($conversationEmptyState['actions'] !== [])
                            <div class="empty-state-actions">
                                @foreach ($conversationEmptyState['actions'] as $emptyStateAction)
                                    <a class="button secondary" href="{{ $emptyStateAction['href'] }}">
                                        {{ $emptyStateAction['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Site</th>
                                    <th scope="col">Visitor</th>
                                    <th scope="col">Presence</th>
                                    <th scope="col">Cobrowse</th>
                                    <th scope="col">Assigned</th>
                                    <th scope="col">Attention</th>
                                    <th scope="col">Read</th>
                                    <th scope="col">Support Code</th>
                                    <th scope="col">Timing</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($conversations as $conversation)
                                    @php
                                        $activityPreview = $conversation->queueActivityPreview();
                                        $conversationTiming = $conversation->queueTimingContext();
                                        $cobrowseTransport = $cobrowseTransportByConversationId->get($conversation->id, [
                                            'label' => 'Unavailable',
                                            'message' => 'Cobrowse transport is not active.',
                                            'last_report' => 'Not reported',
                                            'pressure' => 'No drops reported',
                                            'guidance' => 'Wait for an active cobrowse session before relying on cobrowse.',
                                            'tone' => 'manual',
                                        ]);
                                    @endphp
                                    <tr>
                                        <td class="queue-activity-preview">
                                            <a class="text-link" href="{{ route('dashboard.conversations.show', ['supportCode' => $conversation->support_code] + $conversationQuery) }}">
                                                {{ $conversation->subject ?? 'Untitled conversation' }}
                                            </a>
                                            <span class="table-note">{{ $activityPreview['label'] }}</span>
                                            @if ($activityPreview['occurred_at'])
                                                <time class="table-note" datetime="{{ $activityPreview['occurred_at']->toJSON() }}">
                                                    Activity {{ $activityPreview['occurred_at']->diffForHumans() }}
                                                </time>
                                            @endif
                                            <p class="lede">{{ $activityPreview['body'] }}</p>
                                        </td>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.sites.show', $conversation->site) }}">
                                                {{ $conversation->site->name }}
                                            </a>
                                        </td>
                                        <td>{{ $conversation->visitor->anonymous_id ?? 'Unknown visitor' }}</td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ in_array($conversation->visitor?->presenceState(), ['active', 'recent'], true) ? 'ready' : 'manual' }}">
                                                {{ $conversation->visitor?->presenceLabel() ?? 'Not reported' }}
                                            </span>
                                        </td>
                                        <td>
                                            <span
                                                class="readiness-status"
                                                data-status="{{ $cobrowseTransport['tone'] }}"
                                                aria-label="Cobrowse {{ $cobrowseTransport['label'] }}. {{ $cobrowseTransport['message'] }} {{ $cobrowseTransport['guidance'] }}"
                                                title="{{ $cobrowseTransport['message'] }}"
                                            >
                                                {{ $cobrowseTransport['label'] }}
                                            </span>
                                            <span class="table-note">Last report {{ $cobrowseTransport['last_report'] }}</span>
                                            @if (! in_array($cobrowseTransport['pressure'], ['No drops reported', 'No recent drops reported'], true))
                                                <span class="table-note">Pressure {{ $cobrowseTransport['pressure'] }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $conversation->assignedAgent?->name ?? 'Unassigned' }}</td>
                                        <td>{{ $conversation->attentionLabel() }}</td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ $conversation->hasNewActivityFor($agent) ? 'attention' : 'ready' }}">
                                                {{ $conversation->readStateLabelFor($agent) }}
                                            </span>
                                        </td>
                                        <td>
                                            <x-support-code-reference
                                                :code="$conversation->support_code"
                                                :href="route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code])"
                                            />
                                        </td>
                                        <td>
                                            <strong>{{ $conversationTiming['opened_label'] }}</strong>
                                            <span class="table-note">{{ $conversationTiming['wait_label'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
