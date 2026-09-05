<x-layouts.app :title="__('conversations.document_title')" :agent="$agent" :account="$account">
            {{-- One whole sentence per language, not a clause glued to ' for '. --}}
            <x-page-header
                :title="__('conversations.document_title')"
                :subtitle="$conversationFilter === 'closed'
                    ? __('conversations.page_title_closed', ['account' => $account->name])
                    : __('conversations.page_title_active', ['account' => $account->name])" />

            @if (session('conversation_bulk_status'))
                @php
                    $bulkStatus = session('conversation_bulk_status');
                @endphp
                <div class="status-message wf-bulk-result" role="status">
                    <span>
                        @if ($bulkStatus['key'] === 'conversations.bulk.flash.applied')
                            {{ __('conversations.bulk.flash.applied', ['changed' => $bulkStatus['changed'], 'selected' => $bulkStatus['selected']]) }}
                        @else
                            {{ __('conversations.bulk.flash.undone', ['reverted' => $bulkStatus['reverted'], 'skipped' => $bulkStatus['skipped']]) }}
                        @endif
                    </span>
                    @if (($bulkStatus['run_id'] ?? null) !== null)
                        <form method="POST" action="{{ route('dashboard.conversations.bulk.undo', $bulkStatus['run_id']) }}">
                            @csrf
                            <button class="button secondary" type="submit">{{ __('conversations.bulk.undo.submit') }}</button>
                        </form>
                    @endif
                </div>
            @endif

            @if (session('conversation_bulk_error'))
                <p class="field-error" role="alert">{{ __(session('conversation_bulk_error')) }}</p>
            @endif

            @if ($errors->hasAny(['conversation_ids', 'conversation_ids.*', 'action', 'value']))
                <div class="field-error" role="alert">
                    @foreach ($errors->getMessages() as $field => $messages)
                        @if ($field === 'conversation_ids' || str_starts_with($field, 'conversation_ids.') || in_array($field, ['action', 'value'], true))
                            @foreach ($messages as $message)
                                <p>{{ $message }}</p>
                            @endforeach
                        @endif
                    @endforeach
                </div>
            @endif

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
                            data-agent-shortcut-search-primary
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

                {{-- Only when the cap actually bit. A queue that fits says
                     nothing, which is every queue until a desk gets busy. --}}
                @if ($conversationsShownOf > $conversations->count())
                    <p class="wf-queue-summary" role="status">
                        {{ __('conversations.summary.capped_notice', [
                            'shown' => \App\Support\ReaderNumber::count($conversations->count()),
                            'total' => \App\Support\ReaderNumber::count($conversationsShownOf),
                        ]) }}
                    </p>
                @endif

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
                    @if ($canManageConversations)
                        <form method="POST" action="{{ route('dashboard.conversations.bulk.preview') }}" data-queue-bulk-form data-queue-bulk-no-value-actions="close" data-conversation-bulk-form>
                            @csrf
                            @foreach ($conversationQuery as $queryKey => $queryValue)
                                <input type="hidden" name="return_query[{{ $queryKey }}]" value="{{ $queryValue }}">
                            @endforeach

                            <div class="wf-bulk-toolbar" role="group" aria-label="{{ __('conversations.bulk.region') }}">
                                <strong data-queue-selected-count data-none="{{ __('conversations.bulk.selected.none') }}" data-one="{{ __('conversations.bulk.selected.one') }}" data-many="{{ __('conversations.bulk.selected.many', ['count' => '__COUNT__']) }}" data-conversation-selected-count>
                                    {{ __('conversations.bulk.selected.none') }}
                                </strong>

                                <label for="conversation_bulk_action">{{ __('conversations.bulk.action_label') }}</label>
                                <select id="conversation_bulk_action" name="action" data-queue-bulk-action data-conversation-bulk-action>
                                    <option value="">{{ __('conversations.bulk.choose_action') }}</option>
                                    <option value="assign_agent">{{ __('conversations.bulk.actions.assign_agent') }}</option>
                                    <option value="set_priority">{{ __('conversations.bulk.actions.set_priority') }}</option>
                                    <option value="set_status">{{ __('conversations.bulk.actions.set_status') }}</option>
                                    <option value="close">{{ __('conversations.bulk.actions.close') }}</option>
                                </select>

                                <label class="sr-only" for="conversation_bulk_assign_agent_value" data-queue-bulk-value-label="assign_agent" hidden>{{ __('conversations.bulk.values.agent') }}</label>
                                <select id="conversation_bulk_assign_agent_value" name="value" data-queue-bulk-value="assign_agent" disabled hidden>
                                    <option value="">{{ __('conversations.bulk.values.choose_agent') }}</option>
                                    @foreach ($bulkActionAgents as $bulkAgent)
                                        <option value="{{ $bulkAgent->id }}">{{ $bulkAgent->name }}</option>
                                    @endforeach
                                </select>

                                <label class="sr-only" for="conversation_bulk_priority_value" data-queue-bulk-value-label="set_priority" hidden>{{ __('conversations.bulk.values.priority') }}</label>
                                <select id="conversation_bulk_priority_value" name="value" data-queue-bulk-value="set_priority" disabled hidden>
                                    <option value="">{{ __('conversations.bulk.values.choose_priority') }}</option>
                                    @foreach (array_keys(\App\Enums\TicketPriority::guidanceOptions()) as $bulkPriority)
                                        <option value="{{ $bulkPriority }}">{{ __('tickets.priorities.'.$bulkPriority) }}</option>
                                    @endforeach
                                </select>

                                <label class="sr-only" for="conversation_bulk_status_value" data-queue-bulk-value-label="set_status" hidden>{{ __('conversations.bulk.values.status') }}</label>
                                <select id="conversation_bulk_status_value" name="value" data-queue-bulk-value="set_status" disabled hidden>
                                    <option value="">{{ __('conversations.bulk.values.choose_status') }}</option>
                                    <option value="open">{{ __('conversations.detail.statuses.open') }}</option>
                                </select>

                                <button class="button" type="submit" data-queue-bulk-review disabled>{{ __('conversations.bulk.review') }}</button>
                                <button class="button secondary" type="button" data-queue-bulk-clear disabled>{{ __('conversations.bulk.clear') }}</button>
                            </div>
                    @endif

                    <div class="table-wrap">
                        <table class="wf-queue" data-agent-shortcut-queue>
                            <thead>
                                <tr>
                                    @if ($canManageConversations)
                                        <th class="wf-queue-select" scope="col">
                                            <input type="checkbox" data-queue-select-all data-conversation-select-all aria-label="{{ __('conversations.bulk.select_all') }}">
                                            <span class="sr-only" aria-hidden="true">{{ __('conversations.bulk.selection') }}</span>
                                        </th>
                                    @endif
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
                                        $slaState = $slaStateByConversationId->get($conversation->id);
                                        $cobrowseTransport = $cobrowseTransportByConversationId->get($conversation->id, [
                                            'label' => 'Unavailable',
                                            'message' => 'Cobrowse transport is not active.',
                                            'last_report_reported' => false,
                                            'pressure' => 'No drops reported',
                                            'guidance' => 'Wait for an active cobrowse session before relying on cobrowse.',
                                            'tone' => 'manual',
                                        ]);
                                        $presenceState = $conversation->visitor?->presenceState();
                                        $needsReply = $conversation->attentionState() !== 'waiting_on_visitor';
                                        // The visitor's own words in the first three
                                        // branches and OURS in the fourth, so the reset
                                        // has to follow the branch rather than the
                                        // element: marking it unconditionally announces
                                        // our fallback as unknown.
                                        $visitorGaveTheirOwn = filled($conversation->visitor?->name
                                            ?: $conversation->visitor?->email
                                            ?: $conversation->visitor?->anonymous_id);
                                        $visitorLabel = $conversation->visitor?->name
                                            ?: $conversation->visitor?->email
                                            ?: $conversation->visitor?->anonymous_id
                                            ?: __('conversations.row.unknown_visitor');
                                    @endphp
                                    <tr data-agent-shortcut-row @if ($canManageConversations) data-queue-bulk-row data-conversation-bulk-row @endif>
                                        @if ($canManageConversations)
                                            <td class="wf-queue-select">
                                                <input
                                                    type="checkbox"
                                                    name="conversation_ids[]"
                                                    value="{{ $conversation->id }}"
                                                    data-queue-select
                                                    data-conversation-select
                                                    aria-label="{{ __('conversations.bulk.select_conversation', ['subject' => filled($conversation->subject) ? $conversation->subject : __('conversations.row.untitled')]) }}"
                                                >
                                            </td>
                                        @endif
                                        <td class="wf-queue-subject" style="--wf-row-site: var({{ $conversation->site->resolvedColor()->cssVariable() }})">
                                            <a href="{{ route('dashboard.conversations.show', ['supportCode' => $conversation->support_code, 'from_queue' => '1'] + $conversationQuery) }}" data-agent-shortcut-open>
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
                                            {{-- The `title` takes its language from this
                                                 element, so one attribute covers both. --}}
                                            <span class="wf-queue-assignee" title="{{ $visitorLabel }}" @if ($visitorGaveTheirOwn) lang="" @endif>{{ Str::limit($visitorLabel, 22) }}</span>
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
                                                // The label, message and guidance are translated now, so the
                                                // element carrying them no longer claims English. The two below
                                                // are mixed: a German label wrapping a value that may be English,
                                                // in one sentence whose word order the catalogue owns. Splitting
                                                // the sentence to wrap the value would be exactly the fragment
                                                // concatenation this extraction refuses, so the marked value is
                                                // passed IN as the placeholder -- escaped here, with only our own
                                                // catalogue string rendered unescaped around it.
                                                $marked = fn (string $value, string $language): string => '<span lang="'
                                                    .e(str_replace('_', '-', $language))
                                                    .'">'.e($value).'</span>';

                                                // `pressure` is still English: English words, and an English
                                                // pluraliser building "2 dropped batches". It belongs to
                                                // CobrowseTransportPressure, which is not extracted yet.
                                                $englishValue = \App\Support\DashboardLanguage::FALLBACK;

                                                $transportCopy = $cobrowseTransport['copy'] ?? 'inactive';

                                                // `last_report` is page-locale in BOTH of its branches, so it
                                                // is marked German either way. With a report it is
                                                // `diffForHumans()`, which follows the page's locale and returns
                                                // "vor 20 Sekunden" here; with none it is translated below rather
                                                // than arriving from the model as the literal "Not reported".
                                                //
                                                // It used to be decided from the state, which meant the
                                                // no-report case -- every row with no cobrowse session, so most
                                                // of them -- rendered English and said so. The model's own
                                                // discriminator answers this; the state only happened to agree.
                                                $lastReport = ($cobrowseTransport['last_report_reported'] ?? false)
                                                    ? $cobrowseTransport['last_report']
                                                    : __('cobrowse.units.not_reported');
                                            @endphp
                                            <span
                                                class="wf-queue-cobrowse"
                                                @if ($cobrowseTransport['tone'] !== 'manual')
                                                    data-tone="{{ $cobrowseTransport['tone'] === 'ready' ? 'live' : 'attention' }}"
                                                @endif
                                                title="{{ __('cobrowse.transport.'.$transportCopy.'.message') }} {{ __('cobrowse.transport.'.$transportCopy.'.'.($cobrowseTransport['guidance_copy'] ?? 'guidance')) }}"
                                            >{{ __('cobrowse.transport.'.$transportCopy.'.label') }}</span>
                                            <span class="wf-queue-preview">
                                                {!! __('conversations.row.last_report', ['value' => $marked($lastReport, app()->getLocale())]) !!}@if ($cobrowseTransport['has_pressure'] ?? false) &middot; {{ __('conversations.row.pressure', ['value' => \App\Support\CobrowsePressureSentence::for($cobrowseTransport['pressure_counts'] ?? [])]) }}@endif
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
                    @if ($canManageConversations)
                        </form>
                    @endif
                @endif
            </section>
            <x-queue-bulk-selector-script />
</x-layouts.app>
