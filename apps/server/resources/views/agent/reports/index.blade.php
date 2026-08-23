@php
    $reportTabs = [
        ['id' => 'volume', 'label' => 'Volume'],
        ['id' => 'speed', 'label' => 'Speed'],
        ['id' => 'tickets', 'label' => 'Tickets'],
        ['id' => 'agents', 'label' => 'Agents'],
        // Its own tab, not a row under Speed. Every other tab answers how fast
        // the desk moved; this one answers whether that helped, and a desk can
        // improve all of the others while getting worse at this.
        ['id' => 'satisfaction', 'label' => 'Satisfaction'],
    ];
@endphp

<x-layouts.app title="Reports" :agent="$agent" :account="$account">
    <x-page-header title="Reports" subtitle="How much support came in, how fast it was answered, and who answered it.">
        <x-slot:actions>
            <span class="lede">{{ $window->label() }}</span>
        </x-slot:actions>
    </x-page-header>

    <section class="section" aria-labelledby="report-filters-heading">
        <div class="section-header">
            <h2 id="report-filters-heading">Range</h2>
            <span class="lede">{{ $siteId ? 'One site' : 'All visible sites' }}</span>
        </div>
        <form class="section-form" method="GET" action="{{ route('dashboard.reports.index') }}">
            <div class="meta-grid">
                <div class="meta-item">
                    <label class="meta-label" for="report_days">Period</label>
                    <select id="report_days" name="report_days">
                        @foreach ($windowChoices as $choice)
                            <option value="{{ $choice }}" @selected($window->days === $choice)>Last {{ $choice }} days</option>
                        @endforeach
                    </select>
                </div>
                <div class="meta-item">
                    <label class="meta-label" for="report_site">Site</label>
                    <select id="report_site" name="report_site">
                        <option value="">All visible sites</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected($siteId === $site->id)>{{ $site->name }}@if ($site->isArchived()) (archived) @endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Report</span>
                    <button class="button" type="submit">Apply</button>
                    <a class="button secondary" href="{{ route('dashboard.reports.index') }}">Reset</a>
                </div>
            </div>
        </form>
    </section>

    @if ($historyIsPartial)
        <section class="section" aria-labelledby="report-history-heading">
            <div class="section-header">
                <h2 id="report-history-heading">What these numbers can reach</h2>
                <span class="lede">Not all of it is the same age</span>
            </div>
            <div class="notice-copy">
                <p><strong>Conversations opened</strong> and <strong>first response times</strong> are recoverable from the whole history of this install.</p>
                @if ($historyBeganAt)
                    <p><strong>Closes, resolution times and reopens</strong> are read from lifecycle records, which this install began keeping on {{ $historyBeganAt->toFormattedDayDateString() }}. Anything before that is unrecorded rather than absent &mdash; conversations were closed, but nothing was keeping the sequence, and it cannot be reconstructed after the fact.</p>
                @else
                    <p><strong>Closes, resolution times and reopens</strong> are read from lifecycle records, and this install has not stamped when it started keeping them. Run outstanding migrations; until then these figures cover only what happens to be on record.</p>
                @endif
                <p>Purging a site removes its history along with everything else, so a total can legitimately fall.</p>
            </div>
        </section>
    @endif

    <x-tabs id="support-report" label="Report sections" :tabs="$reportTabs">
        <x-tab-panel id="volume" :active="true">
            <section class="section" aria-labelledby="report-volume-heading">
                <div class="section-header">
                    <h2 id="report-volume-heading">Conversation volume</h2>
                    <span class="lede">
                        {{ $volume['opened_total'] }} opened
                        &middot; {{ $volume['closed_total'] }} closed
                        &middot; {{ $volume['open_now'] }} open now
                    </span>
                </div>

                @if ($chart['max'] === 0)
                    <p class="empty">No conversations were opened or closed in this period.</p>
                @else
                    <div class="chart-scroll">
                        <div
                            class="chart"
                            role="img"
                            aria-label="Conversations per day. {{ $volume['opened_total'] }} opened and {{ $volume['closed_total'] }} closed over the {{ $window->days }} days ending {{ $window->end->toFormattedDayDateString() }}. The busiest day had {{ $chart['max'] }}."
                        >
                            @foreach ($chart['days'] as $day)
                                <div class="chart__day" title="{{ $day['label'] }}: {{ $day['opened'] }} opened, {{ $day['closed'] }} closed">
                                    <div class="chart__bars">
                                        {{-- A day with nothing on it gets no bar at all. The minimum
                                             height that keeps a single conversation visible would
                                             otherwise draw a sliver on every empty day. --}}
                                        <div class="chart__bar chart__bar--opened @if ($day['opened'] === 0) chart__bar--none @endif" style="height: {{ round(($day['opened'] / $chart['max']) * 100, 2) }}%"></div>
                                        <div class="chart__bar chart__bar--closed @if ($day['closed'] === 0) chart__bar--none @endif" style="height: {{ round(($day['closed'] / $chart['max']) * 100, 2) }}%"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    <p class="chart-legend">
                        <span class="chart-key chart-key--opened"></span> Opened
                        <span class="chart-key chart-key--closed"></span> Closed
                        <span class="lede">Tallest day: {{ $chart['max'] }}</span>
                    </p>
                    <p class="lede">
                        <a href="{{ route('dashboard.reports.export', $reportQuery + ['report_export' => 'daily']) }}">Export the daily series as CSV</a>
                    </p>
                @endif
            </section>

            <section class="section" aria-labelledby="report-queue-heading">
                <div class="section-header">
                    <h2 id="report-queue-heading">Waiting right now</h2>
                    <span class="lede">A live count, not a trend</span>
                </div>
                @if ($queueHealth['needs_reply'] === 0)
                    <p class="empty">Nothing is waiting on a reply.</p>
                @else
                    <p>
                        <strong>{{ $queueHealth['needs_reply'] }}</strong>
                        {{ $queueHealth['needs_reply'] === 1 ? 'conversation is' : 'conversations are' }} waiting on the desk,
                        the oldest for {{ \App\Support\Reporting\DurationSummary::humanize($queueHealth['oldest_wait_seconds']) }}.
                    </p>
                @endif
                <p class="lede">For reference, unattended alerts fire once a conversation has waited {{ $queueHealth['threshold_minutes'] }} minutes without anyone looking at it. This count is every conversation waiting, whatever its age.</p>
            </section>
        </x-tab-panel>

        <x-tab-panel id="speed">
            <section class="section" aria-labelledby="report-response-heading">
                <div class="section-header">
                    <h2 id="report-response-heading">First response</h2>
                    <span class="lede">{{ $firstResponse['summary']->count }} measured</span>
                </div>
                @if ($firstResponse['summary']->isEmpty())
                    <p class="empty">No conversation opened in this period has had a first reply yet.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">Median</th>
                                    <td>{{ $firstResponse['summary']->medianLabel() }}</td>
                                    <td class="lede">Half of visitors waited less than this.</td>
                                </tr>
                                <tr>
                                    <th scope="row">90th percentile</th>
                                    <td>{{ $firstResponse['summary']->p90Label() }}</td>
                                    <td class="lede">The unlucky tenth waited at least this long.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
                @if ($firstResponse['awaiting'] > 0)
                    <p class="lede">{{ $firstResponse['awaiting'] }} {{ $firstResponse['awaiting'] === 1 ? 'conversation' : 'conversations' }} opened in this period {{ $firstResponse['awaiting'] === 1 ? 'has' : 'have' }} had no reply at all, so {{ $firstResponse['awaiting'] === 1 ? 'it is' : 'they are' }} counted here rather than folded into the figures above.</p>
                @endif
            </section>

            <section class="section" aria-labelledby="report-resolution-heading">
                <div class="section-header">
                    <h2 id="report-resolution-heading">Resolution</h2>
                    <span class="lede">{{ $resolution['summary']->count }} {{ $resolution['summary']->count === 1 ? 'close' : 'closes' }} measured</span>
                </div>
                @if ($resolution['summary']->isEmpty() && $resolution['unmeasurable'] > 0)
                    {{-- An upgraded install whose closes are all older than the
                         recording boundary lands here. Saying "nothing was
                         closed" would be false, and would hide the explanation
                         in exactly the case that needs it most. --}}
                    <p class="empty">
                        {{ $resolution['unmeasurable'] }} {{ $resolution['unmeasurable'] === 1 ? 'conversation was' : 'conversations were' }} closed in this period, but
                        {{ $resolution['unmeasurable'] === 1 ? 'it' : 'they' }} opened before this install started recording reopens, so how long the work took cannot be established.
                        Resolution times will appear as conversations opened since then are closed.
                    </p>
                @elseif ($resolution['summary']->isEmpty())
                    <p class="empty">No conversation was closed in this period.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">Median</th>
                                    <td>{{ $resolution['summary']->medianLabel() }}</td>
                                    <td class="lede">From opening, or from the reopen that started the stretch of work.</td>
                                </tr>
                                <tr>
                                    <th scope="row">90th percentile</th>
                                    <td>{{ $resolution['summary']->p90Label() }}</td>
                                    <td class="lede">The slowest tenth took at least this long.</td>
                                </tr>
                                @if ($resolution['unmeasurable'] > 0)
                                    <tr>
                                        <th scope="row">Counted but not measured</th>
                                        <td>{{ $resolution['unmeasurable'] }}</td>
                                        <td class="lede">Closed before this install started recording reopens, so how long the work took cannot be established. Counted as closes above; left out of the two figures here rather than inflating them.</td>
                                    </tr>
                                @endif
                                <tr>
                                    <th scope="row">Reopened</th>
                                    <td>{{ $resolution['reopened'] }}</td>
                                    <td class="lede">A resolution that did not hold.</td>
                                </tr>
                                <tr>
                                    <th scope="row">Reopened by a visitor</th>
                                    <td>{{ $resolution['reopened_by_visitor'] }}</td>
                                    <td class="lede">The visitor came back rather than an agent reopening it &mdash; the clearest signal the answer did not land.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </x-tab-panel>

        <x-tab-panel id="tickets">
            <section class="section" aria-labelledby="report-ticket-volume-heading">
                <div class="section-header">
                    <div>
                        <h2 id="report-ticket-volume-heading">Ticket volume</h2>
                        <p class="lede">
                            {{ $ticketVolume['opened_total'] }} created ·
                            {{ $ticketVolume['closed_total'] }} closed ·
                            {{ $ticketVolume['open_now'] }} open now
                        </p>
                    </div>
                </div>

                @if ($ticketVolume['opened_total'] === 0 && $ticketVolume['closed_total'] === 0)
                    <div class="notice-copy">
                        <p>No ticket was created or closed in this period.</p>
                    </div>
                @else
                    {{-- The same chart the conversation tab draws. Its classes
                         are the ones with CSS behind them; a second set invented
                         here would render as an unstyled column of divs. --}}
                    <div
                        class="chart"
                        role="img"
                        aria-label="Tickets per day. {{ $ticketVolume['opened_total'] }} created and {{ $ticketVolume['closed_total'] }} closed over the {{ $window->days }} days ending {{ $window->end->toFormattedDayDateString() }}. The busiest day had {{ $ticketChart['max'] }}."
                    >
                        @foreach ($ticketChart['days'] as $day)
                            <div class="chart__day" title="{{ $day['label'] }}: {{ $day['opened'] }} created, {{ $day['closed'] }} closed">
                                <div class="chart__bars">
                                    <div class="chart__bar chart__bar--opened @if ($day['opened'] === 0) chart__bar--none @endif" style="height: {{ $ticketChart['max'] === 0 ? 0 : round(($day['opened'] / $ticketChart['max']) * 100, 2) }}%"></div>
                                    <div class="chart__bar chart__bar--closed @if ($day['closed'] === 0) chart__bar--none @endif" style="height: {{ $ticketChart['max'] === 0 ? 0 : round(($day['closed'] / $ticketChart['max']) * 100, 2) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="chart-legend">
                        <span class="chart-key chart-key--opened"></span> Created
                        <span class="chart-key chart-key--closed"></span> Closed
                        <span class="lede">Tallest day: {{ $ticketChart['max'] }}</span>
                    </p>
                @endif
            </section>

            <section class="section" aria-labelledby="report-ticket-resolution-heading">
                <div class="section-header">
                    <div>
                        <h2 id="report-ticket-resolution-heading">Ticket resolution</h2>
                        <p class="lede">
                            {{ $ticketResolution['summary']->count }}
                            {{ $ticketResolution['summary']->count === 1 ? 'close' : 'closes' }} measured
                        </p>
                    </div>
                </div>

                @if ($ticketResolution['summary']->count === 0 && $ticketResolution['unmeasurable'] > 0)
                    {{-- Same shape as the conversation half: an upgraded install
                         whose ticket closes all predate the boundary lands here,
                         and "nothing was closed" would be false. --}}
                    <div class="notice-copy">
                        <p>
                            {{ $ticketResolution['unmeasurable'] }} {{ $ticketResolution['unmeasurable'] === 1 ? 'ticket was' : 'tickets were' }} closed in this period, but
                            {{ $ticketResolution['unmeasurable'] === 1 ? 'it' : 'they' }} opened before this install started recording ticket reopens, so how long the work took cannot be established.
                            Resolution times will appear as tickets opened since then are closed.
                        </p>
                        @if ($ticketResolution['reopened'] > 0)
                            <p>
                                {{ $ticketResolution['reopened'] }} {{ $ticketResolution['reopened'] === 1 ? 'ticket was' : 'tickets were' }} reopened in this period &mdash;
                                {{ $ticketResolution['reopened'] === 1 ? 'a resolution' : 'resolutions' }} that did not hold. That is countable even where the durations are not.
                            </p>
                        @endif
                    </div>
                @elseif ($ticketResolution['summary']->count === 0)
                    {{-- A reopen needs no close to be worth reporting. A ticket
                         closed before the range and reopened inside it is a
                         resolution that did not hold, and it stays open -- so
                         it never reaches the table below, and the figure the
                         backend now counts correctly would still be invisible
                         on the page. --}}
                    @if ($ticketResolution['reopened'] > 0)
                        <div class="table-wrap">
                            <table>
                                <tbody>
                                    <tr>
                                        <th scope="row">Reopened</th>
                                        <td>{{ $ticketResolution['reopened'] }}</td>
                                        <td class="lede">A resolution that did not hold. Nothing closed in this period, so there is no resolution time to report alongside it.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="notice-copy">
                        @if ($ticketHistoryBeganAt && $window->start->lessThan($ticketHistoryBeganAt))
                            {{-- The window reaches back past the boundary, so
                                 "nothing was closed" is a claim this install
                                 cannot make: closes before it are unknowable,
                                 not absent. --}}
                            <p>
                                No ticket close is on record in this period. This install began recording ticket
                                closes on {{ $ticketHistoryBeganAt->toFormattedDayDateString() }}, and the range
                                selected reaches back before that &mdash; tickets closed earlier left no trace to
                                count, so this is not the same as nothing having happened.
                            </p>
                        @else
                            <p>No ticket was closed in this period.</p>
                        @endif
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">Median</th>
                                    <td>{{ $ticketResolution['summary']->medianLabel() }}</td>
                                    <td class="lede">Half of tickets were resolved faster than this.</td>
                                </tr>
                                <tr>
                                    <th scope="row">90th percentile</th>
                                    <td>{{ $ticketResolution['summary']->p90Label() }}</td>
                                    <td class="lede">The slowest tenth took at least this long.</td>
                                </tr>
                                @if ($ticketResolution['unmeasurable'] > 0)
                                    <tr>
                                        <th scope="row">Counted but not measured</th>
                                        <td>{{ $ticketResolution['unmeasurable'] }}</td>
                                        <td class="lede">Opened before this install started recording ticket reopens, so how long the work took cannot be established. Left out of the two figures above rather than inflating them.</td>
                                    </tr>
                                @endif
                                <tr>
                                    <th scope="row">Reopened</th>
                                    <td>{{ $ticketResolution['reopened'] }}</td>
                                    <td class="lede">A resolution that did not hold. Each reopen starts a new episode, so a ticket closed three times contributes three resolutions rather than one long one.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                @endif

                @if ($ticketHistoryBeganAt)
                    <div class="notice-copy">
                        <p>
                            Ticket closes and reopens have been recorded since long before the conversation
                            ones &mdash; on this install, since
                            {{ $ticketHistoryBeganAt->toFormattedDayDateString() }}. A ticket opened before
                            then may have been closed and reopened while nothing was writing it down, so it
                            is counted as a close and left out of the times here.
                        </p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="report-ticket-agents-heading">
                <div class="section-header">
                    <div>
                        <h2 id="report-ticket-agents-heading">Who carried the ticket work</h2>
                    </div>
                </div>

                @if ($ticketAgentActivity === [])
                    <div class="notice-copy">
                        <p>No ticket replies or closes in this period.</p>
                    </div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Agent</th>
                                    <th scope="col">Replies sent</th>
                                    <th scope="col">Tickets closed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ticketAgentActivity as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td>{{ $row['replies'] }}</td>
                                        <td>{{ $row['closes'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </x-tab-panel>

        <x-tab-panel id="agents">
            <section class="section" aria-labelledby="report-agents-heading">
                <div class="section-header">
                    <h2 id="report-agents-heading">Who carried the work</h2>
                    <span class="lede">{{ count($agentActivity) }} {{ count($agentActivity) === 1 ? 'agent' : 'agents' }}</span>
                </div>
                @if ($agentActivity === [])
                    <p class="empty">No agent replied to or closed a conversation in this period.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Agent</th>
                                    <th scope="col">Replies sent</th>
                                    <th scope="col">Conversations closed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($agentActivity as $row)
                                    <tr>
                                        <td>
                                            {{ $row['name'] }}
                                            @if ($row['agent']?->isDeactivated())
                                                <span class="lede">Deactivated</span>
                                            @endif
                                        </td>
                                        <td>{{ $row['replies'] }}</td>
                                        <td>{{ $row['closes'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="lede">
                        Deactivated agents stay listed: they did the work, and a total that changes when someone leaves is not a total.
                        <a href="{{ route('dashboard.reports.export', $reportQuery + ['report_export' => 'agents']) }}">Export as CSV</a>
                    </p>
                @endif
            </section>
        </x-tab-panel>

        <x-tab-panel id="satisfaction">
            <section class="section" aria-labelledby="report-satisfaction-heading">
                <div class="section-header">
                    <h2 id="report-satisfaction-heading">Whether it helped</h2>
                    <span class="lede">{{ $satisfaction['answered'] }} of {{ $satisfaction['closed'] }} {{ $satisfaction['closed'] === 1 ? 'close' : 'closes' }} answered</span>
                </div>
                @if ($satisfaction['answered'] === 0)
                    {{-- Never a zero or a dash where a percentage goes. Nobody
                         answering is not the same as everybody answering badly,
                         and a 0% here would be read as the second. --}}
                    <p class="empty">
                        @if ($satisfaction['closed'] === 0)
                            No conversation was closed in this period, so nobody was asked.
                        @else
                            Nobody answered in this period. That is not a bad score &mdash; it is no score, and the
                            two must not be read as the same thing. If your sites are not asking, turn the prompt
                            on under <strong>Asking how it went</strong> in a site's settings.
                        @endif
                    </p>
                @else
                    <div class="table-wrap">
                        <table>
                            <tbody>
                                <tr>
                                    <th scope="row">Good</th>
                                    <td>{{ $satisfaction['good'] }}</td>
                                    <td class="lede">{{ $satisfaction['positive'] }}% of the people who answered.</td>
                                </tr>
                                <tr>
                                    <th scope="row">Ok</th>
                                    <td>{{ $satisfaction['ok'] }}</td>
                                    <td class="lede">Helped, but not a story anybody will tell.</td>
                                </tr>
                                <tr>
                                    <th scope="row">Bad</th>
                                    <td>{{ $satisfaction['bad'] }}</td>
                                    <td class="lede">The answer this whole tab exists to surface.</td>
                                </tr>
                                <tr>
                                    <th scope="row">Answered</th>
                                    <td>{{ $satisfaction['answered'] }}</td>
                                    <td class="lede">Out of {{ $satisfaction['closed'] }} {{ $satisfaction['closed'] === 1 ? 'close' : 'closes' }}. Every figure above is a share of this number, never of the closes &mdash; people who said nothing are not counted as satisfied.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    @if ($satisfaction['answered'] < 10)
                        <p class="lede">
                            Few enough answers that one more would move the percentage noticeably. Read it as a
                            direction rather than a measurement.
                        </p>
                    @endif
                @endif
            </section>

            <section class="section" aria-labelledby="report-comments-heading">
                <div class="section-header">
                    <h2 id="report-comments-heading">What people said</h2>
                    <span class="lede">{{ count($ratingComments) }} {{ count($ratingComments) === 1 ? 'comment' : 'comments' }}</span>
                </div>

                @if ($ratingComments === [])
                    <p class="empty">Nobody left a comment in this period. The comment box is optional, and most people skip it &mdash; a score with no words is still an answer.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Score</th>
                                    <th scope="col">What they said</th>
                                    <th scope="col">Conversation</th>
                                    <th scope="col">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($ratingComments as $comment)
                                    <tr>
                                        <td>{{ ucfirst($comment['score']) }}</td>
                                        {{-- Visitor-authored, so escaped like any other visitor text and
                                             never used as a link label. --}}
                                        <td>{{ $comment['comment'] }}</td>
                                        <td>
                                            <a href="{{ route('dashboard.conversations.show', $comment['support_code']) }}">{{ $comment['support_code'] }}</a>
                                        </td>
                                        <td>{{ $comment['rated_at']->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="lede">The most recent {{ count($ratingComments) }}. A score tells you something went wrong; this is the only place that says what.</p>
                @endif
            </section>
        </x-tab-panel>
    </x-tabs>
</x-layouts.app>
