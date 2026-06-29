<x-layouts.app title="Operator Readiness" :agent="$agent" :account="$account">
            @php($readinessConfirmationRoute = route('dashboard.readiness.confirmations.store'))

            <x-page-header title="Operator readiness" subtitle="A calm install checkup for the pieces that usually make self-hosted support feel mysterious." />

            <section class="section" aria-labelledby="readiness-summary-heading">
                <div class="section-header">
                    <h2 id="readiness-summary-heading">Readiness summary</h2>
                    <span class="readiness-status" data-status="{{ $readiness['attention_count'] > 0 ? 'attention' : 'ready' }}">
                        {{ $readiness['label'] }}
                    </span>
                </div>

                <div class="meta-grid readiness-summary-grid">
                    <div class="meta-item">
                        <span class="meta-label">Ready</span>
                        <span class="meta-value">{{ $readiness['ready_count'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Needs attention</span>
                        <span class="meta-value">{{ $readiness['attention_count'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Manual checks</span>
                        <span class="meta-value">{{ $readiness['manual_count'] }}</span>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>These diagnostics are intentionally practical. They flag missing setup, then point to the next safe step instead of making operators read server tea leaves.</p>
                </div>
            </section>

            <x-operator-dogfood-summary :dogfood-summary="$readiness['dogfood_summary']" />

            <x-operator-retention-summary :retention-summary="$readiness['retention_summary']" />

            <x-operator-next-step :confirmation-route="$readinessConfirmationRoute" :next-step="$readiness['next_step']" />

            <x-operator-smoke-path :confirmation-route="$readinessConfirmationRoute" :smoke-path="$readiness['smoke_path']" />

            <x-operator-cobrowse-budget-defaults :budget-defaults="$readiness['cobrowse_budget_defaults']" />

            <section class="section" aria-labelledby="readiness-checks-heading">
                <div class="section-header">
                    <h2 id="readiness-checks-heading">Checks</h2>
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
                            <x-operator-readiness-confirmation-form :action="$readinessConfirmationRoute" :item="$check" />
                        </article>
                    @endforeach
                </div>
            </section>
</x-layouts.app>
