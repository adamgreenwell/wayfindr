@props([
    'confirmationRoute' => null,
    'nextStep',
])

<section class="section" aria-labelledby="operator-next-step-heading">
    <div class="section-header">
        <div>
            <h2 id="operator-next-step-heading">{{ __('operator.dashboard.next.title') }}</h2>
            <p class="lede">{{ __('operator.dashboard.next.subtitle') }}</p>
        </div>
        <span class="readiness-status" data-status="{{ $nextStep['status'] }}">
            {{ $nextStep['status_label'] }}
        </span>
    </div>

    <article class="readiness-check" data-status="{{ $nextStep['status'] }}">
        <div class="readiness-check-main">
            <div>
                <h3>{{ $nextStep['label'] }}</h3>
                <p><x-operator-feedback :feedback="$nextStep['summary']" /></p>
            </div>
        </div>

        @if (($nextStep['detail'] ?? '') !== '')
            <p class="lede"><x-operator-feedback :feedback="$nextStep['detail']" /></p>
        @endif
        <p class="readiness-action"><x-operator-feedback :feedback="$nextStep['action']" /></p>
        <x-operator-readiness-commands :commands="$nextStep['commands'] ?? []" />
        <x-operator-readiness-confirmation-form :action="$confirmationRoute" id-prefix="operator-next-step" :item="$nextStep" />
    </article>
</section>
