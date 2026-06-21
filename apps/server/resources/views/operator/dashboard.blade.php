<x-layouts.app title="Operator console">
    @php($readinessConfirmationRoute = route('operator.readiness.confirmations.store'))

    <p><a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a></p>
    <h1>Operator console</h1>
    <p class="lede">
        Signed in as {{ $operator->name }}. Platform operator access does not grant support data access.
    </p>

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

    <section class="section">
        <div class="section-header">
            <div>
                <h2>Instance readiness</h2>
                <p class="lede">Infrastructure checks for this Wayfindr installation.</p>
            </div>
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
            <p>
                This first operator surface is intentionally small: readiness only, no conversation bodies,
                ticket contents, cobrowse snapshots, transcripts, or site support queues.
            </p>
        </div>
    </section>

    <section class="section" aria-labelledby="operator-boundary-inventory-heading">
        <div class="section-header">
            <div>
                <h2 id="operator-boundary-inventory-heading">Boundary inventory</h2>
                <p class="lede">A quick map of what platform operators can inspect here.</p>
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
                <span class="meta-label">Break-glass access</span>
                <span class="meta-value">Future scoped workflow</span>
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
                Any future customer-data access must be explicit, time-bound, and audited.
            </p>
        </div>
    </section>

    <section class="section" aria-labelledby="operator-action-inventory-heading">
        <div class="section-header">
            <div>
                <h2 id="operator-action-inventory-heading">Platform action inventory</h2>
                <p class="lede">The operator console lists instance-level actions without making support data an input.</p>
            </div>
        </div>

        <div class="meta-grid readiness-summary-grid">
            <div class="meta-item">
                <span class="meta-label">Current safe actions</span>
                <span class="meta-value">Read-only</span>
                <p class="lede">System identity and release checks help operators verify what is running.</p>
            </div>
            <div class="meta-item">
                <span class="meta-label">Instance readiness confirmations</span>
                <span class="meta-value">Audited manual proof</span>
                <p class="lede">Manual backup, scheduler, and restore confirmations record safe operator evidence.</p>
            </div>
            <div class="meta-item">
                <span class="meta-label">Future break-glass actions</span>
                <span class="meta-value">Not enabled</span>
                <p class="lede">Customer-data access requires explicit scope, expiry, approval, and audit before it exists.</p>
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

    <section class="section" aria-labelledby="operator-activity-heading">
        <div class="section-header">
            <div>
                <h2 id="operator-activity-heading">Recent operator activity</h2>
                <p class="lede">Only safe instance-level operator actions are shown here.</p>
            </div>
            <span class="lede">
                {{ $operatorActivity->count() === 1 ? '1 safe event' : $operatorActivity->count().' safe events' }}
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

    <x-operator-next-step :confirmation-route="$readinessConfirmationRoute" :next-step="$readiness['next_step']" />

    <x-operator-smoke-path :confirmation-route="$readinessConfirmationRoute" :smoke-path="$readiness['smoke_path']" />

    <x-operator-cobrowse-budget-defaults :budget-defaults="$readiness['cobrowse_budget_defaults']" />

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
                    <x-operator-readiness-confirmation-form :action="$readinessConfirmationRoute" :item="$check" />
                </article>
            @endforeach
        </div>
    </section>
</x-layouts.app>
