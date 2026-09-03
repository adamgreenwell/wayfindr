@props([
    'confirmationRoute' => null,
    'smokePath' => [],
])

<section class="section" aria-labelledby="post-install-smoke-path-heading">
    <div class="section-header">
        <div>
            <h2 id="post-install-smoke-path-heading">{{ __('operator.dashboard.smoke.title') }}</h2>
            <p class="lede">{{ __('operator.dashboard.smoke.subtitle') }}</p>
        </div>
        <span class="lede">{{ trans_choice('operator.dashboard.smoke.count', count($smokePath), ['count' => \App\Support\ReaderNumber::count(count($smokePath))]) }}</span>
    </div>

    <div class="readiness-list">
        @foreach ($smokePath as $step)
            <article class="readiness-check" data-status="{{ $step['status'] }}">
                <div class="readiness-check-main">
                    <div>
                        <span class="meta-label">{{ __('operator.dashboard.smoke.step', ['count' => \App\Support\ReaderNumber::count($loop->iteration)]) }}</span>
                        <h3>{{ $step['label'] }}</h3>
                        <p><x-operator-feedback :feedback="$step['summary']" /></p>
                    </div>
                    <span class="readiness-status" data-status="{{ $step['status'] }}">
                        {{ $step['status_label'] }}
                    </span>
                </div>

                <p class="readiness-action"><x-operator-feedback :feedback="$step['action']" /></p>
                <x-operator-readiness-commands :commands="$step['commands'] ?? []" />
                <x-operator-readiness-confirmation-form :action="$confirmationRoute" id-prefix="operator-smoke-path" :item="$step" />
            </article>
        @endforeach
    </div>
</section>
