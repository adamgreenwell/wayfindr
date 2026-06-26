@props([
    'retentionSummary',
])

<section class="section" aria-labelledby="operator-retention-summary-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-retention-summary-heading">Retention posture</h2>
            <p class="lede">Plain-language data retention visibility for this install.</p>
        </div>
        <span class="readiness-status" data-status="{{ $retentionSummary['status'] }}">
            {{ $retentionSummary['status_label'] }}
        </span>
    </div>

    <div class="meta-grid readiness-summary-grid">
        <div class="meta-item">
            <span class="meta-label">Current posture</span>
            <span class="meta-value">{{ $retentionSummary['label'] }}</span>
        </div>
        @foreach ($retentionSummary['items'] as $item)
            <div class="meta-item">
                <span class="meta-label">{{ $item['label'] }}</span>
                <span class="meta-value">{{ $item['value'] }}</span>
                @if ($item['description'] !== '')
                    <p class="lede">{{ $item['description'] }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="notice-copy">
        <p>{{ $retentionSummary['summary'] }}</p>
        <p>{{ $retentionSummary['description'] }}</p>
        @foreach ($retentionSummary['reminders'] as $reminder)
            <p>{{ $reminder }}</p>
        @endforeach
    </div>

    @if ($retentionSummary['docs_url'])
        <p>
            <a class="text-link" href="{{ $retentionSummary['docs_url'] }}" target="_blank" rel="noreferrer">
                Open retention guidance
            </a>
        </p>
    @endif
</section>
