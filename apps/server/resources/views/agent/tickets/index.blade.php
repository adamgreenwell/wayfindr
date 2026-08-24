<x-layouts.app :title="__('tickets.document_title')" :agent="$agent" :account="$account">
            {{-- One whole sentence per language, not a clause glued to a name. --}}
            <x-page-header
                :title="__('tickets.document_title')"
                :subtitle="__('tickets.subtitle', ['account' => $account->name])" />

            <section id="tickets" aria-labelledby="tickets-heading">
                <h2 id="tickets-heading" class="sr-only">{{ __('tickets.title') }}</h2>

                <nav class="wf-lanes" aria-label="{{ __('tickets.regions.lanes') }}">
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
                            class="wf-lane"
                            href="{{ route('dashboard.tickets.index', $statusParams) }}"
                            @if ($ticketStatus === $filterValue) aria-current="page" @endif
                        >{{ $filterLabel }}</a>
                    @endforeach

                    <span class="wf-lane-divider" aria-hidden="true"></span>

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
                            class="wf-lane"
                            href="{{ route('dashboard.tickets.index', $ownerParams) }}"
                            @if ($ticketFilter === $filterValue) aria-current="page" @endif
                        >{{ $filterLabel }}</a>
                    @endforeach
                </nav>

                @if (collect($ticketQueueSummary)->sum('count') > 0)
                    {{-- The old "Queue snapshot" band. These chips were always the
                         next-step filter with a count on it, so they are lanes. --}}
                    <nav class="wf-lanes wf-lanes-secondary" aria-label="{{ __('tickets.regions.next_steps') }}">
                        @foreach ($ticketQueueSummary as $ticketSummary)
                            <a
                                class="wf-lane"
                                href="{{ $ticketSummary['href'] }}"
                                @if ($ticketAttention === $ticketSummary['state']) aria-current="page" @endif
                            >
                                {{ $ticketSummary['label'] }}
                                <span
                                    class="wf-lane-count"
                                    title="{{ $ticketSummary['label'] }}: {{ $ticketSummary['count'] }}"
                                    @if (in_array($ticketSummary['state'], ['needs_reply', 'needs_owner'], true) && $ticketSummary['count'] > 0) data-tone="waiting" @endif
                                >{{ $ticketSummary['count'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                @endif

                <form class="wf-filters" method="GET" action="{{ route('dashboard.tickets.index') }}">
                    @if ($ticketStatus !== 'open')
                        <input type="hidden" name="ticket_status" value="{{ $ticketStatus }}">
                    @endif

                    @if ($ticketFilter !== 'all')
                        <input type="hidden" name="ticket_filter" value="{{ $ticketFilter }}">
                    @endif

                    <div class="wf-filter wf-filter-search">
                        <label for="ticket_search">{{ __('tickets.search.label') }}</label>
                        <input
                            id="ticket_search"
                            name="ticket_search"
                            type="search"
                            value="{{ $ticketSearch }}"
                            placeholder="{{ __('tickets.search.placeholder') }}"
                        >
                        <span class="wf-filter-help">{{ __('tickets.search.hint') }}</span>
                    </div>

                    @php
                        $ticketSelectFilters = [
                            ['id' => 'ticket_site', 'label' => __('tickets.columns.site'), 'options' => $sites->pluck('name', 'id')->prepend(__('tickets.filters.site_any'), '')->all(), 'selected' => $ticketSite ?? ''],
                            ['id' => 'ticket_priority', 'label' => __('tickets.columns.priority'), 'options' => $ticketPriorityFilters, 'selected' => $ticketPriority],
                            ['id' => 'ticket_category', 'label' => __('tickets.columns.category'), 'options' => $ticketCategoryFilters, 'selected' => $ticketCategory],
                            ['id' => 'ticket_label', 'label' => __('tickets.columns.label'), 'options' => $ticketLabelFilters, 'selected' => $ticketLabel],
                            ['id' => 'ticket_attention', 'label' => __('tickets.columns.next_step'), 'options' => $ticketAttentionFilters, 'selected' => $ticketAttention],
                            ['id' => 'ticket_external', 'label' => __('tickets.columns.external_issue'), 'options' => $ticketExternalIssueFilters, 'selected' => $ticketExternalIssue],
                        ];
                    @endphp

                    @foreach ($ticketSelectFilters as $selectFilter)
                        <div class="wf-filter">
                            <label for="{{ $selectFilter['id'] }}">{{ $selectFilter['label'] }}</label>
                            <select id="{{ $selectFilter['id'] }}" name="{{ $selectFilter['id'] }}">
                                @foreach ($selectFilter['options'] as $optionValue => $optionLabel)
                                    <option value="{{ $optionValue }}" @selected((string) $selectFilter['selected'] === (string) $optionValue)>
                                        {{ $optionLabel }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach

                    @php
                        $clearParams = $ticketQuery;
                        unset($clearParams['ticket_site'], $clearParams['ticket_priority'], $clearParams['ticket_category'], $clearParams['ticket_label'], $clearParams['ticket_attention'], $clearParams['ticket_external'], $clearParams['ticket_search']);
                    @endphp
                    <div class="wf-filter-actions">
                        <button class="button" type="submit">{{ __('tickets.search.submit') }}</button>
                        <a class="button secondary" href="{{ route('dashboard.tickets.index', $clearParams) }}">{{ __('tickets.actions.clear_filters') }}</a>
                    </div>
                </form>

                <p class="wf-queue-summary">
                    <strong>{{ $ticketQueueCountSummary['heading'] }}</strong>
                    {{ $ticketQueueCountSummary['detail'] }}
                </p>

                @if ($ticketActiveFilters !== [])
                    <div class="filter-summary" aria-label="{{ __('tickets.regions.filters') }}">
                        <div>
                            <strong>{{ __('tickets.regions.filters') }}</strong>
                        </div>
                        <div class="filter-chips">
                            @foreach ($ticketActiveFilters as $activeFilter)
                                <a class="filter-chip" href="{{ $activeFilter['href'] }}">
                                    {{ $activeFilter['label'] }}
                                    <span aria-hidden="true">x</span>
                                </a>
                            @endforeach
                            <a class="filter-chip filter-chip-clear" href="{{ route('dashboard.tickets.index') }}">{{ __('tickets.actions.clear_all') }}</a>
                        </div>
                    </div>
                @endif

                @if ($tickets->isEmpty())
                    <div class="empty empty-state">
                        <strong>{{ $ticketEmptyState['heading'] }}</strong>
                        <p class="lede">{{ $ticketEmptyState['detail'] }}</p>

                        @if ($ticketEmptyState['actions'] !== [])
                            <div class="empty-state-actions">
                                @foreach ($ticketEmptyState['actions'] as $emptyStateAction)
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
                                    <th scope="col">{{ __('tickets.columns.subject') }}</th>
                                    <th scope="col">{{ __('tickets.columns.latest_activity') }}</th>
                                    <th scope="col">{{ __('tickets.columns.site') }}</th>
                                    <th scope="col">{{ __('tickets.columns.status') }}</th>
                                    <th scope="col">{{ __('tickets.columns.category') }}</th>
                                    <th scope="col">{{ __('tickets.columns.labels') }}</th>
                                    <th scope="col">{{ __('tickets.columns.priority') }}</th>
                                    <th scope="col">{{ __('tickets.columns.assignee') }}</th>
                                    <th scope="col">{{ __('tickets.columns.next_step') }}</th>
                                    <th scope="col">{{ __('tickets.columns.external_issue') }}</th>
                                    <th scope="col">{{ __('tickets.columns.timing') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    @php
                                        $ticketTiming = $ticket->queueTimingContext();
                                        $activityPreview = $ticket->queueActivityPreview();

                                        // The model hands out keys and timestamps; this surface
                                        // turns them into words, because it is the only place that
                                        // knows whose language to use. See Ticket::attentionLabelKey().
                                        $previewBody = $activityPreview['body_key']
                                            ? __('tickets.row.'.$activityPreview['body_key'])
                                            : $activityPreview['body'];
                                        $previewLabel = __('tickets.row.'.$activityPreview['label_key']);
                                        $waitLabel = $ticketTiming['wait_since']
                                            ? __('tickets.row.'.$ticketTiming['wait_key'], [
                                                'elapsed' => $ticketTiming['wait_key'] === 'closed'
                                                    ? $ticketTiming['wait_since']->diffForHumans()
                                                    : $ticket->elapsedWaitFrom($ticketTiming['wait_since']),
                                            ])
                                            : __('tickets.row.'.$ticketTiming['wait_key']);
                                        $recentEscalation = $ticket->latestRecentEscalationEvent();
                                        $ticketLifecycleNote = $ticket->latestLifecycleNote();
                                        $ticketExternalIssueState = $ticketExternalIssueStates[$ticket->id] ?? [
                                            'attempt' => null,
                                            'label' => 'No external issue',
                                            'tone' => 'manual',
                                            'detail' => 'Wayfindr is the only tracker for this ticket.',
                                        ];
                                    @endphp
                                    <tr>
                                        <td class="wf-queue-subject" style="--wf-row-site: var({{ $ticket->site->resolvedColor()->cssVariable() }})">
                                            <a href="{{ route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketQuery) }}">
                                                {{ $ticket->subject }}
                                            </a>
                                            <span class="wf-queue-preview">
                                                @if ($ticket->conversation)
                                                    <x-support-code-reference
                                                        :code="$ticket->conversation->support_code"
                                                        :href="route('dashboard.support-code.lookup', ['support_code' => $ticket->conversation->support_code])"
                                                    />
                                                @else
                                                    {{ __('tickets.row.not_linked') }}
                                                @endif
                                            </span>
                                        </td>
                                        <td class="ticket-activity-preview">
                                            <span class="wf-queue-cobrowse">{{ $previewLabel }}</span>
                                            <span class="wf-queue-preview" title="{{ $previewBody }}">
                                                {{ $previewBody }}@if ($activityPreview['occurred_at']) &middot; {{ $activityPreview['occurred_at']->diffForHumans() }}@endif
                                            </span>
                                            @if ($activityPreview['reply_visibility'])
                                                <span class="wf-queue-preview">
                                                    {{ __('tickets.row.reply_visibility') }}
                                                    <span class="wf-queue-mark" @if ($activityPreview['reply_visibility']['tone'] !== 'manual') data-tone="attention" @endif>{{ $activityPreview['reply_visibility']['label'] }}</span>
                                                    {{ $activityPreview['reply_visibility']['detail'] }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="wf-queue-site">
                                                <span class="wf-site-dot" style="background: var({{ $ticket->site->resolvedColor()->cssVariable() }})" aria-hidden="true"></span>
                                                {{ $ticket->site->name }}
                                            </span>
                                        </td>
                                        <td><span class="wf-queue-cobrowse">{{ __('tickets.statuses.'.$ticket->status) }}</span></td>
                                        {{-- From the catalogue, keyed by the value on the row. TicketCategory's
                                             own labels stay English for the surfaces not yet extracted. --}}
                                        <td><span class="wf-queue-cobrowse">{{ $ticket->category ? __('tickets.categories.'.$ticket->category) : __('tickets.filters.category_uncategorized') }}</span></td>
                                        <td>
                                            @if ($ticket->labels->isEmpty())
                                                <span class="wf-queue-cobrowse">{{ __('tickets.row.none') }}</span>
                                            @else
                                                <div class="ticket-label-list">
                                                    @foreach ($ticket->labels as $label)
                                                        <x-ticket-label-chip :label="$label" :ticket-status="$ticketStatus" />
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="wf-queue-cobrowse" @if ($ticket->priority === 'urgent' || $ticket->priority === 'high') data-tone="attention" @endif>
                                                {{ __('tickets.priorities.'.$ticket->priority) }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="wf-queue-assignee" @if (! $ticket->assignee) data-unassigned="true" @endif>
                                                {{ $ticket->assignee?->name ?? __('tickets.row.unassigned') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="wf-queue-state" @if (in_array($ticket->attentionState(), ['needs_reply', 'needs_owner'], true)) data-tone="waiting" @endif>
                                                <i aria-hidden="true"></i>{{ __('tickets.row.'.$ticket->attentionLabelKey()) }}
                                            </span>
                                            <span class="wf-queue-preview" title="{{ __('tickets.row.'.$ticket->attentionDescriptionKey()) }}">{{ __('tickets.row.'.$ticket->attentionDescriptionKey()) }}</span>
                                            @if ($recentEscalation)
                                                <span class="wf-queue-preview">{{ $ticket->escalationAudienceLabelFor($agent) }}</span>
                                            @endif
                                            @if ($ticketLifecycleNote)
                                                <span class="wf-queue-preview" title="{{ $ticketLifecycleNote['body'] }}">
                                                    Lifecycle note {{ $ticketLifecycleNote['label'] }}: {{ $ticketLifecycleNote['body'] }}
                                                </span>
                                                <span class="wf-queue-preview">{{ $ticketLifecycleNote['actor'] }} - {{ $ticketLifecycleNote['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="wf-queue-cobrowse" @if ($ticketExternalIssueState['tone'] !== 'manual') data-tone="{{ $ticketExternalIssueState['tone'] === 'ready' ? 'live' : 'attention' }}" @endif>
                                                {{ $ticketExternalIssueState['label'] }}
                                            </span>
                                            <span class="wf-queue-preview" title="{{ $ticketExternalIssueState['detail'] }}">{{ $ticketExternalIssueState['detail'] }}</span>
                                            @if ($ticketExternalIssueState['attempt'])
                                                <span class="wf-queue-preview" title="{{ $ticketExternalIssueState['attempt']['body'] }}">
                                                    Latest attempt {{ $ticketExternalIssueState['attempt']['label'] }}: {{ $ticketExternalIssueState['attempt']['body'] }}
                                                </span>
                                                @if ($ticketExternalIssueState['attempt']['occurred_at'])
                                                    <span class="wf-queue-preview">{{ $ticketExternalIssueState['attempt']['occurred_at']->diffForHumans() }}</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="wf-queue-when">
                                            {{ __('tickets.row.opened', ['elapsed' => $ticketTiming['opened_at']->diffForHumans()]) }}
                                            <span class="wf-queue-preview" title="{{ $waitLabel }}">{{ $waitLabel }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
