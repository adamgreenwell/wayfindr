<x-layouts.app :title="__('conversations.document_title')" :agent="$agent" :account="$account">
            {{-- One whole sentence per language, not a clause glued to ' for '. --}}
            <x-page-header
                :title="__('conversations.document_title')"
                :subtitle="$conversationFilter === 'closed'
                    ? __('conversations.page_title_closed', ['account' => $account->name])
                    : __('conversations.page_title_active', ['account' => $account->name])" />

            @php
                // The lanes carry their own counts, which is what lets the
                // separate "Queue snapshot" band go: four of its six chips were
                // lanes already. The other two are presence, and they move onto
                // the presence control below.
                $laneCounts = collect($conversationQueueSummary)->keyBy('state');
                $waitingLanes = ['new_activity', 'needs_reply'];
            @endphp

            <section id="conversations" aria-labelledby="conversations-heading">
                <h2 id="conversations-heading" class="sr-only">{{ __('conversations.title') }}</h2>

                <nav class="wf-lanes" aria-label="{{ __('conversations.lanes.region_label') }}">
                    @foreach ($conversationFilters as $filterValue => $filterLabel)
                        @php
                            $filterParams = $conversationQuery;

                            if ($filterValue === 'all') {
                                unset($filterParams['conversation_filter']);
                            } else {
                                $filterParams['conversation_filter'] = $filterValue;
                            }

                            $lane = $laneCounts->get($filterValue);
                        @endphp
                        <a
                            class="wf-lane"
                            href="{{ route('dashboard.conversations.index', $filterParams) }}"
                            @if ($conversationFilter === $filterValue) aria-current="page" @endif
                        >
                            {{ $filterLabel }}
                            @if ($lane)
                                <span
                                    class="wf-lane-count"
                                    title="{{ $lane['label'] }}: {{ $lane['count'] }}"
                                    @if (in_array($filterValue, $waitingLanes, true) && $lane['count'] > 0) data-tone="waiting" @endif
                                >{{ $lane['count'] }}</span>
                            @endif
                        </a>
                    @endforeach

                    @foreach ($conversationQueueSummary as $summaryLane)
                        @if (in_array($summaryLane['state'], ['active', 'recent'], true))
                            <a
                                class="wf-lane"
                                href="{{ $summaryLane['href'] }}"
                                @if ($summaryLane['active']) aria-current="page" @endif
                            >
                                {{ $summaryLane['label'] }}
                                <span class="wf-lane-count" title="{{ $summaryLane['label'] }}: {{ $summaryLane['count'] }}">{{ $summaryLane['count'] }}</span>
                            </a>
                        @endif
                    @endforeach
                </nav>

                <form class="wf-filters" method="GET" action="{{ route('dashboard.conversations.index') }}">
                    @if ($conversationFilter !== 'all')
                        <input type="hidden" name="conversation_filter" value="{{ $conversationFilter }}">
                    @endif

                    <div class="wf-filter wf-filter-search">
                        <label for="conversation_search">Search</label>
                        <input
                            id="conversation_search"
                            name="conversation_search"
                            type="search"
                            value="{{ $conversationSearch }}"
                            placeholder="{{ __('conversations.search.placeholder') }}"
                        >
                        <span class="wf-filter-help">{{ __('conversations.search.hint') }}</span>
                    </div>

                    <div class="wf-filter">
                        <label for="conversation_site">Site</label>
                        <select id="conversation_site" name="conversation_site">
                            <option value="">{{ __('conversations.sites.any') }}</option>
                            @foreach ($sites as $site)
                                <option value="{{ $site->id }}" @selected($conversationSite === $site->id)>
                                    {{ $site->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="wf-filter">
                        <label for="conversation_presence">Presence</label>
                        <select id="conversation_presence" name="conversation_presence">
                            @foreach ($conversationPresenceFilters as $presenceValue => $presenceLabel)
                                <option value="{{ $presenceValue === 'all' ? '' : $presenceValue }}" @selected($conversationPresence === $presenceValue)>
                                    {{ $presenceLabel }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    @php
                        $clearParams = $conversationQuery;
                        unset($clearParams['conversation_search'], $clearParams['conversation_site'], $clearParams['conversation_presence']);
                    @endphp
                    <div class="wf-filter-actions">
                        <button class="button" type="submit">{{ __('conversations.search.submit') }}</button>
                        <a class="button secondary" href="{{ route('dashboard.conversations.index', $clearParams) }}">{{ __('conversations.actions.clear_filters') }}</a>
                    </div>
                </form>

                <p class="wf-queue-summary">
                    <strong>{{ $conversationQueueCountSummary['heading'] }}</strong>
                    {{ $conversationQueueCountSummary['detail'] }}
                </p>

                @if ($activeConversationFilters !== [])
                    <div class="filter-summary" aria-label="{{ __('conversations.chips.region_label') }}">
                        <div>
                            <strong>{{ __('conversations.chips.region_label') }}</strong>
                        </div>
                        <div class="filter-chips">
                            @foreach ($activeConversationFilters as $activeFilter)
                                <a class="filter-chip" href="{{ $activeFilter['href'] }}">
                                    {{ $activeFilter['label'] }}
                                    <span aria-hidden="true">x</span>
                                </a>
                            @endforeach
                            <a class="filter-chip filter-chip-clear" href="{{ route('dashboard.conversations.index') }}">{{ __('conversations.actions.clear_all') }}</a>
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
                        <table class="wf-queue">
                            <thead>
                                <tr>
                                    <th scope="col">Subject</th>
                                    <th scope="col">Site</th>
                                    <th scope="col">Visitor</th>
                                    <th scope="col">Attention</th>
                                    <th scope="col">Read</th>
                                    <th scope="col">Cobrowse</th>
                                    <th scope="col">Assigned</th>
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
                                        $presenceState = $conversation->visitor?->presenceState();
                                        $needsReply = $conversation->attentionState() !== 'waiting_on_visitor';
                                        $visitorLabel = $conversation->visitor?->name
                                            ?: $conversation->visitor?->email
                                            ?: $conversation->visitor?->anonymous_id
                                            ?: 'Unknown visitor';
                                    @endphp
                                    <tr>
                                        <td class="wf-queue-subject" style="--wf-row-site: var({{ $conversation->site->resolvedColor()->cssVariable() }})">
                                            <a href="{{ route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'from_queue' => '1'] + $conversationQuery) }}">
                                                {{ $conversation->subject ?? 'Untitled conversation' }}
                                            </a>
                                            <span class="wf-queue-preview" title="{{ $activityPreview['body'] }}">
                                                <x-support-code-reference
                                                    :code="$conversation->support_code"
                                                    :href="route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code])"
                                                />
                                                &middot; {{ $activityPreview['label'] }}@if ($activityPreview['occurred_at']) &middot; <time datetime="{{ $activityPreview['occurred_at']->toJSON() }}">Activity {{ $activityPreview['occurred_at']->diffForHumans() }}</time>@endif &middot; {{ $activityPreview['body'] }}
                                            </span>
                                        </td>
                                        <td>
                                            <a class="wf-queue-site" href="{{ route('dashboard.sites.show', $conversation->site) }}">
                                                <span class="wf-site-dot" style="background: var({{ $conversation->site->resolvedColor()->cssVariable() }})" aria-hidden="true"></span>
                                                {{ $conversation->site->name }}
                                            </a>
                                        </td>
                                        <td>
                                            <span class="wf-queue-assignee" title="{{ $visitorLabel }}">{{ Str::limit($visitorLabel, 22) }}</span>
                                        </td>
                                        <td>
                                            <span class="wf-queue-state" @if ($needsReply) data-tone="waiting" @endif>
                                                <i aria-hidden="true"></i>{{ $conversation->attentionLabel() }}
                                            </span>

                                            {{-- Marks only for what is actually true. A quiet visitor and an
                                                 unavailable cobrowse are the resting states of nearly every
                                                 row, and printing them on all of them says nothing. --}}
                                            <span class="wf-queue-marks">
                                                @if (in_array($presenceState, ['active', 'recent'], true))
                                                    <span class="wf-queue-mark" data-tone="live">
                                                        <i aria-hidden="true"></i>{{ $conversation->visitor?->presenceLabel() }}
                                                    </span>
                                                @endif
                                            </span>
                                        </td>
                                        <td>
                                            @if ($conversation->hasNewActivityFor($agent))
                                                <span class="wf-queue-unread">{{ $conversation->readStateLabelFor($agent) }}</span>
                                            @else
                                                <span class="wf-queue-cobrowse">{{ $conversation->readStateLabelFor($agent) }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span
                                                class="wf-queue-cobrowse"
                                                @if ($cobrowseTransport['tone'] !== 'manual')
                                                    data-tone="{{ $cobrowseTransport['tone'] === 'ready' ? 'live' : 'attention' }}"
                                                @endif
                                                title="{{ $cobrowseTransport['message'] }} {{ $cobrowseTransport['guidance'] }}"
                                            >{{ $cobrowseTransport['label'] }}</span>
                                            <span class="wf-queue-preview">
                                                Last report {{ $cobrowseTransport['last_report'] }}@if (! in_array($cobrowseTransport['pressure'], ['No drops reported', 'No recent drops reported'], true)) &middot; Pressure {{ $cobrowseTransport['pressure'] }}@endif
                                            </span>
                                        </td>
                                        <td>
                                            <span class="wf-queue-assignee" @if (! $conversation->assignedAgent) data-unassigned="true" @endif>
                                                {{ $conversation->assignedAgent?->name ?? 'Unassigned' }}
                                            </span>
                                        </td>
                                        <td class="wf-queue-when">
                                            {{ $conversationTiming['opened_label'] }}
                                            <span class="wf-queue-preview" title="{{ $conversationTiming['wait_label'] }}">{{ $conversationTiming['wait_label'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
