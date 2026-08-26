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
                        <label for="conversation_search">{{ __('conversations.search.label') }}</label>
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
                        <label for="conversation_site">{{ __('conversations.columns.site') }}</label>
                        <select id="conversation_site" name="conversation_site">
                            <option value="">{{ __('conversations.sites.any') }}</option>
                            @foreach ($sites as $site)
                                {{-- `lang` on the option itself: an <option> takes text
                                     content only, so a nested element is dropped by the
                                     parser and the name would inherit the document
                                     language after all. --}}
                                <option value="{{ $site->id }}" lang="" @selected($conversationSite === $site->id)>{{ $site->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="wf-filter">
                        <label for="conversation_presence">{{ __('conversations.filters_label_presence') }}</label>
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
                                    <th scope="col">{{ __('conversations.columns.subject') }}</th>
                                    <th scope="col">{{ __('conversations.columns.site') }}</th>
                                    <th scope="col">{{ __('conversations.columns.visitor') }}</th>
                                    <th scope="col">{{ __('conversations.columns.attention') }}</th>
                                    <th scope="col">{{ __('conversations.columns.read') }}</th>
                                    <th scope="col">{{ __('conversations.columns.cobrowse') }}</th>
                                    <th scope="col">{{ __('conversations.columns.assigned') }}</th>
                                    <th scope="col">{{ __('conversations.columns.timing') }}</th>
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
                                            ?: __('conversations.row.unknown_visitor');
                                    @endphp
                                    <tr>
                                        <td class="wf-queue-subject" style="--wf-row-site: var({{ $conversation->site->resolvedColor()->cssVariable() }})">
                                            <a href="{{ route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'from_queue' => '1'] + $conversationQuery) }}">
                                                @if (filled($conversation->subject))<span lang="">{{ $conversation->subject }}</span>@else{{ __('conversations.row.untitled') }}@endif
                                            </a>
                                            @php
                                                // The model hands out keys and timestamps; this surface turns
                                                // them into words, because it is the only place that knows whose
                                                // language to use. See Conversation::attentionLabel().
                                                $previewBody = $activityPreview['body_key']
                                                    ? __('conversations.row.'.$activityPreview['body_key'])
                                                    : $activityPreview['body'];
                                                $previewLabel = __('conversations.row.'.$activityPreview['label_key']);
                                                $waitLabel = $conversationTiming['wait_since']
                                                    ? __('conversations.row.'.$conversationTiming['wait_key'], [
                                                        'elapsed' => $conversationTiming['wait_key'] === 'closed'
                                                            ? $conversationTiming['wait_since']->diffForHumans()
                                                            : $conversation->elapsedWaitFrom($conversationTiming['wait_since']),
                                                    ])
                                                    : __('conversations.row.'.$conversationTiming['wait_key']);
                                            @endphp
                                            <span class="wf-queue-preview" title="{{ $previewBody }}">
                                                <x-support-code-reference
                                                    :code="$conversation->support_code"
                                                    :href="route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code])"
                                                />
                                                &middot; {{ $previewLabel }}@if ($activityPreview['occurred_at']) &middot; <time datetime="{{ $activityPreview['occurred_at']->toJSON() }}">{{ __('conversations.row.activity', ['elapsed' => $activityPreview['occurred_at']->diffForHumans()]) }}</time>@endif &middot; {{ $previewBody }}
                                            </span>
                                        </td>
                                        <td>
                                            <a class="wf-queue-site" href="{{ route('dashboard.sites.show', $conversation->site) }}">
                                                <span class="wf-site-dot" style="background: var({{ $conversation->site->resolvedColor()->cssVariable() }})" aria-hidden="true"></span>
                                                <span lang="">{{ $conversation->site->name }}</span>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="wf-queue-assignee" title="{{ $visitorLabel }}">{{ Str::limit($visitorLabel, 22) }}</span>
                                        </td>
                                        <td>
                                            <span class="wf-queue-state" @if ($needsReply) data-tone="waiting" @endif>
                                                <i aria-hidden="true"></i>{{ __('conversations.row.attention_'.$conversation->attentionState()) }}
                                            </span>

                                            {{-- Marks only for what is actually true. A quiet visitor and an
                                                 unavailable cobrowse are the resting states of nearly every
                                                 row, and printing them on all of them says nothing. --}}
                                            <span class="wf-queue-marks">
                                                @if (in_array($presenceState, ['active', 'recent'], true))
                                                    <span class="wf-queue-mark" data-tone="live">
                                                        <i aria-hidden="true"></i>{{ $conversation->visitor ? __('presence.'.($conversation->visitor->presenceState() === 'unknown' ? 'not_reported' : $conversation->visitor->presenceState())) : '' }}
                                                    </span>
                                                @endif
                                            </span>
                                        </td>
                                        <td>
                                            @if ($conversation->hasNewActivityFor($agent))
                                                <span class="wf-queue-unread">{{ __('conversations.row.'.$conversation->readStateKeyFor($agent)) }}</span>
                                            @else
                                                <span class="wf-queue-cobrowse">{{ __('conversations.row.'.$conversation->readStateKeyFor($agent)) }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @php
                                                // Every string CobrowseConsentState supplies is still English --
                                                // the recorded exception in docs/product/dashboard-language.md --
                                                // and it is being rendered inside a region marked with the agent's
                                                // language, so each piece has to say what it actually is.
                                                //
                                                // The label, message and guidance are wholly English, so the
                                                // element carrying them is marked. The two below are mixed: a
                                                // German label wrapping an English value, in one sentence whose
                                                // word order the catalogue owns. Splitting the sentence to wrap
                                                // the value would be exactly the fragment concatenation this
                                                // extraction refuses, so the marked value is passed IN as the
                                                // placeholder -- escaped here, with only our own catalogue string
                                                // rendered unescaped around it.
                                                $marked = fn (string $value, string $language): string => '<span lang="'
                                                    .e(str_replace('_', '-', $language))
                                                    .'">'.e($value).'</span>';

                                                // `pressure` is always English: English words, and an English
                                                // pluraliser building "2 dropped batches".
                                                $englishValue = \App\Support\DashboardLanguage::FALLBACK;

                                                // `last_report` is NOT. It is the static "Not reported" only in
                                                // the `unavailable` state; every other state builds it with
                                                // `diffForHumans()`, which follows the page's locale and returns
                                                // "vor 20 Sekunden" here. Marking that English would have a
                                                // screen reader pronounce German as English -- the same defect as
                                                // leaving it unmarked, pointing the other way.
                                                //
                                                // Decided from the STATE rather than by comparing the prose,
                                                // which is what the `in_array` below still does and should not.
                                                $lastReportValue = ($cobrowseTransport['state'] ?? null) === 'unavailable'
                                                    ? $englishValue
                                                    : app()->getLocale();
                                            @endphp
                                            <span
                                                class="wf-queue-cobrowse"
                                                lang="{{ str_replace('_', '-', \App\Support\DashboardLanguage::FALLBACK) }}"
                                                @if ($cobrowseTransport['tone'] !== 'manual')
                                                    data-tone="{{ $cobrowseTransport['tone'] === 'ready' ? 'live' : 'attention' }}"
                                                @endif
                                                title="{{ $cobrowseTransport['message'] }} {{ $cobrowseTransport['guidance'] }}"
                                            >{{ $cobrowseTransport['label'] }}</span>
                                            <span class="wf-queue-preview">
                                                {{-- The `in_array` below compares against English prose and will
                                                     need to move to a state key when that vocabulary is
                                                     extracted. --}}
                                                {!! __('conversations.row.last_report', ['value' => $marked($cobrowseTransport['last_report'], $lastReportValue)]) !!}@if (! in_array($cobrowseTransport['pressure'], ['No drops reported', 'No recent drops reported'], true)) &middot; {!! __('conversations.row.pressure', ['value' => $marked($cobrowseTransport['pressure'], $englishValue)]) !!}@endif
                                            </span>
                                        </td>
                                        <td>
                                            <span class="wf-queue-assignee" @if (! $conversation->assignedAgent) data-unassigned="true" @endif>
                                                {{ $conversation->assignedAgent?->name ?? __('conversations.row.unassigned_agent') }}
                                            </span>
                                        </td>
                                        <td class="wf-queue-when">
                                            {{ __('conversations.row.opened', ['elapsed' => $conversationTiming['opened_at']->diffForHumans()]) }}
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
