<x-layouts.app :title="__('conversations.detail_document_title', ['code' => $conversation->support_code])" :agent="$agent" :account="$account">
            {{-- Whole sentences from the catalogue: a subject is the visitor's own
                 words and is never translated, but the fallback and the support-code
                 line are copy. --}}
            <x-page-header
                :title="filled($conversation->subject) ? $conversation->subject : __('conversations.detail.untitled')"
                :title-lang="filled($conversation->subject) ? '' : null"
                :subtitle="__('conversations.detail.support_code', ['code' => $conversation->support_code])"
                :back-href="$conversationBackUrl"
                :back-label="__('conversations.detail.back')">
                @if ($conversationSiblings['total'] > 1)
                    <x-slot:actions>
                        {{-- Move through the queue without returning to it. The
                             list, the order and the neighbours all come from the
                             same query the queue itself runs. --}}
                        <nav class="wf-switcher" aria-label="{{ __('conversations.detail.nav.move') }}">
                            @if ($conversationSiblings['previous'])
                                <a class="wf-switcher-step" rel="prev" aria-label="{{ __('conversations.detail.nav.previous') }}" data-agent-shortcut-previous
                                   href="{{ route('dashboard.conversations.show', ['supportCode' => $conversationSiblings['previous'], 'from_queue' => '1'] + $conversationReturnQuery) }}">&#8593;</a>
                            @else
                                <span class="wf-switcher-step" aria-hidden="true" data-disabled="true">&#8593;</span>
                            @endif

                            <details class="wf-switcher-list">
                                <summary>
                                    {{ __('conversations.detail.tabs.position', ['position' => $conversationSiblings['position'], 'total' => $conversationSiblings['total']]) }}
                                    <x-icon name="chevron-down" :size="12" />
                                </summary>
                                <div class="wf-switcher-menu">
                                    @foreach ($conversationSiblings['items'] as $sibling)
                                        <a
                                            class="wf-switcher-item"
                                            href="{{ route('dashboard.conversations.show', ['supportCode' => $sibling['support_code'], 'from_queue' => '1'] + $conversationReturnQuery) }}"
                                            @if ($sibling['current']) aria-current="true" @endif
                                        >@if ($sibling['subject_fallback']){{ __('conversations.detail.untitled') }}@else<span lang="">{{ $sibling['subject'] }}</span>@endif</a>
                                    @endforeach
                                </div>
                            </details>

                            @if ($conversationSiblings['next'])
                                <a class="wf-switcher-step" rel="next" aria-label="{{ __('conversations.detail.nav.next') }}" data-agent-shortcut-next
                                   href="{{ route('dashboard.conversations.show', ['supportCode' => $conversationSiblings['next'], 'from_queue' => '1'] + $conversationReturnQuery) }}">&#8595;</a>
                            @else
                                <span class="wf-switcher-step" aria-hidden="true" data-disabled="true">&#8595;</span>
                            @endif
                        </nav>
                    </x-slot:actions>
                @endif
            </x-page-header>

            @if (session('status'))
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            <x-tabs
                id="conversation-workspace"
                :label="__('conversations.detail.tabs.workspace')"
                :tabs="[
                    ['id' => 'conversation', 'label' => __('conversations.detail.tabs.conversation')],
                    // The cobrowse badge is a VALUE from CobrowseConsentState and is
                    // still the recorded exception -- see the panel's own `lang`.
                    // The badge is a VALUE from CobrowseConsentState and sits outside
                    // the panel that declares itself English, so it carries its own.
                    ['id' => 'cobrowse', 'label' => __('conversations.detail.tabs.cobrowse'), 'badge' => isset($cobrowseConsent['transport']['copy']) ? __('cobrowse.transport.'.$cobrowseConsent['transport']['copy'].'.label') : null],
                    ['id' => 'visitor', 'label' => __('conversations.detail.tabs.visitor')],
                    ['id' => 'ticket', 'label' => __('conversations.detail.tabs.ticket'), 'badge' => $tickets->isEmpty() ? null : trans_choice('conversations.detail.tabs.linked_badge', $tickets->count(), ['count' => $tickets->count()])],
                ]"
            >
                <x-tab-panel id="conversation" active>
            {{-- The conversation IS the task: transcript + reply lead the tab. --}}
            @include('agent.conversations.partials.chat-workspace')

            <section class="section" aria-labelledby="conversation-context-heading">
                <div class="section-header">
                    <h2 id="conversation-context-heading">{{ __('conversations.detail.headings.context') }}</h2>
                    <span class="lede">{{ __('conversations.detail.statuses.'.$conversation->status) }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.site') }}</span>
                        <span class="meta-value"><span lang="">{{ $conversation->site->name }}</span></span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.visitor') }}</span>
                        {{-- The identifier the visitor's browser gave, so it follows
                             the branch: ours only when there is no visitor at all. --}}
                        @if ($conversation->visitor->anonymous_id ?? null)
                            <span class="meta-value" lang="">{{ $conversation->visitor->anonymous_id }}</span>
                        @else
                            <span class="meta-value">{{ __('conversations.detail.unknown_visitor') }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.assigned_to') }}</span>
                        <span class="meta-value">{{ $conversation->assignedAgent?->name ?? __('conversations.detail.context.unassigned') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sla.priority') }}</span>
                        <span class="meta-value">{{ __('tickets.priorities.'.$conversation->priority) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.opened') }}</span>
                        <span class="meta-value">{{ $conversation->created_at->diffForHumans() }}</span>
                        <span class="lede">{{ __('conversations.detail.context.last_activity', ['elapsed' => $conversation->last_message_at?->diffForHumans() ?? __('conversations.detail.context.last_activity_none')]) }}</span>
                    </div>
                </div>

                @if ($slaStates->isNotEmpty())
                    <div class="meta-grid" aria-label="{{ __('sla.detail.region') }}">
                        @foreach ($slaStates as $slaState)
                            <div class="meta-item">
                                <span class="meta-label">{{ $slaState['metric_label'] }}</span>
                                <span class="readiness-status" data-status="{{ $slaState['tone'] }}">{{ $slaState['label'] }}</span>
                                <span class="lede">{{ $slaState['detail'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($canUpdateConversation)
                    <div class="section-form-row">
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.priority.update', $conversation->support_code) }}">
                        @csrf
                        @method('PUT')
                        @include('agent.conversations.partials.return-query-fields')
                        <label for="conversation-priority">{{ __('sla.change_priority') }}</label>
                        <select id="conversation-priority" name="priority">
                            @foreach ($ticketPriorities as $priority => $option)
                                <option value="{{ $priority }}" @selected($conversation->priority === $priority)>{{ __('tickets.priorities.'.$priority) }}</option>
                            @endforeach
                        </select>
                        <button class="button secondary" type="submit">{{ __('sla.save_priority') }}</button>
                    </form>
                    @if (! $conversation->assigned_agent_id && $canClaimConversation)
                        <form class="section-form" method="POST" action="{{ route('dashboard.conversations.claim', $conversation->support_code) }}">
                            @csrf
                            @include('agent.conversations.partials.return-query-fields')

                            <button class="button" type="submit" data-agent-shortcut-claim>{{ __('conversations.detail.ticket.claim') }}</button>
                        </form>
                    @elseif ($conversation->assigned_agent_id === $agent->id && $canReleaseConversation)
                        <form class="section-form" method="POST" action="{{ route('dashboard.conversations.release', $conversation->support_code) }}">
                            @csrf
                            @include('agent.conversations.partials.return-query-fields')

                            <button class="button secondary" type="submit">{{ __('conversations.detail.ticket.release') }}</button>
                        </form>
                    @endif

                    <form class="section-form" method="POST" action="{{ route($conversation->status === 'closed' ? 'dashboard.conversations.reopen' : 'dashboard.conversations.close', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button {{ $conversation->status === 'closed' ? '' : 'secondary' }}" type="submit" @if ($conversation->status !== 'closed') data-agent-shortcut-close @endif>
                            {{ $conversation->status === 'closed' ? __('conversations.detail.context.reopen') : __('conversations.detail.context.close') }}
                        </button>
                    </form>
                    </div>
                @endif
            </section>

            @if ($automationMacros->isNotEmpty())
                <section class="section" aria-labelledby="conversation-macros-heading">
                    <div class="section-header">
                        <h2 id="conversation-macros-heading">{{ __('automation_macros.apply.heading') }}</h2>
                        <span class="lede">{{ __('automation_macros.apply.lede') }}</span>
                    </div>
                    <div class="section-form-row">
                        @foreach ($automationMacros as $macro)
                            <form class="section-form" method="POST" action="{{ route('dashboard.conversations.macros.run', [$conversation->support_code, $macro]) }}">
                                @csrf
                                @include('agent.conversations.partials.return-query-fields')
                                <button class="button secondary" type="submit">
                                    {{ __('automation_macros.apply.run') }} <span lang="">{{ $macro->name }}</span>
                                </button>
                                <span class="field-help">{{ trans_choice('automation_macros.apply.action_count', count($macro->actions), ['count' => \App\Support\ReaderNumber::count(count($macro->actions))]) }}</span>
                            </form>
                        @endforeach
                    </div>
                </section>
            @endif
                </x-tab-panel>

                {{-- The cobrowse vocabulary is extracted, so this panel no longer declares
                     itself English: it is an ordinary part of the page again.

                     What is still marked is marked for what it IS rather than for what
                     has not been done -- the visitor's own page title and URLs carry
                     `lang=""`, because they are neither our English nor the agent's
                     German. See docs/product/dashboard-language.md. --}}
                <x-tab-panel id="cobrowse">
            <section class="section" aria-labelledby="cobrowse-heading">
                <div class="section-header">
                    <h2 id="cobrowse-heading">{{ __('conversations.detail.tabs.cobrowse') }}</h2>
                    <span class="lede">{{ __('cobrowse.consent.'.$cobrowseConsent['status'].'.label') }}</span>
                </div>

                <p class="empty">{{ __('cobrowse.consent.'.$cobrowseConsent['status'].'.message') }}</p>

                @if ($cobrowseConsent['snapshot_recovery'])
                    <div
                        class="live-update"
                        data-state="{{ $cobrowseConsent['snapshot_recovery']['status'] }}"
                        data-cobrowse-snapshot-recovery
                        data-pending="{{ $cobrowseConsent['snapshot_recovery']['status'] === 'pending' ? 'true' : 'false' }}"
                    >
                        <div>
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.snapshot_guidance') }}</span>
                            <strong data-cobrowse-snapshot-recovery-label>{{ __('cobrowse.snapshot_recovery.'.$cobrowseConsent['snapshot_recovery']['copy'].'.label') }}</strong>
                            <p class="lede" data-cobrowse-snapshot-recovery-message>{{ __('cobrowse.snapshot_recovery.'.$cobrowseConsent['snapshot_recovery']['copy'].'.message') }}</p>
                        </div>
                    </div>
                @endif

                @if ($canRequestCobrowse && in_array($cobrowseConsent['status'], ['unavailable', 'revoked', 'ended'], true))
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.request', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button" type="submit">{{ __('conversations.detail.cobrowse.request') }}</button>
                    </form>
                @elseif ($canEndCobrowse && in_array($cobrowseConsent['status'], ['pending', 'granted'], true))
                    @if ($cobrowseConsent['status'] === 'granted')
                        @php
                            $resyncStatus = $cobrowseConsent['resync_request']['status'] ?? null;
                            $resyncActionLabel = in_array($resyncStatus, ['delayed', 'exhausted', 'expired'], true)
                                ? __('cobrowse.labels.request_another_snapshot')
                                : __('cobrowse.labels.request_snapshot');
                        @endphp

                        @if ($resyncStatus === 'pending')
                            <form
                                class="section-form"
                                method="POST"
                                action="{{ route('dashboard.conversations.cobrowse.resync', $conversation->support_code) }}"
                                data-resync-retry-form
                                data-retry-at="{{ $cobrowseConsent['resync_request']['retry_at'] ?? '' }}"
                                data-retry-label="{{ __('cobrowse.labels.request_another_snapshot') }}"
                                data-retry-ready-help="{{ __('cobrowse.labels.retry_ready_help') }}"
                                data-retry-ready-recovery="{{ __('cobrowse.labels.retry_ready_recovery') }}"
                            >
                                @csrf
                                @include('agent.conversations.partials.return-query-fields')

                                <button class="button secondary" type="submit" disabled data-resync-retry-button>{{ __('conversations.detail.cobrowse.fresh_requested') }}</button>
                                <p class="field-help" data-resync-retry-help>{{ __('conversations.detail.cobrowse.fresh_waiting') }}</p>
                            </form>
                        @else
                            <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.resync', $conversation->support_code) }}">
                                @csrf
                                @include('agent.conversations.partials.return-query-fields')

                                <button class="button secondary" type="submit">{{ $resyncActionLabel }}</button>
                            </form>
                        @endif
                    @endif
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.cobrowse.end', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <button class="button secondary" type="submit">
                            {{ $cobrowseConsent['status'] === 'pending' ? __('cobrowse.actions.cancel_request') : __('cobrowse.actions.end') }}
                        </button>
                    </form>
                @endif

                @if ($cobrowseConsent['resync_request'])
                    <div class="live-update" data-state="{{ $cobrowseConsent['resync_request']['status'] }}">
                        <div>
                            <strong>{{ __('cobrowse.resync.'.$cobrowseConsent['resync_request']['status'].'.label') }}</strong>
                            <p class="lede">{{ __('cobrowse.resync.'.$cobrowseConsent['resync_request']['status'].'.message') }}</p>
                        </div>
                        <span class="lede">
                            {{ __('cobrowse.labels.requested_by', ['actor' => $cobrowseConsent['resync_request']['requested_by']]) }}
                            <span lang="{{ str_replace('_', '-', $cobrowseConsent['resync_request']['requested_at_language'] ?? \App\Support\DashboardLanguage::FALLBACK) }}">{{ $cobrowseConsent['resync_request']['requested_at'] }}</span>
                            @if (filled($cobrowseConsent['resync_request']['fulfilled_at'] ?? null))
                                <br>
                                {!! __('cobrowse.labels.received', ['elapsed' => '<span lang="'.e(str_replace('_', '-', $cobrowseConsent['resync_request']['fulfilled_at_language'] ?? \App\Support\DashboardLanguage::FALLBACK)).'">'.e($cobrowseConsent['resync_request']['fulfilled_at']).'</span>']) !!}
                            @endif
                            @if (filled($cobrowseConsent['resync_request']['expires_at'] ?? null))
                                <br>
                                {!! __('cobrowse.labels.expires', ['elapsed' => '<span lang="'.e(str_replace('_', '-', $cobrowseConsent['resync_request']['expires_at_language'] ?? \App\Support\DashboardLanguage::FALLBACK)).'">'.e($cobrowseConsent['resync_request']['expires_at']).'</span>']) !!}
                            @endif
                            @if (filled($cobrowseConsent['resync_request']['expired_at'] ?? null))
                                <br>
                                {!! __('cobrowse.labels.expired', ['elapsed' => '<span lang="'.e(str_replace('_', '-', $cobrowseConsent['resync_request']['expired_at_language'] ?? \App\Support\DashboardLanguage::FALLBACK)).'">'.e($cobrowseConsent['resync_request']['expired_at']).'</span>']) !!}
                            @endif
                        </span>
                    </div>

                @endif

                @if ($cobrowseConsent['replay_preview'])
                    <div class="section-header">
                        <strong>{{ __('conversations.detail.cobrowse.replay_preview') }}</strong>
                        <span class="lede">
                            <span data-cobrowse-replay-applied>{{ __('cobrowse.units.applied', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['replay_preview']['applied_mutations_value'])]) }}</span>
                            /
                            <span data-cobrowse-replay-skipped>{{ __('cobrowse.units.skipped', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['replay_preview']['skipped_mutations_value'])]) }}</span>
                        </span>
                        @if ($cobrowseConsent['replay_preview']['viewport_width'])
                            <span class="lede" data-cobrowse-viewport-label>{{ __('cobrowse.units.viewport', ['width' => \App\Support\ReaderNumber::count($cobrowseConsent['replay_preview']['viewport_width'])]) }}</span>
                        @else
                            <span class="lede" data-cobrowse-viewport-label hidden></span>
                        @endif
                        <span
                            class="readiness-status"
                            data-status="{{ $cobrowseConsent['replay_preview']['drift']['tone'] }}"
                            data-cobrowse-replay-drift-status
                        >{{ __('cobrowse.drift.'.$cobrowseConsent['replay_preview']['drift']['state'].'.label') }}</span>
                    </div>

                    <p
                        class="lede realtime-note"
                        data-cobrowse-replay-drift-message
                        data-recommend-resync="{{ $cobrowseConsent['replay_preview']['drift']['recommend_resync'] ? 'true' : 'false' }}"
                        @unless ($cobrowseConsent['replay_preview']['drift']['state'] !== 'steady') hidden @endunless
                    >{{ __('cobrowse.drift.'.$cobrowseConsent['replay_preview']['drift']['state'].'.message') }} ({{ __('cobrowse.drift.summary', $cobrowseConsent['replay_preview']['drift']['summary_counts']) }})</p>

                    <div class="cobrowse-preview-frame">
                        <div class="cobrowse-preview-scale">
                            {{-- The title is translated, but an element cannot go inside an
                                 attribute: the span's own quote closes `title` early and every
                                 attribute after it -- sandbox, srcdoc -- is parsed as text, which
                                 blanks the preview. An attribute takes its language from its
                                 element, so `lang` goes on the iframe. --}}
                            <iframe
                                class="cobrowse-preview"
                                lang="{{ str_replace('_', '-', app()->getLocale()) }}"
                                title="{{ __('conversations.detail.cobrowse.replay_heading') }}"
                                sandbox
                                srcdoc="{{ $cobrowseConsent['replay_preview']['srcdoc'] }}"
                                data-cobrowse-replay-frame
                                @if ($cobrowseConsent['replay_preview']['viewport_width']) data-viewport-width="{{ $cobrowseConsent['replay_preview']['viewport_width'] }}" @endif
                            ></iframe>
                        </div>
                    </div>

                    <script>
                        (function () {
                            // Render the sandboxed preview at the visitor's reported viewport
                            // width, scaled down to fit the dashboard column (never scaled up),
                            // so captured layout keeps the visitor's real proportions instead
                            // of wrapping at whatever width the column happens to be.
                            function sizeCobrowsePreview() {
                                var frame = document.querySelector('[data-cobrowse-replay-frame]');

                                if (!frame || !frame.parentElement) {
                                    return;
                                }

                                var wrap = frame.parentElement;
                                var viewportWidth = parseInt(frame.getAttribute('data-viewport-width') || '', 10);

                                if (!viewportWidth || viewportWidth <= 0 || wrap.clientWidth <= 0) {
                                    frame.style.width = '';
                                    frame.style.height = '';
                                    frame.style.transform = '';

                                    return;
                                }

                                var scale = Math.min(1, wrap.clientWidth / viewportWidth);

                                frame.style.width = viewportWidth + 'px';
                                frame.style.height = Math.round(wrap.clientHeight / scale) + 'px';
                                frame.style.transform = 'scale(' + scale + ')';
                            }

                            // Chrome can leave the transform-scaled sandboxed iframe
                            // unpainted after parsing its srcdoc: the layer gets promoted
                            // but never rasterized, so the preview sits blank while every
                            // diagnostic reads live. Rebuilding the box across a forced
                            // reflow repaints it, but only when BOTH conditions hold: the
                            // srcdoc document has finished loading (nudging mid-parse does
                            // nothing) AND the frame is in the viewport (nudging offscreen
                            // does nothing, and the preview sits below the fold on a cold
                            // page load). So every frame `load` arms a needs-repaint flag
                            // and an IntersectionObserver fires the toggle at the first
                            // moment the loaded frame is actually visible — immediately for
                            // live swaps the agent is watching, on scroll-in otherwise.
                            // Lives in this always-rendered block so non-realtime installs
                            // are covered. CSS zoom instead of transform would avoid the
                            // stall entirely but re-lays-out the inner document at the
                            // zoomed box width, losing the visitor's true viewport geometry.
                            var repaintFrame = document.querySelector('[data-cobrowse-replay-frame]');
                            var previewNeedsRepaint = true;

                            function repaintCobrowsePreview() {
                                if (!repaintFrame) {
                                    return;
                                }

                                repaintFrame.style.display = 'none';
                                void repaintFrame.offsetHeight;
                                repaintFrame.style.display = '';
                                previewNeedsRepaint = false;
                            }

                            function frameIsOnScreen() {
                                var rect = repaintFrame.getBoundingClientRect();
                                var viewportHeight = window.innerHeight || document.documentElement.clientHeight;

                                return rect.bottom > 0 && rect.top < viewportHeight;
                            }

                            // The toggle must only run (and clear the flag) when the frame
                            // is on screen RIGHT NOW: observer callbacks can deliver stale
                            // queued entries (a fast scroll past the preview queues an
                            // intersecting entry followed by a non-intersecting one), so
                            // visibility is always re-read from live geometry at toggle time.
                            function attemptPreviewRepaint() {
                                if (previewNeedsRepaint && frameIsOnScreen()) {
                                    repaintCobrowsePreview();
                                }
                            }

                            if (repaintFrame) {
                                repaintFrame.addEventListener('load', function () {
                                    previewNeedsRepaint = true;
                                    attemptPreviewRepaint();
                                });

                                if (typeof IntersectionObserver === 'function') {
                                    new IntersectionObserver(attemptPreviewRepaint).observe(repaintFrame);
                                } else {
                                    attemptPreviewRepaint();
                                }
                            }

                            // Inside a hidden tab panel the wrapper measures 0 wide, so the
                            // scale can only be computed once the Cobrowse tab is revealed.
                            // The repaint machinery follows on its own: the reveal makes the
                            // frame intersect, which fires the deferred repaint.
                            window.addEventListener('wayfindr:tab-shown', function (event) {
                                if (event.detail && event.detail.panel === 'cobrowse') {
                                    sizeCobrowsePreview();
                                }
                            });

                            window.wayfindrSizeCobrowsePreview = sizeCobrowsePreview;
                            window.addEventListener('resize', sizeCobrowsePreview);
                            sizeCobrowsePreview();
                        })();
                    </script>
                @else
                    <p class="empty realtime-note" data-cobrowse-replay-empty>{{ __('conversations.detail.cobrowse.no_replay') }}</p>
                @endif

                @if ($cobrowseConsent['transport'])
                    @php
                        $transportRecoveryLocked = ($cobrowseConsent['resync_request']['status'] ?? null) === 'pending';
                        $transportCopy = $cobrowseConsent['transport']['copy'] ?? 'inactive';
                        $transportRecoveryAction = $transportRecoveryLocked
                            ? __('cobrowse.transport.recovery_locked')
                            : __('cobrowse.transport.'.$transportCopy.'.recovery_action');
                    @endphp

                    <div class="section-header" data-cobrowse-transport-panel data-state="{{ $cobrowseConsent['transport']['state'] }}">
                        <strong>{{ __('conversations.detail.cobrowse.transport_health') }}</strong>
                        <span class="lede" data-cobrowse-transport-label>{{ __('cobrowse.transport.'.$transportCopy.'.label') }}</span>
                    </div>

                    <p class="empty realtime-note" data-cobrowse-transport-message>{{ __('cobrowse.transport.'.$transportCopy.'.message') }}</p>
                @endif

                @if ($realtime)
                    <div class="live-update" data-cobrowse-update-panel data-state="idle">
                        <div>
                            <strong>{{ __('conversations.detail.cobrowse.updates') }}</strong>
                            <p class="lede" data-cobrowse-update-status>{{ __('conversations.detail.cobrowse.waiting') }}</p>
                        </div>
                        <button class="button secondary" type="button" data-cobrowse-refresh hidden>{{ __('conversations.detail.cobrowse.refresh_preview') }}</button>
                    </div>
                @endif

                {{-- Everything below is session diagnostics: useful when debugging a
                     specific cobrowse session, ambient noise otherwise. Collapsed by
                     default; the realtime script's targets stay in the DOM. --}}
                <x-details-disclosure :summary="__('conversations.detail.context.session_diagnostics')" :summary-lang="app()->getLocale()" data-cobrowse-diagnostics>
                    @if ($cobrowseConsent['lifecycle'])
                        <div class="section-header">
                            <strong>{{ __('conversations.detail.cobrowse.session_timeline') }}</strong>
                        </div>

                        <div class="meta-grid realtime-grid">
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.requested_by') }}</span>
                                <span class="meta-value">@if ($cobrowseConsent['lifecycle']['requested_by_copy']){{ __('cobrowse.units.'.$cobrowseConsent['lifecycle']['requested_by_copy']) }}@else{{ $cobrowseConsent['lifecycle']['requested_by'] }}@endif</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.requested') }}</span>
                                <span class="meta-value"><span lang="{{ str_replace('_', '-', $cobrowseConsent['lifecycle']['requested_at_language'] ?? \App\Support\DashboardLanguage::FALLBACK) }}">{{ $cobrowseConsent['lifecycle']['requested_at'] }}</span></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.consent') }}</span>
                                <span class="meta-value">@if ($cobrowseConsent['lifecycle']['consented_at_copy']){{ __('cobrowse.units.'.$cobrowseConsent['lifecycle']['consented_at_copy']) }}@else<x-lang :is="$cobrowseConsent['lifecycle']['consented_at_language']">{{ $cobrowseConsent['lifecycle']['consented_at'] }}</x-lang>@endif</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.stopped') }}</span>
                                <span class="meta-value">@if ($cobrowseConsent['lifecycle']['ended_at_copy']){{ __('cobrowse.units.'.$cobrowseConsent['lifecycle']['ended_at_copy']) }}@else<x-lang :is="$cobrowseConsent['lifecycle']['ended_at_language']">{{ $cobrowseConsent['lifecycle']['ended_at'] }}</x-lang>@endif</span>
                            </div>
                            @if ($cobrowseConsent['lifecycle']['has_ended'])
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('conversations.detail.cobrowse.stopped_by') }}</span>
                                    <span class="meta-value">@if ($cobrowseConsent['lifecycle']['ended_by_copy']){{ __('cobrowse.units.'.$cobrowseConsent['lifecycle']['ended_by_copy']) }}@else{{ $cobrowseConsent['lifecycle']['ended_by'] }}@endif</span>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if (! empty($cobrowseConsent['resync_request']['recovery_timeline'] ?? []))
                        <div class="section-header">
                            <strong>{{ __('conversations.detail.cobrowse.recovery_timeline') }}</strong>
                            <span class="lede">{{ __('conversations.detail.cobrowse.fresh_path') }}</span>
                        </div>

                        <div class="timeline-list">
                            @foreach ($cobrowseConsent['resync_request']['recovery_timeline'] as $timelineItem)
                                <article class="timeline-item internal-note" data-recovery-state="{{ $timelineItem['state'] }}">
                                    <div class="timeline-content">
                                        <strong>{{ __('cobrowse.timeline.'.$timelineItem['copy'].'.label') }}</strong>
                                        <p class="message-body">{{ $timelineItem['copy'] === 'ignored'
                                            ? __('cobrowse.timeline.ignored.'.(in_array($timelineItem['replace']['reason'] ?? '', ['expired', 'mismatched', 'already_fulfilled'], true) ? $timelineItem['replace']['reason'] : 'unmatched'))
                                            : __('cobrowse.timeline.'.$timelineItem['copy'].'.'.($timelineItem['detail_copy'] ?? 'detail'), $timelineItem['replace'] ?? []) }}</p>
                                        <div class="timeline-meta">
                                            <span lang="{{ str_replace('_', '-', $timelineItem['occurred_at_language'] ?? \App\Support\DashboardLanguage::FALLBACK) }}">{{ $timelineItem['occurred_at'] }}</span>
                                            <span>{{ __('cobrowse.timeline.'.$timelineItem['copy'].'.badge') }}</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif

                    @if ($cobrowseConsent['transport'])
                        <div class="section-header">
                            <strong>{{ __('conversations.detail.cobrowse.transport_detail') }}</strong>
                        </div>

                        <div class="meta-grid realtime-grid">
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.state') }}</span>
                                <span class="meta-value" data-cobrowse-transport-state-label>{{ __('cobrowse.transport.'.$transportCopy.'.label') }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.last_report') }}</span>
                                {{-- Both branches are page-locale: diffForHumans() follows the request
                                     locale, and the not-reported case is translated here rather than
                                     arriving as a literal. No marker, which also survives the realtime
                                     handler assigning textContent to this element. --}}
                                <span class="meta-value" data-cobrowse-transport-last-report>{{ $cobrowseConsent['transport']['last_report_reported']
                                    ? $cobrowseConsent['transport']['last_report']
                                    : __('cobrowse.units.not_reported') }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.reconnects') }}</span>
                                <span class="meta-value" data-cobrowse-transport-reconnects>{{ \App\Support\ReaderNumber::count($cobrowseConsent['transport']['reconnects_value'] ?? 0) }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.pressure') }}</span>
                                <span class="meta-value" data-cobrowse-transport-pressure><x-cobrowse-pressure :counts="$cobrowseConsent['transport']['pressure_counts']" /></span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.guidance') }}</span>
                                <span class="meta-value" data-cobrowse-transport-guidance>{{ __('cobrowse.transport.'.$transportCopy.'.'.($cobrowseConsent['transport']['guidance_copy'] ?? 'guidance')) }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('conversations.detail.cobrowse.recovery_action') }}</span>
                                <span
                                    class="meta-value"
                                    data-cobrowse-transport-recovery
                                    data-recovery-locked="{{ $transportRecoveryLocked ? 'true' : 'false' }}"
                                >{{ $transportRecoveryAction }}</span>
                            </div>
                        </div>
                    @endif

                @if ($cobrowseConsent['page_state'])
                    <div class="section-header">
                        <strong>{{ __('conversations.detail.cobrowse.visitor_page') }}</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.ticket.title') }}</span>
                            <span class="meta-value">@if ($cobrowseConsent['page_state']['title_reported'])<span lang="">{{ $cobrowseConsent['page_state']['title'] }}</span>@else{{ __('cobrowse.units.untitled_page') }}@endif</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.url') }}</span>
                            <span class="meta-value">@if ($cobrowseConsent['page_state']['page_url_reported'])<span lang="">{{ $cobrowseConsent['page_state']['page_url'] }}</span>@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.viewport') }}</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['viewport'] ?? __('cobrowse.units.not_reported') }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.scroll') }}</span>
                            <span class="meta-value">{{ $cobrowseConsent['page_state']['scroll'] ?? __('cobrowse.units.not_reported') }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.reply.visibility_label') }}</span>
                            <span class="meta-value">{{ __('cobrowse.visibility.'.(in_array($cobrowseConsent['page_state']['visibility_state'], ['visible', 'hidden', 'prerender'], true)
                                    ? $cobrowseConsent['page_state']['visibility_state']
                                    : 'unknown')) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.focus') }}</span>
                            <span class="meta-value">{{ __('cobrowse.units.'.$cobrowseConsent['page_state']['focus_copy']) }}</span>
                        </div>
                    </div>
                @else
                    <p class="empty realtime-note">{{ __('conversations.detail.context.no_page_state') }}</p>
                @endif

                @if ($cobrowseConsent['snapshot'])
                    <div class="section-header">
                        <strong>{{ __('conversations.detail.cobrowse.page_snapshot') }}</strong>
                        <span
                            class="readiness-status"
                            data-status="{{ $cobrowseConsent['snapshot']['freshness']['tone'] }}"
                            data-cobrowse-snapshot-status
                        >{{ __('cobrowse.freshness.'.$cobrowseConsent['snapshot']['freshness']['state'].'.label') }}</span>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.snapshot_freshness') }}</span>
                            <span class="meta-value" data-cobrowse-snapshot-freshness-label>{{ __('cobrowse.freshness.'.$cobrowseConsent['snapshot']['freshness']['state'].'.label') }}</span>
                            <span class="lede" data-cobrowse-snapshot-freshness-message>{{ __('cobrowse.freshness.'.$cobrowseConsent['snapshot']['freshness']['state'].'.message') }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.reported') }}</span>
                            <span class="meta-value" data-cobrowse-snapshot-freshness-reported>{{ ($cobrowseConsent['snapshot']['freshness']['reported_elapsed'] ?? null) === null
                                ? __('cobrowse.freshness.reported_unknown')
                                : __('cobrowse.freshness.reported', ['elapsed' => $cobrowseConsent['snapshot']['freshness']['reported_elapsed']]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.ticket.title') }}</span>
                            <span class="meta-value">@if ($cobrowseConsent['snapshot']['title_reported'])<span lang="">{{ $cobrowseConsent['snapshot']['title'] }}</span>@else{{ __('cobrowse.units.untitled_page') }}@endif</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.url') }}</span>
                            <span class="meta-value">@if ($cobrowseConsent['snapshot']['page_url_reported'])<span lang="">{{ $cobrowseConsent['snapshot']['page_url'] }}</span>@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.nodes') }}</span>
                            <span class="meta-value">{{ trans_choice('cobrowse.units.nodes', $cobrowseConsent['snapshot']['node_count_value'], ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['snapshot']['node_count_value'])]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.masked') }}</span>
                            <span class="meta-value">{{ __('cobrowse.units.masked', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['snapshot']['masked_count_value'])]) }}</span>
                        </div>
                    </div>

                    <div class="message-list">
                        <article class="message">
                            <p class="message-body">@if ($cobrowseConsent['snapshot']['text_reported'])<span lang="">{{ $cobrowseConsent['snapshot']['text'] }}</span>@else{{ __('cobrowse.units.no_text_preview') }}@endif</p>
                        </article>
                    </div>
                @else
                    <p class="empty realtime-note">{{ __('conversations.detail.cobrowse.no_snapshot') }}</p>
                @endif

                @if ($cobrowseConsent['mutation_stream'])
                    <div class="section-header">
                        <strong>{{ __('conversations.detail.cobrowse.mutation_stream') }}</strong>
                    </div>

                    <div class="meta-grid realtime-grid">
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.batches') }}</span>
                            <span class="meta-value">{{ trans_choice('cobrowse.units.batches', $cobrowseConsent['mutation_stream']['batch_count_value'], ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['mutation_stream']['batch_count_value'])]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.mutations') }}</span>
                            <span class="meta-value">{{ trans_choice('cobrowse.units.mutations', $cobrowseConsent['mutation_stream']['mutation_count_value'], ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['mutation_stream']['mutation_count_value'])]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.dropped') }}</span>
                            <span class="meta-value">{{ __('cobrowse.units.dropped', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['mutation_stream']['dropped_count_value'])]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.skipped') }}</span>
                            <span class="meta-value">{{ __('cobrowse.units.skipped', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['mutation_stream']['skipped_count_value'])]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.last_sequence') }}</span>
                            <span class="meta-value">{{ __('cobrowse.units.sequence', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['mutation_stream']['last_sequence_value'])]) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.cobrowse.url') }}</span>
                            <span class="meta-value">@if ($cobrowseConsent['mutation_stream']['last_page_url_reported'])<span lang="">{{ $cobrowseConsent['mutation_stream']['last_page_url'] }}</span>@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                        </div>
                    </div>
                @else
                    <p class="empty realtime-note">{{ __('conversations.detail.cobrowse.no_mutations') }}</p>
                @endif


                {{-- Payload budgets are install-wide constants, not session state —
                     they live on /dashboard/readiness. --}}

                <div class="section-header" data-cobrowse-telemetry-heading @if (! $cobrowseConsent['telemetry']) hidden @endif>
                    <strong>{{ __('conversations.detail.cobrowse.telemetry') }}</strong>
                </div>

                <div class="meta-grid realtime-grid" data-cobrowse-telemetry-grid @if (! $cobrowseConsent['telemetry']) hidden @endif>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.rtt') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-rtt>@if (($cobrowseConsent['telemetry']['rtt_value'] ?? null) !== null){{ __('cobrowse.units.milliseconds', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['rtt_value'])]) }}@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.max_rtt') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-max-rtt>@if (($cobrowseConsent['telemetry']['max_rtt_value'] ?? null) !== null){{ __('cobrowse.units.milliseconds', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['max_rtt_value'])]) }}@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.payload') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-payload>@if (($cobrowseConsent['telemetry']['payload_value'] ?? null) !== null){{ __('cobrowse.units.bytes', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['payload_value'])]) }}@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.max_payload') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-max-payload>@if (($cobrowseConsent['telemetry']['max_payload_value'] ?? null) !== null){{ __('cobrowse.units.bytes', ['count' => \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['max_payload_value'])]) }}@else{{ __('cobrowse.units.not_reported') }}@endif</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.dropped_batches') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-dropped-batches>{{ \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['dropped_batches_value'] ?? 0) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.reconnects') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-reconnects>{{ \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['reconnects_value'] ?? 0) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.cobrowse.samples') }}</span>
                        <span class="meta-value" data-cobrowse-telemetry-samples>{{ \App\Support\ReaderNumber::count($cobrowseConsent['telemetry']['samples_value'] ?? 0) }}</span>
                    </div>
                </div>

                <p class="empty realtime-note" data-cobrowse-telemetry-empty @if ($cobrowseConsent['telemetry']) hidden @endif>{{ __('conversations.detail.cobrowse.no_telemetry') }}</p>
                </x-details-disclosure>
            </section>
                </x-tab-panel>

                <x-tab-panel id="visitor">
            <section class="section" aria-labelledby="visitor-context-heading">
                <div class="section-header">
                    <h2 id="visitor-context-heading">{{ __('conversations.detail.context.heading') }}</h2>
                    <div class="section-actions">
                        <span class="lede">{{ __('conversations.detail.context.safe_only') }}</span>
                        @if ($conversation->visitor)
                            <a class="button secondary" href="{{ route('dashboard.visitors.show', $conversation->visitor) }}">{{ __('conversations.detail.context.open_profile') }}</a>
                        @endif
                    </div>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.visitor') }}</span>
                        {{-- Usually the visitor's words -- a name they gave, or the
                             identifier their browser did -- but not always. An
                             inbound email with no display name leaves both null and
                             `anonymous_id` carries a translated sentence instead, so
                             the reset follows `identified` rather than the element. --}}
                        @if ($visitorContext['identified'])
                            <span class="meta-value" lang="">{{ $visitorContext['name'] ?: $visitorContext['anonymous_id'] }}</span>
                        @else
                            <span class="meta-value">{{ $visitorContext['anonymous_id'] }}</span>
                        @endif
                    </div>
                    @if ($visitorContext['email'])
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.context.email') }}</span>
                            <span class="meta-value">{{ $visitorContext['email'] }}</span>
                        </div>
                    @endif
                    @if ($visitorContext['reason'])
                        <div class="meta-item">
                            <span class="meta-label">{{ __('conversations.detail.context.about') }}</span>
                            {{-- The visitor's own words, typed before the conversation started. --}}
                            <span class="meta-value" lang="">{{ $visitorContext['reason'] }}</span>
                        </div>
                    @endif
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.host_visitor_id') }}</span>
                        <span class="meta-value">{{ $visitorContext['external_id'] ?? __('conversations.detail.context.not_provided') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.presence') }}</span>
                        {{-- The script that refreshes this cannot reach the catalogue, so its
                             fallback is handed over as data. --}}
                        <span class="readiness-status" data-status="{{ in_array($visitorContext['presence']['state'], ['active', 'recent'], true) ? 'ready' : 'manual' }}" data-fallback="{{ __('conversations.detail.context.not_reported') }}" data-visitor-presence-label aria-live="polite">
                            {{ $visitorContext['presence']['label'] }}
                        </span>
                        <span class="lede" data-visitor-presence-detail>{{ $visitorContext['presence']['detail'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.last_seen') }}</span>
                        <span class="meta-value" data-visitor-presence-last-seen>{{ $visitorContext['last_seen_at']?->diffForHumans() ?? __('conversations.detail.context.not_reported') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.latest_page') }}</span>
                        {{-- Marked only when there IS an address. The fallback is our
                             sentence and stays in the agent's language. --}}
                        @if ($visitorContext['last_page_url'] ?? null)
                            <span class="meta-value" lang="">{{ $visitorContext['last_page_url'] }}</span>
                        @else
                            <span class="meta-value">{{ __('conversations.detail.context.not_reported') }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.entry_page') }}</span>
                        {{-- Marked only when there IS an address. The fallback is our
                             sentence and stays in the agent's language. --}}
                        @if ($visitorContext['started_page_url'] ?? null)
                            <span class="meta-value" lang="">{{ $visitorContext['started_page_url'] }}</span>
                        @else
                            <span class="meta-value">{{ __('conversations.detail.context.not_reported') }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.context.history') }}</span>
                        <span class="meta-value">{{ trans_choice('conversations.detail.context.previous_count', $priorConversations->count(), ['count' => $priorConversations->count()]) }}</span>
                    </div>
                </div>

                <div class="section-header">
                    <strong>{{ __('conversations.detail.headings.references') }}</strong>
                    <span class="lede">{{ __('conversations.detail.references.records') }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.references.current') }}</span>
                        <span class="meta-value">
                            <x-support-code-reference
                                :code="$conversation->support_code"
                                :href="route('dashboard.support-code.lookup', ['support_code' => $conversation->support_code])"
                            />
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.references.visitor_reference') }}</span>
                        {{-- Same fallback underneath: with no external id, this shows
                             `anonymous_id`, which is our sentence when the visitor
                             gave nothing. --}}
                        @if ($visitorContext['external_id'] || $visitorContext['identified'])
                            <span class="meta-value" lang="">{{ $visitorContext['external_id'] ?? $visitorContext['anonymous_id'] }}</span>
                        @else
                            <span class="meta-value">{{ $visitorContext['anonymous_id'] }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.references.same_visitor') }}</span>
                        <span class="meta-value">{{ trans_choice('conversations.detail.context.previous_count', $priorConversations->count(), ['count' => $priorConversations->count()]) }}</span>
                        @if ($priorConversations->isEmpty())
                            <div class="notice-list">
                                <p>{{ __('conversations.detail.references.none') }}</p>
                            </div>
                        @else
                            <div class="notice-list">
                                @foreach ($priorConversations as $priorConversation)
                                    <p>
                                        <a class="text-link" href="{{ route('dashboard.conversations.show', $priorConversation->support_code) }}">
                                            {{ $priorConversation->support_code }}
                                        </a>
                                        <span class="lede">@if (filled($priorConversation->subject))<span lang="">{{ $priorConversation->subject }}</span>@else{{ __('conversations.detail.untitled') }}@endif</span>
                                    </p>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('conversations.detail.references.note') }}</span>
                        <span class="meta-value">{{ __('conversations.detail.references.lede') }}</span>
                    </div>
                </div>

                <div class="notice-copy notice-copy-bordered">
                    <p><strong>{{ __('conversations.detail.cobrowse.data_boundary') }}</strong></p>
                    <p>{{ __('conversations.detail.context.boundary') }}</p>
                </div>

                <div class="section-header">
                    <strong>{{ __('conversations.detail.context.host_context') }}</strong>
                    <span class="lede">{{ trans_choice('conversations.detail.context.field_count', count($visitorContext['host_context']), ['count' => count($visitorContext['host_context'])]) }}</span>
                </div>

                @if ($visitorContext['host_context'] === [])
                    <p class="empty">{{ __('conversations.detail.context.no_host_context') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('conversations.detail.context.field') }}</th>
                                    <th scope="col">{{ __('conversations.detail.context.value') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($visitorContext['host_context'] as $field => $value)
                                    <tr>
                                        {{-- Host context is whatever the customer's own
                                             site chose to send: their field names, their
                                             values, their language. --}}
                                        <td lang="">{{ $field }}</td>
                                        <td lang="">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="section-header">
                    <strong>{{ __('conversations.detail.context.prior') }}</strong>
                    <span class="lede">{{ trans_choice('conversations.detail.context.previous_count', $priorConversations->count(), ['count' => $priorConversations->count()]) }}</span>
                </div>

                @if ($priorConversations->isEmpty())
                    <p class="empty">{{ __('conversations.detail.context.no_prior') }}</p>
                @else
                    <div class="timeline-list">
                        @foreach ($priorConversations as $priorConversation)
                            <article class="timeline-item">
                                <div class="timeline-content">
                                    <a class="text-link" href="{{ route('dashboard.conversations.show', $priorConversation->support_code) }}">
                                        @if (filled($priorConversation->subject))<span lang="">{{ $priorConversation->subject }}</span>@else{{ __('conversations.detail.untitled') }}@endif
                                    </a>
                                    <div class="timeline-meta">
                                        <span>{{ $priorConversation->support_code }}</span>
                                        <span>{{ __('conversations.detail.statuses.'.$priorConversation->status) }}</span>
                                        <span>{{ __('conversations.detail.context.owner_label', ['name' => $priorConversation->assignedAgent?->name ?? __('conversations.detail.context.unassigned')]) }}</span>
                                        <span>{{ __('conversations.detail.context.last_activity_label', ['elapsed' => $priorConversation->last_message_at?->diffForHumans() ?? $priorConversation->created_at->diffForHumans()]) }}</span>
                                    </div>
                                    @if ($canCreateTicket)
                                        <div class="timeline-meta">
                                            <strong>{{ __('conversations.detail.ticket.heading') }}</strong>
                                            @forelse ($priorConversation->tickets as $ticket)
                                                <a class="text-link" href="{{ route('dashboard.tickets.show', $ticket) }}">
                                                    <span lang="">{{ $ticket->subject }}</span>
                                                </a>
                                                {{-- A TICKET status, from the ticket catalogue rather than this one. --}}
                                                <span>{{ __('tickets.statuses.'.$ticket->status) }}</span>
                                            @empty
                                                <span>{{ __('conversations.detail.ticket.none') }}</span>
                                            @endforelse
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
                </x-tab-panel>

                <x-tab-panel id="ticket">
            <section class="section" aria-labelledby="tickets-heading">
                <div class="section-header">
                    <h2 id="tickets-heading">{{ __('conversations.detail.tabs.ticket') }}</h2>
                    <span class="lede">{{ $tickets->isEmpty() ? __('conversations.detail.tabs.not_created') : trans_choice('conversations.detail.tabs.linked_badge', $tickets->count(), ['count' => $tickets->count()]) }}</span>
                </div>

                @if ($canCreateTicket && $tickets->isEmpty())
                    <form class="section-form" method="POST" action="{{ route('dashboard.conversations.tickets.store', $conversation->support_code) }}">
                        @csrf
                        @include('agent.conversations.partials.return-query-fields')

                        <div class="field">
                            <label for="category">{{ __('conversations.detail.ticket.category') }}</label>
                            <select id="category" name="category">
                                <option value="">{{ __('conversations.detail.ticket.uncategorized') }}</option>
                                @foreach ($ticketCategories as $value => $category)
                                    <option value="{{ $value }}" @selected(old('category') === $value)>{{ __('tickets.categories.'.$value) }}</option>
                                @endforeach
                            </select>
                            <x-ticket-category-guidance :categories="$ticketCategoryGuidance" />
                            @error('category')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="priority">{{ __('conversations.detail.ticket.priority') }}</label>
                            <select id="priority" name="priority">
                                @foreach ($ticketPriorities as $value => $priority)
                                    <option value="{{ $value }}" @selected(old('priority', 'normal') === $value)>{{ __('tickets.priorities.'.$value) }}</option>
                                @endforeach
                            </select>
                            <x-ticket-priority-guidance :priorities="$ticketPriorityGuidance" />
                            @error('priority')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">{{ __('conversations.detail.ticket.create') }}</button>
                    </form>
                @elseif ($tickets->isNotEmpty())
                    @include('agent.conversations.partials.linked-ticket-work')
                @else
                    <p class="empty">{{ __('conversations.detail.ticket.none') }}</p>
                @endif
            </section>
                </x-tab-panel>

            </x-tabs>

            <script>
                (function () {
                    var retryForms = document.querySelectorAll('[data-resync-retry-form]');
                    var lockedRecovery = document.querySelector('[data-cobrowse-transport-recovery][data-recovery-locked="true"]');

                    retryForms.forEach(function (form) {
                        var retryAt = Date.parse(form.getAttribute('data-retry-at') || '');
                        var button = form.querySelector('[data-resync-retry-button]');
                        var help = form.querySelector('[data-resync-retry-help]');

                        if (!button || Number.isNaN(retryAt)) {
                            return;
                        }

                        function enableRetry() {
                            button.disabled = false;
                            button.textContent = form.getAttribute('data-retry-label') || 'Request another fresh snapshot';

                            if (help) {
                                help.textContent = form.getAttribute('data-retry-ready-help') || 'You can request another fresh snapshot now.';
                            }

                            if (lockedRecovery) {
                                lockedRecovery.textContent = form.getAttribute('data-retry-ready-recovery') || 'Request another fresh snapshot if the preview still looks out of date.';
                                lockedRecovery.dataset.recoveryLocked = 'false';
                            }
                        }

                        var delay = retryAt - Date.now();

                        if (delay <= 0) {
                            enableRetry();

                            return;
                        }

                        window.setTimeout(enableRetry, delay);
                    });
                })();
            </script>

    @if ($realtime)
        <script>
            (function () {
                var config = @json($realtime);
                var panel = document.querySelector('[data-cobrowse-update-panel]');
                var status = document.querySelector('[data-cobrowse-update-status]');
                var refresh = document.querySelector('[data-cobrowse-refresh]');
                var previewFrame = document.querySelector('[data-cobrowse-replay-frame]');
                var previewApplied = document.querySelector('[data-cobrowse-replay-applied]');
                var previewSkipped = document.querySelector('[data-cobrowse-replay-skipped]');
                var previewDriftStatus = document.querySelector('[data-cobrowse-replay-drift-status]');
                var previewDriftMessage = document.querySelector('[data-cobrowse-replay-drift-message]');
                var previewViewportLabel = document.querySelector('[data-cobrowse-viewport-label]');
                var realtimeLabels = @json($realtimeLabels);

                var visitorPresenceLabel = document.querySelector('[data-visitor-presence-label]');
                var visitorPresenceDetail = document.querySelector('[data-visitor-presence-detail]');
                var visitorPresenceLastSeen = document.querySelector('[data-visitor-presence-last-seen]');
                var visitorReadLabels = document.querySelectorAll('[data-visitor-read-label]');
                var visitorReadDetails = document.querySelectorAll('[data-visitor-read-detail]');
                var transportPanel = document.querySelector('[data-cobrowse-transport-panel]');
                var transportLabel = document.querySelector('[data-cobrowse-transport-label]');
                var transportMessage = document.querySelector('[data-cobrowse-transport-message]');
                var transportStateLabel = document.querySelector('[data-cobrowse-transport-state-label]');
                var transportLastReport = document.querySelector('[data-cobrowse-transport-last-report]');
                var transportReconnects = document.querySelector('[data-cobrowse-transport-reconnects]');
                var transportPressure = document.querySelector('[data-cobrowse-transport-pressure]');
                var transportGuidance = document.querySelector('[data-cobrowse-transport-guidance]');
                var transportRecovery = document.querySelector('[data-cobrowse-transport-recovery]');
                var snapshotStatus = document.querySelector('[data-cobrowse-snapshot-status]');
                var snapshotFreshnessLabel = document.querySelector('[data-cobrowse-snapshot-freshness-label]');
                var snapshotFreshnessMessage = document.querySelector('[data-cobrowse-snapshot-freshness-message]');
                var snapshotFreshnessReported = document.querySelector('[data-cobrowse-snapshot-freshness-reported]');
                var snapshotRecovery = document.querySelector('[data-cobrowse-snapshot-recovery]');
                var snapshotRecoveryLabel = document.querySelector('[data-cobrowse-snapshot-recovery-label]');
                var snapshotRecoveryMessage = document.querySelector('[data-cobrowse-snapshot-recovery-message]');
                var telemetryHeading = document.querySelector('[data-cobrowse-telemetry-heading]');
                var telemetryEmpty = document.querySelector('[data-cobrowse-telemetry-empty]');
                var telemetryGrid = document.querySelector('[data-cobrowse-telemetry-grid]');
                var telemetryRtt = document.querySelector('[data-cobrowse-telemetry-rtt]');
                var telemetryMaxRtt = document.querySelector('[data-cobrowse-telemetry-max-rtt]');
                var telemetryPayload = document.querySelector('[data-cobrowse-telemetry-payload]');
                var telemetryMaxPayload = document.querySelector('[data-cobrowse-telemetry-max-payload]');
                var telemetryDroppedBatches = document.querySelector('[data-cobrowse-telemetry-dropped-batches]');
                var telemetryReconnects = document.querySelector('[data-cobrowse-telemetry-reconnects]');
                var telemetrySamples = document.querySelector('[data-cobrowse-telemetry-samples]');
                var csrf = document.querySelector('meta[name="csrf-token"]');
                var transcript = document.querySelector('[data-transcript]');
                var transcriptCount = document.querySelector('[data-transcript-count]');
                var visitorTyping = document.querySelector('[data-visitor-typing]');
                var hasTranscriptTarget = Boolean(transcript);
                var hasCobrowseTargets = Boolean(panel && status);
                var hasPresenceTargets = Boolean(visitorPresenceLabel && visitorPresenceDetail);
                var hasReadTargets = visitorReadLabels.length > 0 && visitorReadDetails.length > 0;
                var hasTransportTargets = Boolean(transportLabel && transportMessage && transportStateLabel);
                var hasSnapshotFreshnessTargets = Boolean(snapshotStatus && snapshotFreshnessLabel && snapshotFreshnessMessage && snapshotFreshnessReported);
                var hasSnapshotRecoveryTargets = Boolean(snapshotRecovery && snapshotRecoveryLabel && snapshotRecoveryMessage);
                var hasTelemetryTargets = Boolean(telemetryGrid && telemetryRtt);

                if (!config || (!hasCobrowseTargets && !hasPresenceTargets && !hasReadTargets && !hasTransportTargets && !hasSnapshotFreshnessTargets && !hasSnapshotRecoveryTargets && !hasTelemetryTargets && !hasTranscriptTarget) || !window.WebSocket) {
                    if (status) {
                        status.textContent = realtimeLabels.cobrowseRealtime.unavailable;
                    }

                    return;
                }

                if (refresh) {
                    refresh.addEventListener('click', function () {
                        if (config.previewUrl) {
                            setStatus(realtimeLabels.cobrowseRealtime.preview_refreshing, 'listening');
                            refreshCobrowsePreview();

                            return;
                        }

                        window.location.reload();
                    });
                }

                function setStatus(message, state) {
                    if (!hasCobrowseTargets) {
                        return;
                    }

                    status.textContent = message;
                    panel.dataset.state = state || 'idle';
                }

                var previewRefreshInFlight = false;
                var previewRefreshQueued = false;

                // Swap the server-sanitized preview into the existing iframe in
                // place and refresh the applied/skipped and drift labels. Returns
                // false when there is nothing to update (no preview yet, or the
                // preview section was not rendered at page load).
                function applyPreviewState(preview) {
                    if (!preview || !previewFrame) {
                        return false;
                    }

                    if (typeof preview.srcdoc === 'string') {
                        previewFrame.srcdoc = preview.srcdoc;
                    }

                    // The payload's own strings are English -- it is the same
                    // shape the server render reads, and it reaches every agent
                    // watching. The counts travel; the words are local.
                    if (previewApplied && typeof preview.applied_mutations_value === 'number') {
                        previewApplied.textContent = realtimeLabels.cobrowseUnits.applied
                            .replace(':count', readerNumber(preview.applied_mutations_value));
                    }

                    if (previewSkipped && typeof preview.skipped_mutations_value === 'number') {
                        previewSkipped.textContent = realtimeLabels.cobrowseUnits.skipped
                            .replace(':count', readerNumber(preview.skipped_mutations_value));
                    }

                    var drift = preview.drift || null;

                    var driftCopy = drift
                        ? (realtimeLabels.cobrowseDrift[drift.state] || realtimeLabels.cobrowseDrift.steady)
                        : null;

                    if (drift && previewDriftStatus) {
                        previewDriftStatus.textContent = driftCopy.label;
                        previewDriftStatus.dataset.status = drift.tone || 'manual';
                    }

                    if (drift && previewDriftMessage) {
                        // '3 of 12 drifted' is a sentence with two numbers in
                        // it, not a value -- so the counts come across and the
                        // catalogue decides where they land.
                        var counts = drift.summary_counts || null;
                        var summary = counts
                            ? ' (' + realtimeLabels.cobrowseDriftSummary
                                .replace(':unresolved', counts.unresolved)
                                .replace(':addressable', counts.addressable) + ')'
                            : '';

                        previewDriftMessage.textContent = driftCopy.message + summary;
                        previewDriftMessage.dataset.recommendResync = drift.recommend_resync ? 'true' : 'false';
                        previewDriftMessage.hidden = drift.state === 'steady';
                    }

                    // Keep the preview rendered at the visitor's reported viewport
                    // width across live swaps (the width can change if the visitor
                    // resizes or moves devices). The repaint after the swap needs
                    // no call here: the frame's persistent load listener (in the
                    // preview block) fires when the replaced srcdoc finishes
                    // parsing — the only moment the repaint actually works.
                    syncPreviewViewport(preview.viewport_width);

                    return true;
                }

                // Resize the preview to the visitor's reported viewport width. Also
                // driven directly from the metadata-only broadcast summary, so a
                // resize-only page_state update fixes the geometry immediately
                // without refetching preview content.
                function syncPreviewViewport(viewportWidth) {
                    if (!previewFrame) {
                        return;
                    }

                    if (typeof viewportWidth === 'number' && viewportWidth > 0) {
                        previewFrame.setAttribute('data-viewport-width', String(viewportWidth));

                        if (previewViewportLabel) {
                            previewViewportLabel.textContent = realtimeLabels.cobrowseUnits.viewport.replace(':width', readerNumber(viewportWidth));
                            previewViewportLabel.hidden = false;
                        }
                    } else {
                        previewFrame.removeAttribute('data-viewport-width');

                        if (previewViewportLabel) {
                            previewViewportLabel.textContent = '';
                            previewViewportLabel.hidden = true;
                        }
                    }

                    if (typeof window.wayfindrSizeCobrowsePreview === 'function') {
                        window.wayfindrSizeCobrowsePreview();
                    }
                }

                // Fetch the latest sanitized preview and apply it live. The
                // broadcast only carries metadata, so the iframe content always
                // comes back through the server sanitizer here, never the socket.
                function refreshCobrowsePreview() {
                    if (!config.previewUrl) {
                        if (refresh) {
                            refresh.hidden = false;
                        }

                        return;
                    }

                    if (previewRefreshInFlight) {
                        previewRefreshQueued = true;

                        return;
                    }

                    previewRefreshInFlight = true;

                    fetch(config.previewUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                throw new Error(realtimeLabels.cobrowseRealtime.preview_failed.replace(':reason', '') + response.status);
                            }

                            return response.json();
                        })
                        .then(function (body) {
                            var data = body && body.data ? body.data : {};
                            var preview = data.replay_preview || null;

                            if (preview && !previewFrame) {
                                // The first preview arrived after page load; a
                                // reload renders the section that does not exist yet.
                                window.location.reload();

                                return;
                            }

                            if (applyPreviewState(preview)) {
                                if (refresh) {
                                    refresh.hidden = true;
                                }

                                setStatus(realtimeLabels.cobrowseRealtime.preview_updated, 'listening');
                            }
                        })
                        .catch(function () {
                            if (refresh) {
                                refresh.hidden = false;
                            }

                            setStatus(realtimeLabels.cobrowseRealtime.preview_refresh_failed, 'warning');
                        })
                        .then(function () {
                            previewRefreshInFlight = false;

                            if (previewRefreshQueued) {
                                previewRefreshQueued = false;
                                refreshCobrowsePreview();
                            }
                        });
                }

                function presenceStatusFor(state) {
                    return state === 'active' || state === 'recent'
                        ? 'ready'
                        : 'manual';
                }

                var relativeTimeFormat = (function () {
                    try {
                        return new Intl.RelativeTimeFormat(realtimeLabels.locale || 'en', {numeric: 'auto'});
                    } catch (error) {
                        return null;
                    }
                })();

                // Durations arrive as timestamps and are formatted here. A
                // duration formatted on the server is frozen in whichever
                // agent's request built the broadcast, and every other agent
                // watching then reads it in a language they did not choose.
                function elapsedSince(timestamp) {
                    if (!timestamp || !relativeTimeFormat) {
                        return null;
                    }

                    var moment = Date.parse(timestamp);

                    if (!Number.isFinite(moment)) {
                        return null;
                    }

                    var seconds = Math.round((moment - Date.now()) / 1000);
                    var units = [
                        ['year', 31536000],
                        ['month', 2592000],
                        ['day', 86400],
                        ['hour', 3600],
                        ['minute', 60],
                    ];

                    for (var index = 0; index < units.length; index += 1) {
                        if (Math.abs(seconds) >= units[index][1]) {
                            return relativeTimeFormat.format(
                                Math.round(seconds / units[index][1]),
                                units[index][0]
                            );
                        }
                    }

                    return relativeTimeFormat.format(seconds, 'second');
                }

                // A template whose `:elapsed` cannot be filled must not reach the
                // page: `Seen :elapsed` is the right language and still nonsense.
                //
                // Returns null when it cannot produce anything, which is NOT the
                // same as the fallback. A browser without Intl.RelativeTimeFormat
                // can still have a perfectly good timestamp, and treating that as
                // missing telemetry would replace a real "seen 2 minutes ago" with
                // "no visitor heartbeat yet" -- a different fact, on every event.
                // The caller leaves the server-rendered text alone instead.
                function fillElapsed(template, timestamp, fallback) {
                    if (!template) {
                        return fallback;
                    }

                    if (template.indexOf(':elapsed') === -1) {
                        return template;
                    }

                    if (!timestamp) {
                        return fallback;
                    }

                    var elapsed = elapsedSince(timestamp);

                    return elapsed === null ? null : template.replace(':elapsed', elapsed);
                }

                // Skips the write when there is nothing trustworthy to write.
                function setTextIfKnown(target, value) {
                    if (target && value !== null) {
                        target.textContent = value;
                    }
                }

                function updateVisitorPresence(visitorPresence) {
                    if (!hasPresenceTargets || !visitorPresence) {
                        return;
                    }

                    // Same rule as the read receipt: state travels, words are local.
                    var presenceState = visitorPresence.state || 'unknown';

                    visitorPresenceLabel.textContent = realtimeLabels.presence[presenceState]
                        || visitorPresenceLabel.dataset.fallback;
                    visitorPresenceLabel.dataset.status = presenceStatusFor(presenceState);
                    setTextIfKnown(visitorPresenceDetail, fillElapsed(
                        realtimeLabels.presenceDetail[visitorPresence.detail_key],
                        visitorPresence.last_seen_at,
                        realtimeLabels.presenceDetail.no_heartbeat
                    ));

                    if (visitorPresenceLastSeen) {
                        setTextIfKnown(visitorPresenceLastSeen, visitorPresence.last_seen_at
                            ? elapsedSince(visitorPresence.last_seen_at)
                            : realtimeLabels.lastSeenUnknown);
                    }
                }

                function readStatusFor(state) {
                    if (state === 'seen') {
                        return 'ready';
                    }

                    return state === 'unseen' ? 'attention' : 'manual';
                }

                function updateVisitorRead(visitorRead) {
                    if (!hasReadTargets || !visitorRead) {
                        return;
                    }

                    visitorReadLabels.forEach(function (visitorReadLabel) {
                        // The payload's own `label` is deliberately ignored: one
                        // broadcast reaches every agent watching, and they do not
                        // all read the same language. The STATE travels; the words
                        // come from this page, in this agent's language.
                        visitorReadLabel.textContent = realtimeLabels.read[visitorRead.state || 'none']
                            || realtimeLabels.read.none;

                        if (visitorReadLabel.hasAttribute('data-status')) {
                            visitorReadLabel.dataset.status = readStatusFor(visitorRead.state || 'none');
                        }
                    });

                    visitorReadDetails.forEach(function (visitorReadDetail) {
                        // The payload's own `detail` is ignored for the same
                        // reason its `label` is.
                        setTextIfKnown(visitorReadDetail, fillElapsed(
                            realtimeLabels.readDetail[visitorRead.state || 'none'],
                            visitorRead.seen_at,
                            realtimeLabels.readDetail.none
                        ));
                    });

                    var messageId = visitorRead.message_id ? String(visitorRead.message_id) : '';
                    var agentMessageSeen = messageId
                        ? document.querySelector('[data-agent-message-seen-id="' + messageId + '"]')
                        : null;

                    if (!agentMessageSeen) {
                        return;
                    }

                    if (visitorRead.state === 'seen') {
                        setTextIfKnown(agentMessageSeen, fillElapsed(
                            realtimeLabels.transcript.seen,
                            visitorRead.seen_at,
                            realtimeLabels.transcript.seen_unknown
                        ) ?? realtimeLabels.transcript.seen_unknown);

                        return;
                    }

                    if (visitorRead.state === 'unseen') {
                        agentMessageSeen.textContent = realtimeLabels.transcript.unseen;
                    }
                }

                function setText(target, value) {
                    if (!target) {
                        return;
                    }

                    target.textContent = value;
                }

                function numericValue(value) {
                    var number = Number(value);

                    return Number.isFinite(number) && number >= 0 ? number : null;
                }

                // The client half of `ReaderNumber`. Calling `toLocaleString`
                // with no argument follows the BROWSER, not the agent -- so a German
                // agent on an en-US browser got German dates and American
                // numbers in the same panel, and worse, the server painted
                // `4.213` and the first websocket message rewrote the same
                // node as `4,213` with no data change behind it.
                //
                // `realtimeLabels.locale` is the agent's, already shipped for
                // `Intl.RelativeTimeFormat` further down this file.
                function readerNumber(number) {
                    return Number(number).toLocaleString(realtimeLabels.locale || 'en');
                }

                function formatNumber(value) {
                    var number = numericValue(value);

                    return number === null ? '0' : readerNumber(Math.round(number));
                }

                function formatMilliseconds(value) {
                    var number = numericValue(value);

                    return number === null ? realtimeLabels.cobrowseUnits.notReported : realtimeLabels.cobrowseUnits.milliseconds.replace(':count', readerNumber(Math.round(number)));
                }

                function formatBytes(value) {
                    var number = numericValue(value);

                    return number === null ? realtimeLabels.cobrowseUnits.notReported : realtimeLabels.cobrowseUnits.bytes.replace(':count', readerNumber(Math.round(number)));
                }

                function timestampValue(value) {
                    var timestamp = Date.parse(value || '');

                    return Number.isNaN(timestamp) ? null : timestamp;
                }

                function formatRelativeTimestamp(value) {
                    var timestamp = timestampValue(value);

                    if (timestamp === null) {
                        return 'just now';
                    }

                    var elapsedSeconds = Math.max(0, Math.round((Date.now() - timestamp) / 1000));

                    if (elapsedSeconds < 45) {
                        return 'just now';
                    }

                    var elapsedMinutes = Math.round(elapsedSeconds / 60);

                    if (elapsedMinutes <= 1) {
                        return '1 minute ago';
                    }

                    // Deliberately NOT readerNumber(): the noun beside it is a
                    // hardcoded English string. A German number welded to an
                    // English word reads worse than either, and is the trap
                    // `CobrowseSnapshotFreshness` already pins English for.
                    // This becomes correct when the sentence is extracted.
                    return elapsedMinutes.toLocaleString('en') + ' minutes ago';
                }

                function transportPressureFromSummary(summary) {
                    var pressure = summary.transport_pressure || null;

                    if (!pressure) {
                        return null;
                    }

                    return {
                        dropped_batches: numericValue(pressure.dropped_batches) || 0,
                        skipped_mutations: numericValue(pressure.skipped_mutations) || 0,
                        reported_at: pressure.reported_at || null,
                    };
                }

                function droppedBatchPressure(telemetry, pressure) {
                    var droppedBatches = pressure ? pressure.dropped_batches : numericValue(telemetry.dropped_batches) || 0;
                    var skippedMutations = pressure ? pressure.skipped_mutations : 0;
                    var parts = [];

                    var pressureCopy = realtimeLabels.cobrowsePressure;

                    // The sentence is composed, not translated -- the English
                    // built it by gluing an English pluraliser to a comma, and
                    // neither of those travels. Same rule the server render
                    // follows in x-cobrowse-pressure.
                    if (droppedBatches > 0) {
                        parts.push((droppedBatches === 1 ? pressureCopy.droppedOne : pressureCopy.droppedMany)
                            .replace(':count', readerNumber(Math.round(droppedBatches))));
                    }

                    if (skippedMutations > 0) {
                        parts.push((skippedMutations === 1 ? pressureCopy.skippedOne : pressureCopy.skippedMany)
                            .replace(':count', readerNumber(Math.round(skippedMutations))));
                    }

                    if (parts.length === 0) {
                        return pressureCopy.noneRecent;
                    }

                    return parts.join(pressureCopy.separator);
                }

                function transportHealthFromTelemetry(telemetry, pressure) {
                    var droppedBatches = pressure ? pressure.dropped_batches : numericValue(telemetry.dropped_batches) || 0;
                    var skippedMutations = pressure ? pressure.skipped_mutations : 0;
                    var reconnects = numericValue(telemetry.reconnects) || 0;

                    if (telemetry.resync_attempts_exhausted === true) {
                        return {state: 'exhausted', copy: 'exhausted'};
                    }

                    if (reconnects > 0) {
                        return {state: 'reconnecting', copy: 'reconnecting'};
                    }

                    if (droppedBatches > 0 || skippedMutations > 0) {
                        return {state: 'degraded', copy: 'degraded'};
                    }

                    // State and the NAME of the copy, never the copy itself:
                    // this runs for whoever is watching, in their language.
                    return {state: 'live', copy: 'live'};
                }

                function updateTransportHealth(telemetry, pressure) {
                    if (!hasTransportTargets || !telemetry) {
                        return;
                    }

                    var health = transportHealthFromTelemetry(telemetry, pressure);

                    if (transportPanel) {
                        transportPanel.dataset.state = health.state;
                    }

                    var copy = realtimeLabels.cobrowseTransport[health.copy] || realtimeLabels.cobrowseTransport.live;
                    var reportedAt = pressure && pressure.reported_at ? pressure.reported_at : telemetry.reported_at;

                    setText(transportLabel, copy.label);
                    setText(transportMessage, copy.message);
                    setText(transportStateLabel, copy.label);
                    setTextIfKnown(transportLastReport, reportedAt
                        ? elapsedSince(reportedAt)
                        : realtimeLabels.cobrowseUnits.notReported);
                    setText(transportReconnects, formatNumber(telemetry.reconnects));
                    setText(transportPressure, droppedBatchPressure(telemetry, pressure));
                    setText(transportGuidance, copy.guidance);

                    if (!transportRecovery) {
                        return;
                    }

                    if (transportRecovery.dataset.recoveryLocked === 'true' && health.state !== 'exhausted') {
                        return;
                    }

                    transportRecovery.dataset.recoveryLocked = 'false';
                    setText(transportRecovery, copy.recovery_action);
                }

                function recoveryFromSnapshotFreshness(freshness) {
                    if (!freshness || !freshness.state || freshness.state === 'fresh') {
                        return null;
                    }

                    if (snapshotRecovery && snapshotRecovery.dataset.pending === 'true') {
                        return {status: 'pending', copy: 'pending'};
                    }

                    if (freshness.state === 'unknown') {
                        return {status: 'unknown', copy: 'unknown'};
                    }

                    // `aging` and `stale` are different states that say the same
                    // thing, exactly as the server render has it.
                    return {status: freshness.state, copy: 'needs_refresh'};
                }

                function updateSnapshotRecovery(freshness) {
                    if (!hasSnapshotRecoveryTargets) {
                        return;
                    }

                    var recovery = recoveryFromSnapshotFreshness(freshness);

                    if (!recovery) {
                        snapshotRecovery.hidden = true;

                        return;
                    }

                    snapshotRecovery.hidden = false;
                    snapshotRecovery.dataset.state = recovery.status || 'unknown';
                    var recoveryCopy = realtimeLabels.cobrowseRecovery[recovery.copy]
                        || realtimeLabels.cobrowseRecovery.needs_refresh;

                    setText(snapshotRecoveryLabel, recoveryCopy.label);
                    setText(snapshotRecoveryMessage, recoveryCopy.message);
                }

                function updateSnapshotFreshness(snapshot) {
                    if (!hasSnapshotFreshnessTargets || !snapshot || !snapshot.freshness) {
                        return false;
                    }

                    var freshness = snapshot.freshness;

                    // The payload's own label, message and reported_label are
                    // ignored: this event reaches every agent watching, and it
                    // was built in the language of whoever caused it.
                    var freshnessCopy = realtimeLabels.freshness[freshness.state || 'unknown']
                        || realtimeLabels.freshness.unknown;

                    setText(snapshotStatus, freshnessCopy.label);
                    snapshotStatus.dataset.status = freshness.tone || 'manual';
                    setText(snapshotFreshnessLabel, freshnessCopy.label);
                    setText(snapshotFreshnessMessage, freshnessCopy.message);
                    setTextIfKnown(snapshotFreshnessReported, fillElapsed(
                        realtimeLabels.freshness.reported,
                        snapshot.reported_at,
                        realtimeLabels.freshness.reportedUnknown
                    ));
                    updateSnapshotRecovery(freshness);

                    return true;
                }

                function updateConnectionTelemetry(telemetry) {
                    if (!hasTelemetryTargets || !telemetry) {
                        return;
                    }

                    if (telemetryHeading) {
                        telemetryHeading.hidden = false;
                    }

                    if (telemetryEmpty) {
                        telemetryEmpty.hidden = true;
                    }

                    telemetryGrid.hidden = false;

                    setText(telemetryRtt, formatMilliseconds(telemetry.rtt_ms));
                    setText(telemetryMaxRtt, formatMilliseconds(telemetry.max_rtt_ms));
                    setText(telemetryPayload, formatBytes(telemetry.payload_bytes));
                    setText(telemetryMaxPayload, formatBytes(telemetry.max_payload_bytes));
                    setText(telemetryDroppedBatches, formatNumber(telemetry.dropped_batches));
                    setText(telemetryReconnects, formatNumber(telemetry.reconnects));
                    setText(telemetrySamples, formatNumber(telemetry.samples));
                }

                function telemetryIsFreshForUpdate(telemetry, payload) {
                    var update = payload.update || {};
                    var updateKind = update.kind || '';

                    if (updateKind === 'telemetry') {
                        return true;
                    }

                    var telemetryAt = timestampValue(telemetry.reported_at);
                    var updateAt = timestampValue(update.reported_at);

                    return telemetryAt !== null && updateAt !== null && telemetryAt >= updateAt;
                }

                function pressureIsFreshForUpdate(pressure, payload) {
                    if (!pressure) {
                        return false;
                    }

                    var update = payload.update || {};
                    var updateKind = update.kind || '';

                    if (updateKind === 'telemetry') {
                        return true;
                    }

                    var pressureAt = timestampValue(pressure.reported_at);
                    var updateAt = timestampValue(update.reported_at);

                    return pressureAt !== null && updateAt !== null && pressureAt >= updateAt;
                }

                function updateLiveCobrowseTelemetry(payload) {
                    var summary = payload.summary || {};
                    var telemetry = summary.telemetry || null;
                    var pressure = transportPressureFromSummary(summary);

                    if (!telemetry) {
                        return null;
                    }

                    var telemetryFresh = telemetryIsFreshForUpdate(telemetry, payload);
                    var pressureFresh = pressureIsFreshForUpdate(pressure, payload);

                    if (!telemetryFresh && !pressureFresh) {
                        return null;
                    }

                    updateTransportHealth(telemetry, pressureFresh ? pressure : null);

                    if (telemetryFresh) {
                        updateConnectionTelemetry(telemetry);
                    }

                    return telemetry;
                }

                function parsePayload(payload) {
                    if (typeof payload === 'string') {
                        return JSON.parse(payload);
                    }

                    return payload || {};
                }

                var transcriptRefreshInFlight = false;
                var transcriptRefreshQueued = false;

                function agentNearBottom() {
                    return (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 240);
                }

                // Refetch the server-rendered transcript partial and swap it in.
                // A full replace (rather than client-side bubble construction)
                // reuses server escaping and grouping, and lets a reconnecting
                // socket catch up on messages missed while it was down for free.
                function refreshTranscript() {
                    if (!transcript || !config.messagesUrl) {
                        return;
                    }

                    if (transcriptRefreshInFlight) {
                        transcriptRefreshQueued = true;

                        return;
                    }

                    transcriptRefreshInFlight = true;
                    var stickToBottom = agentNearBottom();
                    var previousCount = transcript.querySelectorAll('[data-message-id]').length;

                    fetch(config.messagesUrl, {
                        credentials: 'same-origin',
                        headers: { Accept: 'text/html' }
                    })
                        .then(function (response) {
                            // A redirect means the session expired and the request
                            // was sent to /login (which is itself 200 OK). Never swap
                            // that HTML into the transcript.
                            if (response.redirected || !response.ok) {
                                throw new Error(realtimeLabels.cobrowseRealtime.transcript_failed.replace(':reason', '') + response.status);
                            }

                            return response.text();
                        })
                        .then(function (html) {
                            transcript.innerHTML = html;

                            var items = transcript.querySelectorAll('[data-message-id]');
                            var total = items.length;
                            var hasNewMessages = total > previousCount;

                            if (transcriptCount) {
                                transcriptCount.textContent = (total === 1
                                    ? transcriptCount.dataset.totalOne
                                    : transcriptCount.dataset.totalMany.replace(':count', total));
                            }

                            // Only a genuinely new message means the visitor stopped
                            // typing. A plain catch-up refresh (e.g. on first subscribe)
                            // must not clear the seeded/live typing indicator.
                            if (visitorTyping && hasNewMessages) {
                                visitorTyping.hidden = true;
                            }

                            if (hasNewMessages && typeof window.wayfindrConversationSummaryTranscriptUpdated === 'function') {
                                window.wayfindrConversationSummaryTranscriptUpdated();
                            }

                            if (stickToBottom) {
                                var last = items[items.length - 1];

                                if (last && typeof last.scrollIntoView === 'function') {
                                    last.scrollIntoView({ block: 'end' });
                                }
                            }
                        })
                        .catch(function () {
                            // Keep the existing transcript; the next event or reconnect retries.
                        })
                        .finally(function () {
                            transcriptRefreshInFlight = false;

                            if (transcriptRefreshQueued) {
                                transcriptRefreshQueued = false;
                                refreshTranscript();
                            }
                        });
                }

                var visitorTypingTimer = null;

                function updateVisitorTyping(typing) {
                    if (!visitorTyping || !typing) {
                        return;
                    }

                    if (visitorTypingTimer) {
                        window.clearTimeout(visitorTypingTimer);
                        visitorTypingTimer = null;
                    }

                    if (typing.state === 'typing') {
                        visitorTyping.hidden = false;

                        // Auto-clear if a stop-typing signal never arrives.
                        var freshMs = typeof config.visitorTypingFreshMs === 'number' && config.visitorTypingFreshMs > 0
                            ? config.visitorTypingFreshMs
                            : 6000;

                        visitorTypingTimer = window.setTimeout(function () {
                            visitorTyping.hidden = true;
                        }, freshMs);
                    } else {
                        visitorTyping.hidden = true;
                    }
                }


                // Our own keepalive.
                //
                // The pusher protocol expects the CLIENT to speak on an
                // otherwise silent connection: the server declares an
                // `activity_timeout` when the connection is established, and a
                // client that says nothing for that long is disconnected. This
                // page answered the server's ping and never sent one of its own.
                //
                // It matters more than the protocol makes it sound, because
                // anything between the browser and Reverb is also counting. On
                // a default nginx the websocket location inherits
                // `proxy_read_timeout 60s`, so an idle socket is torn down at
                // sixty seconds with no close frame -- measured on our own
                // staging deploy, where a silent socket died at exactly 60s
                // (code 1006) while one sending a ping every 25s stayed up.
                // Reverb's own ping is also on a sixty-second interval, so it
                // never got the chance to arrive first.
                var keepaliveTimer = null;

                function stopKeepalive() {
                    if (keepaliveTimer) {
                        window.clearInterval(keepaliveTimer);
                        keepaliveTimer = null;
                    }
                }

                function startKeepalive(activeSocket, activityTimeoutSeconds) {
                    stopKeepalive();

                    // Half the window the server gave us, so a single lost frame
                    // is not a disconnection, and never longer than 25 seconds --
                    // proxies in front of us have their own idea of idle and do
                    // not tell us what it is.
                    var declared = Number(activityTimeoutSeconds);
                    var every = Math.max(5, Math.min(25, (declared > 0 ? declared : 30) / 2));

                    keepaliveTimer = window.setInterval(function () {
                        if (activeSocket.readyState !== 1) {
                            stopKeepalive();

                            return;
                        }

                        try {
                            activeSocket.send(JSON.stringify({ event: 'pusher:ping', data: {} }));
                        } catch (error) {
                            stopKeepalive();
                        }
                    }, every * 1000);
                }

                function subscribe(activeSocket, channelName, authorization) {
                    var data = {
                        auth: authorization.auth,
                        channel: channelName
                    };

                    if (authorization.channel_data) {
                        data.channel_data = authorization.channel_data;
                    }

                    activeSocket.send(JSON.stringify({
                        event: 'pusher:subscribe',
                        data: data
                    }));
                }

                function authorization(socketId, channelName) {
                    var body = new URLSearchParams();

                    body.set('socket_id', socketId);
                    body.set('channel_name', channelName);

                    return fetch(config.authEndpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                            'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                        },
                        body: body.toString()
                    })
                        .then(function (response) {
                            if (!response.ok) {
                                var failure = new Error('Broadcast authorization failed.');

                                failure.status = response.status;
                                throw failure;
                            }

                            return response.json();
                        });
                }

                function authorizationFailed(activeSocket, error) {
                    // Only the socket still in service may react.
                    if (activeSocket.wayfindrGeneration !== socketGeneration) {
                        return;
                    }

                    // The identity subscription succeeded, so a refusal of
                    // the content channel is an access revocation, not a
                    // network fault. Reloading clears the already-rendered
                    // transcript and lets the ordinary route boundary take
                    // over.
                    if (error && error.status === 403) {
                        window.location.reload();

                        return;
                    }

                    setStatus(realtimeLabels.cobrowseRealtime.failed, 'warning');

                    try {
                        activeSocket.close();
                    } catch (closeError) {
                        // Closing is best effort; the reconnect is what matters.
                    }

                    scheduleReconnect();
                }

                function authorizeIdentity(activeSocket, socketId) {
                    activeSocket.wayfindrSocketId = socketId;

                    authorization(socketId, config.identityChannelName)
                        .then(function (data) {
                            if (activeSocket.wayfindrGeneration !== socketGeneration) {
                                return;
                            }

                            subscribe(activeSocket, config.identityChannelName, data);
                        })
                        .catch(function (error) {
                            authorizationFailed(activeSocket, error);
                        });
                }

                function authorizeConversation(activeSocket) {
                    authorization(activeSocket.wayfindrSocketId, config.channelName)
                        .then(function (data) {
                            if (activeSocket.wayfindrGeneration !== socketGeneration) {
                                return;
                            }

                            subscribe(activeSocket, config.channelName, data);
                        })
                        .catch(function (error) {
                            authorizationFailed(activeSocket, error);
                        });
                }

                function conversationSubscriptionReady() {
                    setStatus(realtimeLabels.cobrowseRealtime.listening, 'listening');
                    reconnectDelay = 1000;

                    // Catch up the transcript after Reverb confirms the
                    // subscription. Anything posted between the server render
                    // and this acknowledgement is not replayed.
                    refreshTranscript();

                    if (hasConnectedOnce && hasCobrowseTargets) {
                        refreshCobrowsePreview();
                    }

                    hasConnectedOnce = true;
                }

                var socketScheme = config.scheme === 'https' ? 'wss' : 'ws';
                var socketUrl = socketScheme + '://' + config.host + ':' + config.port + '/app/' + encodeURIComponent(config.appKey) + '?protocol=7&client=wayfindr-agent&version=0.0.0&flash=false';
                var socket = null;

                // Which socket is in service, so a callback from one that has been
                // replaced can be told apart and ignored.
                var socketGeneration = 0;

                var reconnectDelay = 1000;
                var reconnectTimer = null;
                var pageClosing = false;
                var hasConnectedOnce = false;

                // A raw WebSocket does not reconnect on its own, so a Reverb
                // restart (deploys) or a network blip would otherwise leave the
                // agent page silently dead until a manual reload. Reconnect with
                // capped exponential backoff; each successful (re)subscribe resets
                // the delay and resyncs the transcript.
                function scheduleReconnect() {
                    if (pageClosing || reconnectTimer) {
                        return;
                    }

                    reconnectTimer = window.setTimeout(function () {
                        reconnectTimer = null;
                        connect();
                    }, reconnectDelay);

                    reconnectDelay = Math.min(reconnectDelay * 2, 15000);
                }

                function handleSocketMessage(message) {
                    var event;

                    try {
                        event = JSON.parse(message.data);
                    } catch (error) {
                        return;
                    }

                    // Reverb sends this on a quiet connection and closes the socket
                    // if nothing answers. It is a protocol MESSAGE, not a WebSocket
                    // control frame, so the browser does not reply on our behalf --
                    // the bundled Pusher client does that, and this page speaks the
                    // protocol itself.
                    //
                    // Without it the page still worked, which is why it went
                    // unnoticed: the socket was retired, the close handler
                    // reconnected, and the transcript refetched on resubscribe. A
                    // realtime page with a gap in it roughly every minute, on every
                    // install, since this socket was written.
                    //
                    // Answered on the socket the frame ARRIVED on, so a ping to one
                    // being replaced does not have its pong sent down the successor.
                    if (event.event === 'pusher:ping') {
                        try {
                            message.target.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
                        } catch (error) {
                            // A socket that cannot be written to is already gone, and
                            // the close handler reconnects.
                        }

                        return;
                    }

                    if (event.event === 'pusher:connection_established') {
                        var established = parsePayload(event.data);

                        startKeepalive(message.target, established.activity_timeout);
                        authorizeIdentity(message.target, established.socket_id);

                        return;
                    }

                    if (event.event === 'pusher_internal:subscription_succeeded') {
                        if (event.channel === config.identityChannelName) {
                            authorizeConversation(message.target);
                        } else if (event.channel === config.channelName) {
                            conversationSubscriptionReady();
                        }

                        return;
                    }

                    if (event.event === config.eventName) {
                        var cobrowsePayload = parsePayload(event.data);
                        var telemetry = updateLiveCobrowseTelemetry(cobrowsePayload);
                        var updateKind = cobrowsePayload.update ? cobrowsePayload.update.kind : '';
                        var summary = cobrowsePayload.summary || {};

                        // The metadata-only summary carries the visitor's clamped
                        // viewport width on every update kind, so a resize-only
                        // page_state report fixes the preview geometry immediately
                        // without refetching preview content.
                        if (typeof summary.viewport_width === 'number' && summary.viewport_width > 0) {
                            syncPreviewViewport(summary.viewport_width);
                        }

                        if (updateKind === 'snapshot') {
                            updateSnapshotFreshness(summary.snapshot);

                            if (config.previewUrl) {
                                setStatus(realtimeLabels.cobrowseRealtime.snapshot_received, 'listening');
                                refreshCobrowsePreview();
                            } else {
                                setStatus(realtimeLabels.cobrowseRealtime.snapshot_received_idle, 'available');

                                if (refresh) {
                                    refresh.hidden = false;
                                }
                            }

                            return;
                        }

                        if (updateKind === 'telemetry') {
                            setStatus(
                                telemetry && telemetry.resync_attempts_exhausted === true
                                    ? realtimeLabels.cobrowseRealtime.retry_limit
                                    : realtimeLabels.cobrowseRealtime.telemetry_updated,
                                telemetry && telemetry.resync_attempts_exhausted === true ? 'exhausted' : 'listening'
                            );

                            return;
                        }

                        // Only mutation batches change the rendered preview, so
                        // only they trigger a live re-fetch. Other kinds (page
                        // state, consent lifecycle) keep the calm manual cue so
                        // frequent page-state reports do not refetch needlessly.
                        if (config.previewUrl && updateKind === 'mutations') {
                            setStatus(realtimeLabels.cobrowseRealtime.changes_received, 'listening');
                            refreshCobrowsePreview();
                        } else {
                            setStatus(realtimeLabels.cobrowseRealtime.update_available, 'available');

                            if (refresh) {
                                refresh.hidden = false;
                            }
                        }
                    }

                    if (event.event === config.presenceEventName) {
                        updateVisitorPresence(parsePayload(event.data).visitor_presence);
                    }

                    if (event.event === config.readEventName) {
                        updateVisitorRead(parsePayload(event.data).visitor_read);
                    }

                    if (event.event === config.messageEventName) {
                        refreshTranscript();
                    }

                    if (event.event === config.typingEventName) {
                        updateVisitorTyping(parsePayload(event.data).visitor_typing);
                    }
                }

                function connect() {
                    // Which socket is in service. An authorization fetch or a close
                    // event for one that has since been replaced is answering about
                    // a connection nobody is using, and acting on it opens another
                    // socket beside the healthy one -- then another.
                    var generation = ++socketGeneration;

                    socket = new WebSocket(socketUrl);
                    socket.wayfindrGeneration = generation;
                    socket.addEventListener('message', handleSocketMessage);

                    socket.addEventListener('close', function () {
                        if (generation !== socketGeneration) {
                            return;
                        }

                        stopKeepalive();

                        if (hasCobrowseTargets && panel.dataset.state !== 'available') {
                            setStatus(realtimeLabels.cobrowseRealtime.disconnected, 'warning');
                        }

                        scheduleReconnect();
                    });

                    socket.addEventListener('error', function () {
                        // A close event follows and schedules the reconnect.
                    });
                }

                window.addEventListener('beforeunload', function () {
                    pageClosing = true;

                    if (socket) {
                        try {
                            socket.close();
                        } catch (error) {
                            // Ignore teardown errors.
                        }
                    }
                });

                connect();
            })();
        </script>
    @endif
</x-layouts.app>
