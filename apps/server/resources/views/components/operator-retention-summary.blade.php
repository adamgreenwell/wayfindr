@props([
    'retentionSummary',
])

<section class="section" aria-labelledby="operator-retention-summary-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-retention-summary-heading">{{ __('operator.dashboard.retention.title') }}</h2>
            <p class="lede">{{ __('operator.dashboard.retention.subtitle') }}</p>
        </div>
        <span class="readiness-status" data-status="{{ $retentionSummary['status'] }}">
            {{ $retentionSummary['status_label'] }}
        </span>
    </div>

    <div class="meta-grid readiness-summary-grid">
        <div class="meta-item">
            <span class="meta-label">{{ __('operator.dashboard.retention.current') }}</span>
            <span class="meta-value"><x-operator-feedback :feedback="$retentionSummary['label']" /></span>
        </div>
        @foreach ($retentionSummary['items'] as $item)
            <div class="meta-item">
                <span class="meta-label"><x-operator-feedback :feedback="$item['label']" /></span>
                <span class="meta-value"><x-operator-feedback :feedback="$item['value']" /></span>
                @if ($item['description'] !== '')
                    <p class="lede"><x-operator-feedback :feedback="$item['description']" /></p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="notice-copy">
        <p><x-operator-feedback :feedback="$retentionSummary['summary']" /></p>
        <p><x-operator-feedback :feedback="$retentionSummary['description']" /></p>
        @foreach ($retentionSummary['reminders'] as $reminder)
            <p><x-operator-feedback :feedback="$reminder" /></p>
        @endforeach
    </div>

    @if ($retentionSummary['docs_url'])
        <p>
            <a class="text-link" href="{{ $retentionSummary['docs_url'] }}" target="_blank" rel="noreferrer">
                {{ __('operator.dashboard.retention.open_guidance') }}
            </a>
        </p>
    @endif
</section>
