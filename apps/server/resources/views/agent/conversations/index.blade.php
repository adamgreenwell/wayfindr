<x-layouts.app title="Conversations" :agent="$agent" :account="$account">
            <p><a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a></p>
            <h1>Conversations</h1>
            <p class="lede">
                {{ $conversationFilter === 'closed' ? 'Closed visitor conversations' : 'Active visitor conversations' }} for {{ $account->name }}.
            </p>

            <section id="conversations" class="section" aria-labelledby="conversations-heading">
                <div class="section-header">
                    <h2 id="conversations-heading">Conversation queue</h2>
                    <div class="section-actions">
                        <span class="lede">
                            @if ($conversationFilter === 'closed')
                                {{ $conversations->count() === 1 ? '1 closed' : $conversations->count().' closed' }}
                            @elseif ($activeConversationFilters !== [])
                                {{ $conversations->count() === 1 ? '1 open matching' : $conversations->count().' open matching' }}
                            @else
                                {{ $conversations->count() }} open ·
                                {{ $newActivityConversationCount === 1 ? '1 needs attention' : $newActivityConversationCount.' need attention' }} ·
                                {{ $cobrowseAttentionConversationCount === 1 ? '1 cobrowse session needs attention' : $cobrowseAttentionConversationCount.' cobrowse sessions need attention' }}
                            @endif
                        </span>
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
                                    <th scope="col">Presence</th>
                                    <th scope="col">Cobrowse</th>
                                    <th scope="col">Assigned</th>
                                    <th scope="col">Attention</th>
                                    <th scope="col">Read</th>
                                    <th scope="col">Support Code</th>
                                    <th scope="col">Last Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($conversations as $conversation)
                                    @php
                                        $activityPreview = $conversation->queueActivityPreview();
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
                                        <td>{{ $conversation->last_message_at?->diffForHumans() ?? $conversation->created_at->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
