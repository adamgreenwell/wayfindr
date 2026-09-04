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
                    <form method="POST" action="{{ route('dashboard.tickets.bulk.preview') }}" data-ticket-bulk-form>
                        @csrf
                        @foreach ($ticketQuery as $queryKey => $queryValue)
                            <input type="hidden" name="return_query[{{ $queryKey }}]" value="{{ $queryValue }}">
                        @endforeach

                        <div class="wf-bulk-toolbar" role="group" aria-label="{{ __('tickets.bulk.region') }}">
                            <strong data-ticket-selected-count data-one="{{ __('tickets.bulk.selected.one') }}" data-many="{{ __('tickets.bulk.selected.many', ['count' => '__COUNT__']) }}">
                                {{ __('tickets.bulk.selected.none') }}
                            </strong>

                            <label for="ticket_bulk_action">{{ __('tickets.bulk.action_label') }}</label>
                            <select id="ticket_bulk_action" name="action" data-ticket-bulk-action>
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
                                <label class="sr-only" for="ticket_bulk_assign_agent_value" data-ticket-bulk-value-label="assign_agent" hidden>{{ __('tickets.bulk.values.agent') }}</label>
                                <select id="ticket_bulk_assign_agent_value" name="value" data-ticket-bulk-value="assign_agent" disabled hidden>
                                    <option value="">{{ __('tickets.bulk.values.choose_agent') }}</option>
                                    @foreach ($bulkActionAgents as $bulkAgent)
                                        <option value="{{ $bulkAgent->id }}">{{ $bulkAgent->name }}</option>
                                    @endforeach
                                </select>
                            @endif

                            <label class="sr-only" for="ticket_bulk_add_label_value" data-ticket-bulk-value-label="add_label" hidden>{{ __('tickets.bulk.values.label') }}</label>
                            <select id="ticket_bulk_add_label_value" name="value" data-ticket-bulk-value="add_label" disabled hidden>
                                <option value="">{{ __('tickets.bulk.values.choose_label') }}</option>
                                @foreach ($ticketLabels as $bulkLabel)
                                    <option value="{{ $bulkLabel->id }}">{{ $bulkLabel->name }}</option>
                                @endforeach
                            </select>

                            <label class="sr-only" for="ticket_bulk_priority_value" data-ticket-bulk-value-label="set_priority" hidden>{{ __('tickets.bulk.values.priority') }}</label>
                            <select id="ticket_bulk_priority_value" name="value" data-ticket-bulk-value="set_priority" disabled hidden>
                                <option value="">{{ __('tickets.bulk.values.choose_priority') }}</option>
                                @foreach (array_keys(\App\Enums\TicketPriority::guidanceOptions()) as $bulkPriority)
                                    <option value="{{ $bulkPriority }}">{{ __('tickets.priorities.'.$bulkPriority) }}</option>
                                @endforeach
                            </select>

                            <label class="sr-only" for="ticket_bulk_status_value" data-ticket-bulk-value-label="set_status" hidden>{{ __('tickets.bulk.values.status') }}</label>
                            <select id="ticket_bulk_status_value" name="value" data-ticket-bulk-value="set_status" disabled hidden>
                                <option value="">{{ __('tickets.bulk.values.choose_status') }}</option>
                                <option value="open">{{ __('tickets.statuses.open') }}</option>
                                <option value="pending">{{ __('tickets.statuses.pending') }}</option>
                            </select>

                            <button class="button" type="submit" data-ticket-bulk-review disabled>{{ __('tickets.bulk.review') }}</button>
                            <button class="button secondary" type="button" data-ticket-bulk-clear disabled>{{ __('tickets.bulk.clear') }}</button>
                        </div>

                        <div class="table-wrap">
                            <table class="wf-queue">
                            <thead>
                                <tr>
                                    <th class="wf-queue-select" scope="col">
                                        <input type="checkbox" data-ticket-select-all aria-label="{{ __('tickets.bulk.select_all') }}">
                                    </th>
                                    <th scope="col">{{ __('tickets.columns.subject') }}</th>
                                    @if ($canViewTicketConversations)
                                        <th scope="col">{{ __('tickets.columns.latest_activity') }}</th>
                                    @endif
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
                                    <tr data-ticket-bulk-row>
                                        <td class="wf-queue-select">
                                            <input
                                                type="checkbox"
                                                name="ticket_ids[]"
                                                value="{{ $ticket->id }}"
                                                data-ticket-select
                                                aria-label="{{ __('tickets.bulk.select_ticket', ['subject' => $ticket->subject]) }}"
                                            >
                                        </td>
                                        <td class="wf-queue-subject" style="--wf-row-site: var({{ $ticket->site->resolvedColor()->cssVariable() }})">
                                            <a href="{{ route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketQuery) }}">
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
                                            <td class="ticket-activity-preview">
                                                <span class="wf-queue-cobrowse">{{ $previewLabel }}</span>
                                                <span class="wf-queue-preview" title="{{ $previewBody }}">
                                                    {{ $previewBody }}@if ($activityPreview['occurred_at']) &middot; {{ $activityPreview['occurred_at']->diffForHumans() }}@endif
                                                </span>
                                                @if ($activityPreview['reply_visibility'])
                                                    <span class="wf-queue-preview">
                                                        {{ __('tickets.row.reply_visibility') }}
                                                        @php
                                                            $cue = $activityPreview['reply_visibility']['cue'] ?? null;
                                                        @endphp
                                                        <span class="wf-queue-mark" @if ($activityPreview['reply_visibility']['tone'] !== 'manual') data-tone="attention" @endif>{{ $cue ? __('tickets.read_state.'.$cue['key']) : __('tickets.row.no_linked_conversation') }}</span>
                                                        {{ $cue
                                                            ? ($cue['seen_at']
                                                                ? __('tickets.read_state.detail_seen', ['elapsed' => $cue['seen_at']->diffForHumans()])
                                                                : __('tickets.read_state.'.$cue['detail_key']))
                                                            : __('tickets.row.reply_visibility_none') }}
                                                    </span>
                                                @endif
                                            </td>
                                        @endif
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
                                        <td>
                                            <span class="wf-queue-cobrowse" @if ($ticketExternalIssueState['tone'] !== 'manual') data-tone="{{ $ticketExternalIssueState['tone'] === 'ready' ? 'live' : 'attention' }}" @endif>
                                                {{ $ticketExternalIssueState['label'] }}
                                            </span>
                                            <span class="wf-queue-preview" title="{{ $ticketExternalIssueState['detail'] }}">{{ $ticketExternalIssueState['detail'] }}</span>
                                            @if ($ticketExternalIssueState['attempt'])
                                                <span class="wf-queue-preview" title="{{ $ticketExternalIssueState['attempt']['body'] }}">
                                                    {{ __('tickets.row.latest_attempt') }} <x-translated-feedback :feedback="$ticketExternalIssueState['attempt']['label_feedback']" />: <x-translated-feedback :feedback="$ticketExternalIssueState['attempt']['body_feedback']" />
                                                </span>
                                                @if ($ticketExternalIssueState['attempt']['occurred_at'])
                                                    <span class="wf-queue-preview">{{ $ticketExternalIssueState['attempt']['occurred_at']->diffForHumans() }}</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="wf-queue-when">
                                            {{ __('tickets.row.opened', ['elapsed' => $ticketTiming['opened_at']->diffForHumans()]) }}
                                            <span class="wf-queue-preview" title="{{ $waitLabel }}">{{ $waitLabel }}</span>
                                            @if ($slaState)
                                                <span class="wf-queue-state" data-tone="{{ $slaState['tone'] }}">
                                                    <i aria-hidden="true"></i>{{ __('sla.queue.summary', ['metric' => $slaState['metric_label'], 'state' => $slaState['label']]) }}
                                                </span>
                                                <span class="wf-queue-preview">{{ $slaState['detail'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            </table>
                        </div>
                    </form>
                @endif
            </section>

            <script>
                (function () {
                    var form = document.querySelector('[data-ticket-bulk-form]');

                    if (! form) {
                        return;
                    }

                    var boxes = Array.from(form.querySelectorAll('[data-ticket-select]'));
                    var selectAll = form.querySelector('[data-ticket-select-all]');
                    var count = form.querySelector('[data-ticket-selected-count]');
                    var action = form.querySelector('[data-ticket-bulk-action]');
                    var values = Array.from(form.querySelectorAll('[data-ticket-bulk-value]'));
                    var valueLabels = Array.from(form.querySelectorAll('[data-ticket-bulk-value-label]'));
                    var review = form.querySelector('[data-ticket-bulk-review]');
                    var clear = form.querySelector('[data-ticket-bulk-clear]');
                    var lastBox = null;

                    function activeValue() {
                        return values.find(function (select) {
                            return ! select.disabled;
                        });
                    }

                    function update() {
                        var selected = boxes.filter(function (box) { return box.checked; });
                        var number = selected.length;
                        var value = activeValue();
                        var actionReady = action.value !== '' && (action.value === 'close' || (value && value.value !== ''));

                        count.textContent = number === 0
                            ? '{{ __('tickets.bulk.selected.none') }}'
                            : (number === 1
                                ? count.dataset.one
                                : count.dataset.many.replace('__COUNT__', String(number)));
                        selectAll.checked = number === boxes.length && boxes.length > 0;
                        selectAll.indeterminate = number > 0 && number < boxes.length;
                        review.disabled = number === 0 || ! actionReady;
                        clear.disabled = number === 0;

                        boxes.forEach(function (box) {
                            box.closest('[data-ticket-bulk-row]').toggleAttribute('data-selected', box.checked);
                        });
                    }

                    action.addEventListener('change', function () {
                        values.forEach(function (select) {
                            var active = select.dataset.ticketBulkValue === action.value;
                            select.disabled = ! active;
                            select.hidden = ! active;
                        });
                        valueLabels.forEach(function (label) {
                            label.hidden = label.dataset.ticketBulkValueLabel !== action.value;
                        });
                        update();
                    });

                    values.forEach(function (select) {
                        select.addEventListener('change', update);
                    });

                    boxes.forEach(function (box, index) {
                        box.addEventListener('click', function (event) {
                            if (event.shiftKey && lastBox) {
                                var lastIndex = boxes.indexOf(lastBox);
                                var from = Math.min(lastIndex, index);
                                var to = Math.max(lastIndex, index);

                                boxes.slice(from, to + 1).forEach(function (rangeBox) {
                                    rangeBox.checked = box.checked;
                                });
                            }

                            lastBox = box;
                            update();
                        });
                    });

                    selectAll.addEventListener('change', function () {
                        boxes.forEach(function (box) { box.checked = selectAll.checked; });
                        lastBox = null;
                        update();
                    });

                    clear.addEventListener('click', function () {
                        boxes.forEach(function (box) { box.checked = false; });
                        lastBox = null;
                        update();
                    });

                    update();
                })();
            </script>
</x-layouts.app>
