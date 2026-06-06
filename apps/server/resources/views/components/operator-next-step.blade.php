@props([
    'confirmationRoute' => null,
    'nextStep',
])

<section class="section" aria-labelledby="operator-next-step-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-next-step-heading">Recommended next step</h2>
            <p class="lede">The one action most likely to move this install forward.</p>
        </div>
        <span class="readiness-status" data-status="{{ $nextStep['status'] }}">
            {{ $nextStep['status_label'] }}
        </span>
    </div>

    <article class="readiness-check" data-status="{{ $nextStep['status'] }}">
        <div class="readiness-check-main">
            <div>
                <h3>{{ $nextStep['label'] }}</h3>
                <p>{{ $nextStep['summary'] }}</p>
            </div>
        </div>

        <p class="lede">{{ $nextStep['detail'] }}</p>
        <p class="readiness-action">{{ $nextStep['action'] }}</p>
        <x-operator-readiness-confirmation-form :action="$confirmationRoute" :item="$nextStep" />
    </article>
</section>
