@props([
    'dogfoodSummary',
])

<section class="section" aria-labelledby="operator-dogfood-summary-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-dogfood-summary-heading">Before real support traffic</h2>
            <p class="lede">What should be true before this install answers real visitors.</p>
        </div>
        <span class="readiness-status" data-status="{{ $dogfoodSummary['status'] }}">
            {{ $dogfoodSummary['label'] }}
        </span>
    </div>

    <div class="meta-grid readiness-summary-grid">
        <div class="meta-item">
            <span class="meta-label">Ready</span>
            <span class="meta-value">{{ $dogfoodSummary['ready_count'] }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">To confirm</span>
            <span class="meta-value">{{ $dogfoodSummary['manual_count'] }}</span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Not ready</span>
            <span class="meta-value">{{ $dogfoodSummary['attention_count'] }}</span>
        </div>
    </div>

    <div class="notice-copy">
        <p>{{ $dogfoodSummary['summary'] }}. These checks read configuration only. None of them opens a conversation or a ticket.</p>
    </div>

    <div class="readiness-list">
        @foreach ($dogfoodSummary['items'] as $item)
            <article class="readiness-check" data-status="{{ $item['status'] }}">
                <div class="readiness-check-main">
                    <div>
                        <h3>{{ $item['label'] }}</h3>
                        <p>{{ $item['summary'] }}</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $item['status'] }}">
                        {{ $item['status_label'] }}
                    </span>
                </div>

                <p class="lede">{{ $item['detail'] }}</p>
                <p class="readiness-action">{{ $item['action'] }}</p>
                <x-operator-readiness-commands :commands="$item['commands'] ?? []" />

                @if ($item['docs_url'])
                    <p>
                        <a class="text-link" href="{{ $item['docs_url'] }}" target="_blank" rel="noreferrer">
                            Open guidance
                        </a>
                    </p>
                @endif
            </article>
        @endforeach
    </div>
</section>
