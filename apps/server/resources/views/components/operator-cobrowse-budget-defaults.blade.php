@props(['budgetDefaults'])

<section class="section" aria-labelledby="operator-cobrowse-budget-defaults-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-cobrowse-budget-defaults-heading">Cobrowse budget defaults</h2>
            <p class="lede">Safe default limits for stock widget payloads and server intake.</p>
        </div>
    </div>

    <div class="notice-copy notice-copy-bordered">
        <p>
            These values are product guardrails, not live support-session data. Support codes, visitor identifiers,
            page URLs, snapshots, transcripts, and queue contents stay out of readiness screens.
        </p>
    </div>

    @foreach ($budgetDefaults as $group)
        <div class="section-header">
            <div>
                <strong>{{ $group['label'] }}</strong>
                <p class="lede">{{ $group['description'] }}</p>
            </div>
        </div>

        <div class="meta-grid realtime-grid">
            @foreach ($group['items'] as $item)
                <div class="meta-item">
                    <span class="meta-label">{{ $item['label'] }}</span>
                    <span class="meta-value">{{ $item['value'] }}</span>
                </div>
            @endforeach
        </div>
    @endforeach
</section>
