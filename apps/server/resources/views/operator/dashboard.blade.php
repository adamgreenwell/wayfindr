<x-layouts.operator :title="__('operator.dashboard.document_title')">
    @php
        $readinessConfirmationRoute = route('operator.readiness.confirmations.store');
        $operatorActivityCount = $operatorActivity->count();
        $operatorActivityLabel = trans_choice('operator.dashboard.activity.shown', $operatorActivityCount, [
            'count' => \App\Support\ReaderNumber::count($operatorActivityCount),
        ]);
        $operatorActivityTotalLabel = trans_choice('operator.dashboard.activity.total', $operatorActivityTotal, [
            'count' => \App\Support\ReaderNumber::count($operatorActivityTotal),
        ]);
        $proofCoverageSummary = __('operator.dashboard.overview.proof_counts', [
            'current' => \App\Support\ReaderNumber::count($readiness['proof_coverage']['fresh_count']),
            'stale' => \App\Support\ReaderNumber::count($readiness['proof_coverage']['stale_count']),
            'missing' => \App\Support\ReaderNumber::count($readiness['proof_coverage']['missing_count']),
        ]);

        // Badges mark problems only. The scheduler check is permanently
        // 'manual' and several gates wait on a person, so badging pending
        // work would put a permanent number on two tabs and teach operators
        // to ignore all of them -- the same mistake as amber pills on
        // resting states (ADR 0014).
        $consoleTabs = [
            ['id' => 'overview', 'label' => __('operator.dashboard.tabs.overview')],
            // A badge has to point at the tab holding the problem. Health
            // uses the CHECK-only count, because attention_count also folds in
            // a retention failure that is read on the Data tab -- badging that
            // on Health would send an operator to a panel of green checks.
            ['id' => 'health', 'label' => __('operator.dashboard.tabs.health'), 'badge' => $readiness['check_attention_count'] ? \App\Support\ReaderNumber::count($readiness['check_attention_count']) : null],
            ['id' => 'golive', 'label' => __('operator.dashboard.tabs.go_live'), 'badge' => $readiness['dogfood_summary']['attention_count'] ? \App\Support\ReaderNumber::count($readiness['dogfood_summary']['attention_count']) : null],
            ['id' => 'data', 'label' => __('operator.dashboard.tabs.data'), 'badge' => $readiness['retention_needs_attention'] ? \App\Support\ReaderNumber::count(1) : null],
            ['id' => 'access', 'label' => __('operator.dashboard.tabs.access')],
        ];
    @endphp

    <x-page-header
        :title="__('operator.dashboard.title')"
    >
        <x-slot:subtitleContent>
            <x-operator-feedback :feedback="[
                'key' => 'operator.dashboard.subtitle',
                'parameters' => ['name' => $operator->name],
            ]" />
        </x-slot:subtitleContent>
        <x-slot:actions>
            {{-- One call to action. The four "Configure X" buttons that used to
                 sit here are every entry in the section sidebar beside this
                 page, and "Back to dashboard" is in the rail and the
                 breadcrumb. --}}
            <a class="button" href="{{ route('operator.onboarding') }}">{{ __('operator.dashboard.guided_setup') }}</a>
        </x-slot:actions>
    </x-page-header>

    <x-tabs id="operator-console" :label="__('operator.dashboard.tabs.label')" :tabs="$consoleTabs">
        <x-tab-panel id="overview" :active="true">
        <section class="section" aria-labelledby="operator-focus-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-focus-heading">{{ __('operator.dashboard.overview.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.overview.subtitle') }}</p>
                </div>
                <span class="readiness-status" data-status="{{ $readiness['attention_count'] > 0 ? 'attention' : 'ready' }}">
                    {{ $readiness['label'] }}
                </span>
            </div>

            <div class="meta-grid realtime-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.overview.status') }}</span>
                    <span class="meta-value">{{ $readiness['label'] }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.overview.confirmed_checks') }}</span>
                    <span class="meta-value">{{ $proofCoverageSummary }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.overview.safe_activity') }}</span>
                    <span class="meta-value">{{ $operatorActivityTotalLabel }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.overview.support_data') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.overview.hidden_here') }}</span>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    {{ __('operator.dashboard.overview.boundary') }}
                </p>
            </div>
        </section>

        @if (! empty($releaseNotices))
            <section class="section" aria-labelledby="release-notices-heading">
                <div class="section-header">
                    <div>
                        <h2 id="release-notices-heading">{{ __('operator.dashboard.release.title') }}</h2>
                        <p class="lede">
                            {{ __('operator.dashboard.release.subtitle') }}
                        </p>
                    </div>
                    <span class="pill">{{ trans_choice('operator.dashboard.release.count', count($releaseNotices), ['count' => \App\Support\ReaderNumber::count(count($releaseNotices))]) }}</span>
                </div>

                <div class="management-list">
                    @foreach ($releaseNotices as $notice)
                        <div class="management-link" style="cursor: default;">
                            <span>
                                <strong lang="">{{ $notice['summary'] ?? '' }}</strong>
                                @if (($notice['detail'] ?? '') !== '')
                                    <span class="lede" lang="">{{ $notice['detail'] }}</span>
                                @endif
                                @if (($notice['satisfied_by'] ?? null) === 'unevaluable')
                                    {{-- "Cannot tell" and "not done" are different facts, and an
                                         operator acting on the wrong one wastes their time. --}}
                                    <span class="lede">
                                        {{ __('operator.dashboard.release.cannot_evaluate') }}
                                    </span>
                                @endif
                                <span class="lede">
                                    {!! __('operator.dashboard.release.silence', [
                                        'reference' => '<code lang="">'.e(($notice['release'] ?? '?').'/'.($notice['id'] ?? '?')).'</code>',
                                        'setting' => '<code lang="">WAYFINDR_ACKNOWLEDGED_ACTIONS</code>',
                                    ]) !!}
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
                    <h2 id="system-identity-heading">{{ __('operator.dashboard.system.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.system.subtitle') }}</p>
                </div>
            </div>

            <div class="meta-grid system-identity-grid">
                @foreach ($systemIdentity['items'] as $item)
                    <div class="meta-item">
                        <span class="meta-label">{{ $item['label'] }}</span>
                        <span class="meta-value"><x-operator-feedback :feedback="$item['value']" /></span>
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
                        <span class="management-action">{{ __('operator.dashboard.system.open_docs') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
        </x-tab-panel>

        <x-tab-panel id="health">
        <section class="section">
            <div class="section-header">
                <div>
                    <h2>{{ __('operator.dashboard.health.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.health.subtitle') }}</p>
                </div>
                <span class="readiness-status" data-status="{{ $readiness['check_attention_count'] > 0 ? 'attention' : 'ready' }}">
                    {{ $readiness['check_attention_count'] > 0 ? __('operator.readiness.status.needs_attention') : __('operator.readiness.status.ready') }}
                </span>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.readiness.status.ready') }}</span>
                    <span class="meta-value">{{ \App\Support\ReaderNumber::count($readiness['ready_count']) }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.readiness.status.needs_attention') }}</span>
                    <span class="meta-value">{{ \App\Support\ReaderNumber::count($readiness['check_attention_count']) }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.common.to_confirm') }}</span>
                    <span class="meta-value">{{ \App\Support\ReaderNumber::count($readiness['manual_count']) }}</span>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    {{ __('operator.dashboard.health.boundary') }}
                </p>
            </div>
        </section>

        <section class="section" aria-labelledby="operator-readiness-checks-heading">
            <div class="section-header">
                <h2 id="operator-readiness-checks-heading">{{ __('operator.dashboard.health.checks') }}</h2>
                <span class="lede">{{ trans_choice('operator.dashboard.health.signal_count', count($readiness['checks']), ['count' => \App\Support\ReaderNumber::count(count($readiness['checks']))]) }}</span>
            </div>

            <div class="readiness-list">
                @foreach ($readiness['checks'] as $check)
                    <article class="readiness-check" data-status="{{ $check['status'] }}">
                        <div class="readiness-check-main">
                            <div>
                                <h3>{{ $check['label'] }}</h3>
                                <p><x-operator-feedback :feedback="$check['summary']" /></p>
                            </div>
                            <span class="readiness-status" data-status="{{ $check['status'] }}">
                                {{ $check['status_label'] }}
                            </span>
                        </div>

                        <p class="lede"><x-operator-feedback :feedback="$check['detail']" /></p>
                        <p class="readiness-action"><x-operator-feedback :feedback="$check['action']" /></p>
                        <x-operator-readiness-commands :commands="$check['commands'] ?? []" />
                        <x-operator-readiness-confirmation-form :action="$readinessConfirmationRoute" id-prefix="operator-health" :item="$check" />
                    </article>
                @endforeach
            </div>
        </section>

        <section class="section" aria-labelledby="realtime-heading">
            <div class="section-header">
                <h2 id="realtime-heading">{{ __('operator.dashboard.realtime.title') }}</h2>
                <span class="lede">{{ $realtimeHealth['label'] }}</span>
            </div>

            <div class="meta-grid realtime-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.realtime.driver') }}</span>
                    <span class="meta-value"><x-operator-feedback :feedback="$realtimeHealth['driver']" /></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.realtime.endpoint') }}</span>
                    <span class="meta-value"><x-operator-feedback :feedback="$realtimeHealth['endpoint']" /></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.realtime.scheme') }}</span>
                    <span class="meta-value"><x-operator-feedback :feedback="$realtimeHealth['scheme']" /></span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.realtime.app_id') }}</span>
                    <span class="meta-value">{{ $realtimeHealth['has_app_id'] ? __('operator.dashboard.common.set') : __('operator.dashboard.common.missing') }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.realtime.app_key') }}</span>
                    <span class="meta-value">{{ $realtimeHealth['has_app_key'] ? __('operator.dashboard.common.set') : __('operator.dashboard.common.missing') }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.realtime.secret') }}</span>
                    <span class="meta-value">{{ $realtimeHealth['has_app_secret'] ? __('operator.dashboard.common.set') : __('operator.dashboard.common.missing') }}</span>
                </div>
            </div>

            <p class="empty realtime-note"><x-operator-feedback :feedback="$realtimeHealth['message']" /></p>
        </section>
        </x-tab-panel>

        <x-tab-panel id="golive">
        <x-operator-dogfood-summary :dogfood-summary="$readiness['dogfood_summary']" />

        <x-operator-smoke-path :confirmation-route="$readinessConfirmationRoute" :smoke-path="$readiness['smoke_path']" />

        <section class="section" aria-labelledby="readiness-proof-coverage-heading">
            <div class="section-header">
                <div>
                    <h2 id="readiness-proof-coverage-heading">{{ __('operator.dashboard.proof.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.proof.subtitle') }}</p>
                </div>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.proof.confirmed_recently') }}</span>
                    <span class="meta-value">{{ \App\Support\ReaderNumber::count($readiness['proof_coverage']['fresh_count']) }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.readiness.status.due_again') }}</span>
                    <span class="meta-value">{{ \App\Support\ReaderNumber::count($readiness['proof_coverage']['stale_count']) }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.proof.never_confirmed') }}</span>
                    <span class="meta-value">{{ \App\Support\ReaderNumber::count($readiness['proof_coverage']['missing_count']) }}</span>
                </div>
            </div>

            <div class="notice-copy notice-copy-bordered">
                <p>
                    {{ __('operator.dashboard.proof.note_boundary') }}
                </p>
            </div>

            <div class="readiness-list">
                @foreach ($readiness['proof_coverage']['items'] as $item)
                    <article class="readiness-check" data-status="{{ $item['status'] === 'fresh' ? 'ready' : 'manual' }}">
                        <div class="readiness-check-main">
                            <div>
                                <h3>{{ $item['label'] }}</h3>
                                <p><x-operator-feedback :feedback="$item['summary']" /></p>
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
                    <h2 id="operator-boundary-inventory-heading">{{ __('operator.dashboard.boundary.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.boundary.subtitle') }}</p>
                </div>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.boundary.instance_health') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.boundary.safe') }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.boundary.support_data') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.boundary.unavailable') }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.boundary.operator_access') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.boundary.account_decides') }}</span>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    {{ __('operator.dashboard.boundary.copy.1') }}
                </p>
                <p>
                    {{ __('operator.dashboard.boundary.copy.2') }}
                </p>
                <p>
                    {{ __('operator.dashboard.boundary.copy.3') }}
                </p>
            </div>
        </section>

        <section class="section" aria-labelledby="operator-action-inventory-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-action-inventory-heading">{{ __('operator.dashboard.actions.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.actions.subtitle') }}</p>
                </div>
            </div>

            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.actions.available') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.actions.read_only') }}</span>
                    <p class="lede">{{ __('operator.dashboard.actions.available_detail') }}</p>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.actions.confirmations') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.actions.recorded') }}</span>
                    <p class="lede">{{ __('operator.dashboard.actions.confirmations_detail') }}</p>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.dashboard.actions.support_access') }}</span>
                    <span class="meta-value">{{ __('operator.dashboard.actions.only_when_granted') }}</span>
                    <p class="lede">{{ __('operator.dashboard.actions.support_access_detail') }}</p>
                </div>
            </div>

            <div class="notice-copy">
                <p>
                    {{ __('operator.dashboard.actions.copy.1') }}
                </p>
                <p>
                    {{ __('operator.dashboard.actions.copy.2') }}
                </p>
            </div>
        </section>
        </x-tab-panel>

        <x-tab-panel id="access">
        <section class="section" aria-labelledby="operator-break-glass-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-break-glass-heading">{{ __('operator.dashboard.access.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.access.subtitle') }}</p>
                </div>
                <a class="button secondary" href="{{ route('operator.break-glass.index') }}">{{ __('operator.dashboard.access.open') }}</a>
            </div>
        </section>

        <section class="section" aria-labelledby="operator-activity-heading">
            <div class="section-header">
                <div>
                    <h2 id="operator-activity-heading">{{ __('operator.dashboard.activity.title') }}</h2>
                    <p class="lede">{{ __('operator.dashboard.activity.subtitle') }}</p>
                </div>
                <span class="lede">
                    {{ $operatorActivityLabel }}
                </span>
            </div>

            <div class="notice-copy notice-copy-bordered">
                <p>
                    {{ __('operator.dashboard.activity.boundary') }}
                </p>
            </div>

            @if ($operatorActivity->isEmpty())
                <p class="empty">{{ __('operator.dashboard.activity.empty') }}</p>
            @else
                <div class="timeline-list">
                    @foreach ($operatorActivity as $activity)
                        <article class="timeline-item internal-note">
                            <div class="timeline-content">
                                <strong>{{ $activity['label'] }}</strong>
                                <p class="message-body"><x-operator-feedback :feedback="$activity['body']" /></p>
                                @if ($activity['details'] !== [])
                                    <div class="operator-activity-details" aria-label="{{ __('operator.dashboard.activity.safe_details') }}">
                                        <span class="meta-label">{{ __('operator.dashboard.activity.safe_details') }}</span>
                                        <div class="meta-grid realtime-grid">
                                            @foreach ($activity['details'] as $detail)
                                                <div class="meta-item">
                                                    <span class="meta-label">{{ $detail['label'] }}</span>
                                                    <span class="meta-value"><x-operator-feedback :feedback="$detail['value']" /></span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                <div class="timeline-meta">
                                    <span><x-operator-feedback :feedback="$activity['actor']" /></span>
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
