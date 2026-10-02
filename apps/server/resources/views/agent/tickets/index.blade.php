<x-layouts.app :title="__('tickets.document_title')" :agent="$agent" :account="$account">
            {{-- One whole sentence per language, not a clause glued to a name. --}}
            <x-page-header
                :title="__('tickets.document_title')"
                :subtitle="__('tickets.subtitle', ['account' => $account->name])" />

            @if (session('ticket_bulk_status'))
                @php
                    $bulkStatus = session('ticket_bulk_status');
                @endphp
                <div class="status-message wf-bulk-result" role="status">
                    <span>
                        @if ($bulkStatus['key'] === 'tickets.bulk.flash.applied')
                            {{ __('tickets.bulk.flash.applied', ['changed' => $bulkStatus['changed'], 'selected' => $bulkStatus['selected']]) }}
                        @else
                            {{ __('tickets.bulk.flash.undone', ['reverted' => $bulkStatus['reverted'], 'skipped' => $bulkStatus['skipped']]) }}
                        @endif
                    </span>
                    @if (($bulkStatus['run_id'] ?? null) !== null)
                        <form method="POST" action="{{ route('dashboard.tickets.bulk.undo', $bulkStatus['run_id']) }}">
                            @csrf
                            <button class="button secondary" type="submit">{{ __('tickets.bulk.undo.submit') }}</button>
                        </form>
                    @endif
                </div>
            @endif

            @if (session('ticket_bulk_error'))
                <p class="field-error" role="alert">{{ __(session('ticket_bulk_error')) }}</p>
            @endif

            @if ($errors->hasAny(['ticket_ids', 'ticket_ids.*', 'action', 'value']))
                <div class="field-error" role="alert">
                    @foreach ($errors->getMessages() as $field => $messages)
                        @if ($field === 'ticket_ids' || str_starts_with($field, 'ticket_ids.') || in_array($field, ['action', 'value'], true))
                            @foreach ($messages as $message)
                                <p>{{ $message }}</p>
                            @endforeach
                        @endif
                    @endforeach
                </div>
            @endif

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
                            data-agent-shortcut-search-primary
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

                {{-- Only when the cap actually bit. The total describes the
                     selected lane; the table below contains the bounded page. --}}
                @if ($ticketQueueShownOf > $tickets->count())
                    <p class="wf-queue-summary" role="status">
                        {{ __('tickets.summary.capped_notice', [
                            'shown' => \App\Support\ReaderNumber::count($tickets->count()),
                            'total' => \App\Support\ReaderNumber::count($ticketQueueShownOf),
                        ]) }}
                    </p>
                @endif

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
                    <form method="POST" action="{{ route('dashboard.tickets.bulk.preview') }}" data-queue-bulk-form data-queue-bulk-no-value-actions="close" data-ticket-bulk-form>
                        @csrf
                        @foreach ($ticketQuery as $queryKey => $queryValue)
                            <input type="hidden" name="return_query[{{ $queryKey }}]" value="{{ $queryValue }}">
                        @endforeach

                        <div class="wf-bulk-toolbar" role="group" aria-label="{{ __('tickets.bulk.region') }}">
                            <strong data-queue-selected-count data-none="{{ __('tickets.bulk.selected.none') }}" data-one="{{ __('tickets.bulk.selected.one') }}" data-many="{{ __('tickets.bulk.selected.many', ['count' => '__COUNT__']) }}" data-ticket-selected-count>
                                {{ __('tickets.bulk.selected.none') }}
                            </strong>

                            <label for="ticket_bulk_action">{{ __('tickets.bulk.action_label') }}</label>
                            <select id="ticket_bulk_action" name="action" data-queue-bulk-action data-ticket-bulk-action>
                                <option value="">{{ __('tickets.bulk.choose_action') }}</option>
                                @if ($canAssignTickets)
                                    <option value="assign_agent">{{ __('tickets.bulk.actions.assign_agent') }}</option>
                                @endif
                                <option value="add_label">{{ __('tickets.bulk.actions.add_label') }}</option>
                                <option value="set_priority">{{ __('tickets.bulk.actions.set_priority') }}</option>
                                <option value="set_status">{{ __('tickets.bulk.actions.set_status') }}</option>
                                <option value="close">{{ __('tickets.bulk.actions.close') }}</option>
                            </select>

                            @if ($canAssignTickets)
                                <label class="sr-only" for="ticket_bulk_assign_agent_value" data-queue-bulk-value-label="assign_agent" data-ticket-bulk-value-label="assign_agent" hidden>{{ __('tickets.bulk.values.agent') }}</label>
                                <select id="ticket_bulk_assign_agent_value" name="value" data-queue-bulk-value="assign_agent" data-ticket-bulk-value="assign_agent" disabled hidden>
                                    <option value="">{{ __('tickets.bulk.values.choose_agent') }}</option>
                                    @foreach ($bulkActionAgents as $bulkAgent)
                                        <option value="{{ $bulkAgent->id }}">{{ $bulkAgent->name }}</option>
                                    @endforeach
                                </select>
                            @endif

                            <label class="sr-only" for="ticket_bulk_add_label_value" data-queue-bulk-value-label="add_label" data-ticket-bulk-value-label="add_label" hidden>{{ __('tickets.bulk.values.label') }}</label>
                            <select id="ticket_bulk_add_label_value" name="value" data-queue-bulk-value="add_label" data-ticket-bulk-value="add_label" disabled hidden>
                                <option value="">{{ __('tickets.bulk.values.choose_label') }}</option>
                                @foreach ($ticketLabels as $bulkLabel)
                                    <option value="{{ $bulkLabel->id }}">{{ $bulkLabel->name }}</option>
                                @endforeach
                            </select>

                            <label class="sr-only" for="ticket_bulk_priority_value" data-queue-bulk-value-label="set_priority" data-ticket-bulk-value-label="set_priority" hidden>{{ __('tickets.bulk.values.priority') }}</label>
                            <select id="ticket_bulk_priority_value" name="value" data-queue-bulk-value="set_priority" data-ticket-bulk-value="set_priority" disabled hidden>
                                <option value="">{{ __('tickets.bulk.values.choose_priority') }}</option>
                                @foreach (array_keys(\App\Enums\TicketPriority::guidanceOptions()) as $bulkPriority)
                                    <option value="{{ $bulkPriority }}">{{ __('tickets.priorities.'.$bulkPriority) }}</option>
                                @endforeach
                            </select>

                            <label class="sr-only" for="ticket_bulk_status_value" data-queue-bulk-value-label="set_status" data-ticket-bulk-value-label="set_status" hidden>{{ __('tickets.bulk.values.status') }}</label>
                            <select id="ticket_bulk_status_value" name="value" data-queue-bulk-value="set_status" data-ticket-bulk-value="set_status" disabled hidden>
                                <option value="">{{ __('tickets.bulk.values.choose_status') }}</option>
                                <option value="open">{{ __('tickets.statuses.open') }}</option>
                                <option value="pending">{{ __('tickets.statuses.pending') }}</option>
                            </select>

                            <button class="button" type="submit" data-queue-bulk-review data-ticket-bulk-review disabled>{{ __('tickets.bulk.review') }}</button>
                            <button class="button secondary" type="button" data-queue-bulk-clear data-ticket-bulk-clear disabled>{{ __('tickets.bulk.clear') }}</button>
                        </div>

                        <div class="table-wrap wf-ticket-queue-wrap">
                            <table class="wf-queue wf-ticket-queue" role="table" data-agent-shortcut-queue>
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th role="columnheader" class="wf-queue-select" scope="col">
                                        <label class="wf-ticket-select-all">
                                            <input type="checkbox" data-queue-select-all data-ticket-select-all aria-label="{{ __('tickets.bulk.select_all') }}">
                                            <span class="wf-ticket-mobile-label">{{ __('tickets.bulk.select_all') }}</span>
                                        </label>
                                    </th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.subject') }}</th>
                                    @if ($canViewTicketConversations)
                                        <th role="columnheader" scope="col">{{ __('tickets.columns.latest_activity') }}</th>
                                    @endif
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.site') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.status') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.category') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.labels') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.priority') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.assignee') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.next_step') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.external_issue') }}</th>
                                    <th role="columnheader" scope="col">{{ __('tickets.columns.timing') }}</th>
                                    <th role="columnheader" class="wf-ticket-mobile-details" scope="col">{{ __('ticket_detail.tabs.details') }}</th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @foreach ($tickets as $ticket)
                                    @php
                                        $ticketTiming = $ticket->queueTimingContext();
                                        $slaState = $slaStateByTicketId->get($ticket->id);
                                        $activityPreview = $canViewTicketConversations
                                            ? $ticket->queueActivityPreview()
                                            : null;

                                        // The model hands out keys and timestamps; this surface
                                        // turns them into words, because it is the only place that
                                        // knows whose language to use. See Ticket::attentionLabelKey().
                                        $previewBody = $activityPreview
                                            ? ($activityPreview['body_key']
                                                ? __('tickets.row.'.$activityPreview['body_key'])
                                                : $activityPreview['body'])
                                            : null;
                                        $previewLabel = $activityPreview
                                            ? __('tickets.row.'.$activityPreview['label_key'])
                                            : null;
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
                                    <tr role="row" style="--wf-row-site: var({{ $ticket->site->resolvedColor()->cssVariable() }})" data-agent-shortcut-row data-queue-bulk-row data-ticket-bulk-row>
                                        <td role="cell" class="wf-queue-select">
                                            <label class="wf-ticket-select">
                                                <input
                                                    type="checkbox"
                                                    name="ticket_ids[]"
                                                    value="{{ $ticket->id }}"
                                                    data-queue-select
                                                    data-ticket-select
                                                    aria-label="{{ __('tickets.bulk.select_ticket', ['subject' => $ticket->subject]) }}"
                                                >
                                            </label>
                                        </td>
                                        <td role="cell" class="wf-queue-subject" style="--wf-row-site: var({{ $ticket->site->resolvedColor()->cssVariable() }})">
                                            <a href="{{ route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketQuery) }}" data-agent-shortcut-open>
                                                {{ $ticket->subject }}
                                            </a>
                                            @if ($canViewTicketConversations)
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
                                            @endif
                                        </td>
                                        @if ($canViewTicketConversations)
                                            <td role="cell" class="ticket-activity-preview wf-ticket-secondary">
                                                @include('agent.tickets.partials.queue-secondary-field', ['queueField' => 'activity'])
                                            </td>
                                        @endif
                                        <td role="cell" class="wf-ticket-secondary">
                                            @include('agent.tickets.partials.queue-secondary-field', ['queueField' => 'site'])
                                        </td>
                                        <td role="cell" class="wf-ticket-status">
                                            <span class="wf-ticket-mobile-label" aria-hidden="true">{{ __('tickets.columns.status') }}</span>
                                            <span class="wf-queue-cobrowse">{{ __('tickets.statuses.'.$ticket->status) }}</span>
                                        </td>
                                        {{-- From the catalogue, keyed by the value on the row. TicketCategory's
                                             own labels stay English for the surfaces not yet extracted. --}}
                                        <td role="cell" class="wf-ticket-secondary">
                                            @include('agent.tickets.partials.queue-secondary-field', ['queueField' => 'category'])
                                        </td>
                                        <td role="cell" class="wf-ticket-secondary">
                                            @include('agent.tickets.partials.queue-secondary-field', ['queueField' => 'labels'])
                                        </td>
                                        <td role="cell" class="wf-ticket-secondary">
                                            @include('agent.tickets.partials.queue-secondary-field', ['queueField' => 'priority'])
                                        </td>
                                        <td role="cell" class="wf-ticket-owner">
                                            <span class="wf-ticket-mobile-label" aria-hidden="true">{{ __('tickets.columns.assignee') }}</span>
                                            <span class="wf-queue-assignee" @if (! $ticket->assignee) data-unassigned="true" @endif>
                                                {{ $ticket->assignee?->name ?? __('tickets.row.unassigned') }}
                                            </span>
                                        </td>
                                        <td role="cell" class="wf-ticket-next-step">
                                            <span class="wf-ticket-mobile-label" aria-hidden="true">{{ __('tickets.columns.next_step') }}</span>
                                            <span class="wf-queue-state" @if (in_array($ticket->attentionState(), ['needs_reply', 'needs_owner'], true)) data-tone="waiting" @endif>
                                                <i aria-hidden="true"></i>{{ __('tickets.row.'.$ticket->attentionLabelKey()) }}
                                            </span>
                                            <span class="wf-queue-preview" title="{{ __('tickets.row.'.$ticket->attentionDescriptionKey()) }}">{{ __('tickets.row.'.$ticket->attentionDescriptionKey()) }}</span>
                                            @if ($recentEscalation)
                                                <span class="wf-queue-preview">{{ __('tickets.row.'.$ticket->escalationAudienceKeyFor($agent)) }}</span>
                                            @endif
                                            @if ($ticketLifecycleNote)
                                                <span class="wf-queue-preview" title="{{ $ticketLifecycleNote['body'] }}">
                                                    {{ __('tickets.row.lifecycle_note') }} {{ __('tickets.lifecycle.'.$ticketLifecycleNote['label_key']) }}: {{ $ticketLifecycleNote['body'] }}
                                                </span>
                                                {{-- An actor is a NAME when there is one, and a key when there is not. --}}
                                                <span class="wf-queue-preview">{{ $ticketLifecycleNote['actor_key'] ? __('tickets.row.'.$ticketLifecycleNote['actor_key']) : $ticketLifecycleNote['actor'] }} - {{ $ticketLifecycleNote['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                        </td>
                                        <td role="cell" class="wf-ticket-secondary">
                                            @include('agent.tickets.partials.queue-secondary-field', ['queueField' => 'external'])
                                        </td>
                                        <td role="cell" class="wf-queue-when wf-ticket-timing">
                                            <span class="wf-ticket-mobile-label" aria-hidden="true">{{ __('tickets.columns.timing') }}</span>
                                            {{ __('tickets.row.opened', ['elapsed' => $ticketTiming['opened_at']->diffForHumans()]) }}
                                            <span class="wf-queue-preview" title="{{ $waitLabel }}">{{ $waitLabel }}</span>
                                            @if ($slaState)
                                                <span class="wf-queue-state" data-tone="{{ $slaState['tone'] }}">
                                                    <i aria-hidden="true"></i>{{ __('sla.queue.summary', ['metric' => $slaState['metric_label'], 'state' => $slaState['label']]) }}
                                                </span>
                                                <span class="wf-queue-preview">{{ $slaState['detail'] }}</span>
                                            @endif
                                        </td>
                                        {{-- One selection checkbox and shortcut link per ticket; only
                                             secondary display fields are repeated in the mobile disclosure. --}}
                                        <td role="cell" class="wf-ticket-mobile-details">
                                            <x-details-disclosure :summary="__('ticket_detail.tabs.details')">
                                                <dl class="wf-ticket-detail-list">
                                                    @foreach (['site', 'priority', 'category', 'labels', 'activity', 'external'] as $queueField)
                                                        @if ($queueField !== 'activity' || $canViewTicketConversations)
                                                            <div>
                                                                <dt>{{ __('tickets.columns.'.match ($queueField) { 'activity' => 'latest_activity', 'external' => 'external_issue', default => $queueField }) }}</dt>
                                                                <dd>@include('agent.tickets.partials.queue-secondary-field', ['queueField' => $queueField])</dd>
                                                            </div>
                                                        @endif
                                                    @endforeach
                                                </dl>
                                            </x-details-disclosure>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            </table>
                        </div>
                    </form>
                @endif
            </section>

            <x-queue-bulk-selector-script />
</x-layouts.app>
