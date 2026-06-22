@props([
    'confirmationRoute' => null,
    'smokePath' => [],
])

<section class="section" aria-labelledby="post-install-smoke-path-heading">
    <div class="section-header">
        <div>
            <h2 id="post-install-smoke-path-heading">Post-install smoke path</h2>
            <p class="lede">A practical proof path before the install carries real visitor conversations.</p>
        </div>
        <span class="lede">{{ count($smokePath) }} steps</span>
    </div>

    <div class="readiness-list">
        @foreach ($smokePath as $step)
            <article class="readiness-check" data-status="{{ $step['status'] }}">
                <div class="readiness-check-main">
                    <div>
                        <span class="meta-label">Step {{ $loop->iteration }}</span>
                        <h3>{{ $step['label'] }}</h3>
                        <p>{{ $step['summary'] }}</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $step['status'] }}">
                        {{ $step['status_label'] }}
                    </span>
                </div>

                <p class="readiness-action">{{ $step['action'] }}</p>
                <x-operator-readiness-commands :commands="$step['commands'] ?? []" />
                <x-operator-readiness-confirmation-form :action="$confirmationRoute" :item="$step" />
            </article>
        @endforeach
    </div>
</section>
