<x-layouts.operator title="Operator console">
    @php
        $readinessConfirmationRoute = route('operator.readiness.confirmations.store');
        $operatorActivityCount = $operatorActivity->count();
        $operatorActivityLabel = $operatorActivityCount === 1 ? '1 safe event' : $operatorActivityCount.' safe events';
        $operatorActivityTotalLabel = $operatorActivityTotal === 1 ? '1 total safe event' : $operatorActivityTotal.' total safe events';
        $proofCoverageSummary = sprintf(
            '%d current / %d stale / %d missing',
            $readiness['proof_coverage']['fresh_count'],
            $readiness['proof_coverage']['stale_count'],
            $readiness['proof_coverage']['missing_count'],
        );

        // Badges mark problems only. The scheduler check is permanently
        // 'manual' and several gates wait on a person, so badging pending
        // work would put a permanent number on two tabs and teach operators
        // to ignore all of them -- the same mistake as amber pills on
        // resting states (ADR 0014).
        $consoleTabs = [
            ['id' => 'overview', 'label' => 'Overview'],
            // A badge has to point at the tab holding the problem. Health
            // uses the CHECK-only count, because attention_count also folds in
            // a retention failure that is read on the Data tab -- badging that
            // on Health would send an operator to a panel of green checks.
            ['id' => 'health', 'label' => 'Health', 'badge' => $readiness['check_attention_count'] ?: null],
            ['id' => 'golive', 'label' => 'Go live', 'badge' => $readiness['dogfood_summary']['attention_count'] ?: null],
            ['id' => 'data', 'label' => 'Data', 'badge' => $readiness['retention_needs_attention'] ? 1 : null],
            ['id' => 'access', 'label' => 'Access'],
        ];
    @endphp

    <x-page-header
        title="Operator console"
        :subtitle="'Signed in as '.$operator->name.'. Platform operator access does not grant support data access.'"
    >
        <x-slot:actions>
            {{-- One call to action. The four "Configure X" buttons that used to
                 sit here are every entry in the section sidebar beside this
                 page, and "Back to dashboard" is in the rail and the
                 breadcrumb. --}}
            <a class="button" href="{{ route('operator.onboarding') }}">Guided setup</a>
        </x-slot:actions>
    </x-page-header>

    <x-tabs id="operator-console" label="Operator console sections" :tabs="$consoleTabs">
        <x-tab-panel id="overview" :active="true">
        <section class="section" aria-labelledby="operator-focus-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-focus-heading">Operator focus</h2>
                    <p class="lede">How this installation is doing, without opening customer support data.</p>
                </div>
                <span class="readiness-status" data-status="{{ $readiness['attention_count'] > 0 ? 'attention' : 'ready' }}">
                    {{ $readiness['label'] }}
                </span>
            </div>

            <div class="meta-grid realtime-grid">
                <div class="meta-item">
                    <span class="meta-label">Status</span>
                    <span class="meta-value">{{ $readiness['label'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Confirmed checks</span>
                    <span class="meta-value">{{ $proofCoverageSummary }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Safe activity</span>
                    <span class="meta-value">{{ $operatorActivityTotalLabel }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Support data</span>
                    <span class="meta-value">Hidden here</span>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    Use this console to keep the installation healthy without opening customer support data.
                </p>
            </div>
        </section>

        @if (! empty($releaseNotices))
            <section class="section" aria-labelledby="release-notices-heading">
                <div class="section-header">
                    <div>
                        <h2 id="release-notices-heading">This release advises</h2>
                        <p class="lede">
                            Recommended for this install. None of these blocks migrations or
                            serving &mdash; the install is running normally.
                        </p>
                    </div>
                    <span class="pill">{{ count($releaseNotices) }} advisory</span>
                </div>

                <div class="management-list">
                    @foreach ($releaseNotices as $notice)
                        <div class="management-link" style="cursor: default;">
                            <span>
                                <strong>{{ $notice['summary'] ?? '' }}</strong>
                                @if (($notice['detail'] ?? '') !== '')
                                    <span class="lede">{{ $notice['detail'] }}</span>
                                @endif
                                @if (($notice['satisfied_by'] ?? null) === 'unevaluable')
                                    {{-- "Cannot tell" and "not done" are different facts, and an
                                         operator acting on the wrong one wastes their time. --}}
                                    <span class="lede">
                                        This install cannot check this automatically, so it may already be done.
                                    </span>
                                @endif
                                <span class="lede">
                                    Silence with <code>{{ ($notice['release'] ?? '?') }}/{{ ($notice['id'] ?? '?') }}</code>
                                    in <code>WAYFINDR_ACKNOWLEDGED_ACTIONS</code>.
                                </span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <x-operator-next-step :confirmation-route="$readinessConfirmationRoute" :next-step="$readiness['next_step']" />

        <section class="section" aria-labelledby="system-identity-heading">
            <div class="section-header">
                <div>
                    <h2 id="system-identity-heading">System identity</h2>
                    <p class="lede">Safe release and runtime details for support and troubleshooting.</p>
                </div>
            </div>

            <div class="meta-grid system-identity-grid">
                @foreach ($systemIdentity['items'] as $item)
                    <div class="meta-item">
                        <span class="meta-label">{{ $item['label'] }}</span>
                        <span class="meta-value">{{ $item['value'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="management-list">
                @foreach ($systemIdentity['docs'] as $doc)
                    <a class="management-link" href="{{ $doc['url'] }}" target="_blank" rel="noreferrer">
                        <span>
                            <strong>{{ $doc['label'] }}</strong>
                            <span class="lede">{{ $doc['description'] }}</span>
                        </span>
                        <span class="management-action">Open docs</span>
                    </a>
                @endforeach
            </div>
        </section>
        </x-tab-panel>

        <x-tab-panel id="health">
        <section class="section">
            <div class="section-header">
                <div>
                    <h2>Instance readiness</h2>
                    <p class="lede">Infrastructure checks for this Wayfindr installation.</p>
                </div>
                <span class="readiness-status" data-status="{{ $readiness['check_attention_count'] > 0 ? 'attention' : 'ready' }}">
                    {{ $readiness['check_attention_count'] > 0 ? 'Needs attention' : 'Ready' }}
                </span>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">Ready</span>
                    <span class="meta-value">{{ $readiness['ready_count'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Needs attention</span>
                    <span class="meta-value">{{ $readiness['check_attention_count'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">To confirm</span>
                    <span class="meta-value">{{ $readiness['manual_count'] }}</span>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    This first operator surface is intentionally small: readiness only, no conversation bodies,
                    ticket contents, cobrowse snapshots, transcripts, or site support queues.
                </p>
            </div>
        </section>

        <section class="section" aria-labelledby="operator-readiness-checks-heading">
            <div class="section-header">
                <h2 id="operator-readiness-checks-heading">Checks</h2>
                <span class="lede">{{ count($readiness['checks']) }} installation signals</span>
            </div>

            <div class="readiness-list">
                @foreach ($readiness['checks'] as $check)
                    <article class="readiness-check" data-status="{{ $check['status'] }}">
                        <div class="readiness-check-main">
                            <div>
                                <h3>{{ $check['label'] }}</h3>
                                <p>{{ $check['summary'] }}</p>
                            </div>
                            <span class="readiness-status" data-status="{{ $check['status'] }}">
                                {{ $check['status_label'] }}
                            </span>
                        </div>

                        <p class="lede">{{ $check['detail'] }}</p>
                        <p class="readiness-action">{{ $check['action'] }}</p>
                        <x-operator-readiness-commands :commands="$check['commands'] ?? []" />
                        <x-operator-readiness-confirmation-form :action="$readinessConfirmationRoute" id-prefix="operator-health" :item="$check" />
                    </article>
                @endforeach
            </div>
        </section>

        <section class="section" aria-labelledby="realtime-heading">
            <div class="section-header">
                <h2 id="realtime-heading">Realtime</h2>
                <span class="lede">{{ $realtimeHealth['label'] }}</span>
            </div>

            <div class="meta-grid realtime-grid">
                <div class="meta-item">
                    <span class="meta-label">Broadcast driver</span>
                    <span class="meta-value">{{ $realtimeHealth['driver'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Endpoint</span>
                    <span class="meta-value">{{ $realtimeHealth['endpoint'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Scheme</span>
                    <span class="meta-value">{{ $realtimeHealth['scheme'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">App ID</span>
                    <span class="meta-value">{{ $realtimeHealth['has_app_id'] ? 'Set' : 'Missing' }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">App key</span>
                    <span class="meta-value">{{ $realtimeHealth['has_app_key'] ? 'Set' : 'Missing' }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Secret</span>
                    <span class="meta-value">{{ $realtimeHealth['has_app_secret'] ? 'Set' : 'Missing' }}</span>
                </div>
            </div>

            <p class="empty realtime-note">{{ $realtimeHealth['message'] }}</p>
        </section>
        </x-tab-panel>

        <x-tab-panel id="golive">
        <x-operator-dogfood-summary :dogfood-summary="$readiness['dogfood_summary']" />

        <x-operator-smoke-path :confirmation-route="$readinessConfirmationRoute" :smoke-path="$readiness['smoke_path']" />

        <section class="section" aria-labelledby="readiness-proof-coverage-heading">
            <div class="section-header">
                <div>
                    <h2 id="readiness-proof-coverage-heading">What you have confirmed</h2>
                    <p class="lede">Some checks only a person can make. This tracks when each was last confirmed, never what the note said.</p>
                </div>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">Confirmed recently</span>
                    <span class="meta-value">{{ $readiness['proof_coverage']['fresh_count'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Due again</span>
                    <span class="meta-value">{{ $readiness['proof_coverage']['stale_count'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Never confirmed</span>
                    <span class="meta-value">{{ $readiness['proof_coverage']['missing_count'] }}</span>
                </div>
            </div>

            <div class="notice-copy notice-copy-bordered">
                <p>
                    Your notes are never shown here. Keep them to what you did, and leave out support codes,
                    visitor identifiers, conversation text, and ticket details.
                </p>
            </div>

            <div class="readiness-list">
                @foreach ($readiness['proof_coverage']['items'] as $item)
                    <article class="readiness-check" data-status="{{ $item['status'] === 'fresh' ? 'ready' : 'manual' }}">
                        <div class="readiness-check-main">
                            <div>
                                <h3>{{ $item['label'] }}</h3>
                                <p>{{ $item['summary'] }}</p>
                            </div>
                            <span class="readiness-status" data-status="{{ $item['status'] === 'fresh' ? 'ready' : 'manual' }}">
                                {{ $item['status_label'] }}
                            </span>
                        </div>
                        <p class="lede">{{ $item['note_status'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>
        </x-tab-panel>

        <x-tab-panel id="data">
        <x-operator-retention-summary :retention-summary="$readiness['retention_summary']" />

        <x-operator-cobrowse-budget-defaults :budget-defaults="$readiness['cobrowse_budget_defaults']" />

        <section class="section" aria-labelledby="operator-boundary-inventory-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-boundary-inventory-heading">What an operator can see</h2>
                    <p class="lede">What this console shows you, and what it keeps closed.</p>
                </div>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">Instance health</span>
                    <span class="meta-value">Safe for operators</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Support data</span>
                    <span class="meta-value">Not available here</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Operator access</span>
                    <span class="meta-value">Available — the account decides</span>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    Conversations, tickets, cobrowse snapshots, transcripts, and visitor page data stay out of operator screens.
                </p>
                <p>
                    Dashboard support routes remain account and site scoped. Platform operator access does not make someone a support agent for customer conversations, tickets, visitors, or sites.
                </p>
                <p>
                    Customer-data access is explicit, time-bound, and audited: an account grants it for one conversation, site or account, and can end it at any time.
                </p>
            </div>
        </section>

        <section class="section" aria-labelledby="operator-action-inventory-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-action-inventory-heading">What an operator can do</h2>
                    <p class="lede">Actions here affect the installation, never a customer's conversations or tickets.</p>
                </div>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">Available actions</span>
                    <span class="meta-value">Read-only</span>
                    <p class="lede">System identity and release checks help operators verify what is running.</p>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Readiness confirmations</span>
                    <span class="meta-value">Recorded and audited</span>
                    <p class="lede">Confirming backups, the scheduler or a restore records who did it and when.</p>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Access to support data</span>
                    <span class="meta-value">Only when an account grants it</span>
                    <p class="lede">Operators can ask an account for read-only access to one conversation, site or account. The account approves it, sees every page opened, and can end it at any time.</p>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    Operator actions should affect availability, readiness, retention, integrations, or instance
                    configuration; normal support data stays behind account and site access.
                </p>
                <p>
                    Conversations, tickets, cobrowse snapshots, transcripts, visitor identifiers, and site queues are
                    not platform action inputs.
                </p>
            </div>
        </section>
        </x-tab-panel>

        <x-tab-panel id="access">
        <section class="section" aria-labelledby="operator-break-glass-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-break-glass-heading">Operator access</h2>
                    <p class="lede">The only way to see an account’s support content, and only if they agree. Read-only, time-limited, and recorded for them.</p>
                </div>
                <a class="button secondary" href="{{ route('operator.break-glass.index') }}">Open</a>
            </div>
        </section>

        <section class="section" aria-labelledby="operator-activity-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-activity-heading">Recent operator activity</h2>
                    <p class="lede">Only safe instance-level operator actions are shown here.</p>
                </div>
                <span class="lede">
                    {{ $operatorActivityLabel }}
                </span>
            </div>

            <div class="notice-copy notice-copy-bordered">
                <p>
                    Support conversations, tickets, cobrowse snapshots, transcripts, visitor data, and account support
                    queues stay out of this feed.
                </p>
            </div>

            @if ($operatorActivity->isEmpty())
                <p class="empty">No operator activity yet.</p>
            @else
                <div class="timeline-list">
                    @foreach ($operatorActivity as $activity)
                        <article class="timeline-item internal-note">
                            <div class="timeline-content">
                                <strong>{{ $activity['label'] }}</strong>
                                <p class="message-body">{{ $activity['body'] }}</p>
                                @if ($activity['details'] !== [])
                                    <div class="operator-activity-details" aria-label="Safe evidence details">
                                        <span class="meta-label">Safe evidence details</span>
                                        <div class="meta-grid realtime-grid">
                                            @foreach ($activity['details'] as $detail)
                                                <div class="meta-item">
                                                    <span class="meta-label">{{ $detail['label'] }}</span>
                                                    <span class="meta-value">{{ $detail['value'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                <div class="timeline-meta">
                                    <span>{{ $activity['actor'] }}</span>
                                    @if ($activity['occurred_at'])
                                        <span>{{ $activity['occurred_at']->diffForHumans() }}</span>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
        </x-tab-panel>
    </x-tabs>
</x-layouts.operator>
