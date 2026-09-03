@props([
    'dogfoodSummary',
])

<section class="section" aria-labelledby="operator-dogfood-summary-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-dogfood-summary-heading">{{ __('operator.dashboard.dogfood.title') }}</h2>
            <p class="lede">{{ __('operator.dashboard.dogfood.subtitle') }}</p>
        </div>
        <span class="readiness-status" data-status="{{ $dogfoodSummary['status'] }}">
            {{ $dogfoodSummary['label'] }}
        </span>
    </div>

    <div class="meta-grid readiness-summary-grid">
        <div class="meta-item">
            <span class="meta-label">{{ __('operator.readiness.status.ready') }}</span>
            <span class="meta-value">{{ \App\Support\ReaderNumber::count($dogfoodSummary['ready_count']) }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">{{ __('operator.dashboard.common.to_confirm') }}</span>
            <span class="meta-value">{{ \App\Support\ReaderNumber::count($dogfoodSummary['manual_count']) }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">{{ __('operator.dashboard.common.not_ready') }}</span>
            <span class="meta-value">{{ \App\Support\ReaderNumber::count($dogfoodSummary['attention_count']) }}</span>
        </div>
    </div>

    <div class="notice-copy">
        <p><x-operator-feedback :feedback="$dogfoodSummary['summary']" /> {{ __('operator.dashboard.dogfood.boundary') }}</p>
    </div>

    <div class="readiness-list">
        @foreach ($dogfoodSummary['items'] as $item)
            <article class="readiness-check" data-status="{{ $item['status'] }}">
                <div class="readiness-check-main">
                    <div>
                        <h3>{{ $item['label'] }}</h3>
                        <p><x-operator-feedback :feedback="$item['summary']" /></p>
                    </div>
                    <span class="readiness-status" data-status="{{ $item['status'] }}">
                        {{ $item['status_label'] }}
                    </span>
                </div>

                <p class="lede"><x-operator-feedback :feedback="$item['detail']" /></p>
                <p class="readiness-action"><x-operator-feedback :feedback="$item['action']" /></p>
                <x-operator-readiness-commands :commands="$item['commands'] ?? []" />

                @if ($item['docs_url'])
                    <p>
                        <a class="text-link" href="{{ $item['docs_url'] }}" target="_blank" rel="noreferrer">
                            {{ __('operator.dashboard.common.open_guidance') }}
                        </a>
                    </p>
                @endif
            </article>
        @endforeach
    </div>
</section>
