<x-layouts.app title="Tickets" :agent="$agent" :account="$account">
            <p><a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a></p>
            <h1>Tickets</h1>
            <p class="lede">Structured support work for {{ $account->name }}.</p>

            <section id="tickets" class="section" aria-labelledby="tickets-heading">
                <div class="section-header">
                    <h2 id="tickets-heading">Ticket queue</h2>
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
                                href="{{ route('dashboard.tickets.index', $statusParams) }}"
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
                                href="{{ route('dashboard.tickets.index', $ownerParams) }}"
                                @if ($ticketFilter === $filterValue) aria-current="page" @endif
                            >
                                {{ $filterLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <form class="section-form" method="GET" action="{{ route('dashboard.tickets.index') }}">
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
                            <a class="button secondary" href="{{ route('dashboard.tickets.index', $clearParams) }}">Clear filters</a>
                        </div>
                    </div>
                </form>

                @if (collect($ticketQueueSummary)->sum('count') > 0)
                    <div class="filter-summary" aria-label="Ticket queue snapshot">
                        <div>
                            <strong>Queue snapshot</strong>
                            <p class="lede">Next-step counts respect the current queue filters before the next-step filter narrows the table.</p>
                        </div>
                        <div class="filter-chips">
                            @foreach ($ticketQueueSummary as $ticketSummary)
                                <a
                                    class="filter-chip"
                                    href="{{ $ticketSummary['href'] }}"
                                    @if ($ticketAttention === $ticketSummary['state']) aria-current="page" @endif
                                >
                                    {{ $ticketSummary['label'] }}: {{ $ticketSummary['count'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

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
                            <a class="filter-chip filter-chip-clear" href="{{ route('dashboard.tickets.index') }}">Clear all ticket filters</a>
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
                                    <th scope="col">Latest activity</th>
                                    <th scope="col">Site</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Labels</th>
                                    <th scope="col">Priority</th>
                                    <th scope="col">Assignee</th>
                                    <th scope="col">Next step</th>
                                    <th scope="col">Support Code</th>
                                    <th scope="col">Timing</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tickets as $ticket)
                                    @php
                                        $ticketTiming = $ticket->queueTimingContext();
                                    @endphp
                                    <tr>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.tickets.show', ['ticket' => $ticket] + $ticketQuery) }}">
                                                {{ $ticket->subject }}
                                            </a>
                                        </td>
                                        <td class="ticket-activity-preview">
                                            @php
                                                $activityPreview = $ticket->queueActivityPreview();
                                            @endphp
                                            <strong>{{ $activityPreview['label'] }}</strong>
                                            <div class="lede">{{ $activityPreview['body'] }}</div>
                                            @if ($activityPreview['occurred_at'])
                                                <span class="table-note">{{ $activityPreview['occurred_at']->diffForHumans() }}</span>
                                            @endif
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
                                            @php
                                                $recentEscalation = $ticket->latestRecentEscalationEvent();
                                            @endphp
                                            <strong>{{ $ticket->attentionLabel() }}</strong>
                                            <div class="lede">{{ $ticket->attentionDescription() }}</div>
                                            @if ($recentEscalation)
                                                <div class="lede">{{ $ticket->escalationAudienceLabelFor($agent) }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($ticket->conversation)
                                                <a class="text-link" href="{{ route('dashboard.support-code.lookup', ['support_code' => $ticket->conversation->support_code]) }}" aria-label="Open support record {{ $ticket->conversation->support_code }}">
                                                    <code>{{ $ticket->conversation->support_code }}</code>
                                                </a>
                                            @else
                                                Not linked
                                            @endif
                                        </td>
                                        <td>
                                            <strong>{{ $ticketTiming['opened_label'] }}</strong>
                                            <span class="table-note">{{ $ticketTiming['wait_label'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
</x-layouts.app>
