<x-layouts.app title="Operator console">
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

    <x-operator-smoke-path :smoke-path="$readiness['smoke_path']" />

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
                </article>
            @endforeach
        </div>
    </section>
</x-layouts.app>
