@props(['budgetDefaults'])

<section class="section" aria-labelledby="operator-cobrowse-budget-defaults-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-cobrowse-budget-defaults-heading">{{ __('operator.dashboard.budget.title') }}</h2>
            <p class="lede">{{ __('operator.dashboard.budget.subtitle') }}</p>
        </div>
    </div>

    <div class="notice-copy notice-copy-bordered">
        <p>
            {{ __('operator.dashboard.budget.boundary') }}
        </p>
    </div>

    @foreach ($budgetDefaults as $group)
        <div class="section-header">
            <div>
                <strong>{{ $group['label'] }}</strong>
                <p class="lede"><x-operator-feedback :feedback="$group['description']" /></p>
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
