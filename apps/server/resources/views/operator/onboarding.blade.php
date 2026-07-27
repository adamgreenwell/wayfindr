<x-layouts.app title="Set up Wayfindr">
    <p><a class="text-link" href="{{ route('operator.dashboard') }}">Operator console</a></p>

    <x-page-header
        title="Set up your installation"
        subtitle="A guided walk to a runnable Wayfindr — configure the essentials in the browser, mail first." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status-message">{{ session('error') }}</p>
    @endif

    <section class="section" aria-labelledby="onboarding-progress-heading">
        <div class="section-header">
            <div>
                <h2 id="onboarding-progress-heading">Essential steps</h2>
                <p class="lede">{{ $readyCount }} of {{ $totalCount }} ready. Work top to bottom.</p>
            </div>
            <span class="readiness-status" data-status="{{ $readyCount === $totalCount ? 'ready' : 'attention' }}">
                {{ $readyCount === $totalCount ? 'All essentials ready' : ($totalCount - $readyCount).' to go' }}
            </span>
        </div>

        @if ($site)
            <article class="readiness-check" data-status="manual">
                <div class="readiness-check-main">
                    <div>
                        <h3>Connect your first site</h3>
                        <p>Install the widget on <strong>{{ $site->name }}</strong> to start receiving support conversations.</p>
                    </div>
                    <a class="button" href="{{ route('dashboard.sites.show', $site) }}#install-snippet">Get the install snippet</a>
                </div>
            </article>
        @endif
    </section>

    <section class="section" aria-labelledby="onboarding-steps-heading">
        <div class="section-header">
            <h2 id="onboarding-steps-heading">Configure the essentials</h2>
            <span class="lede">{{ $totalCount }} steps</span>
        </div>

        <div class="readiness-list">
            @foreach ($steps as $step)
                @php($check = $step['check'])
                <article class="readiness-check" data-status="{{ $check['status'] }}">
                    <div class="readiness-check-main">
                        <div>
                            <h3>{{ $check['label'] }}</h3>
                            <p>{{ $check['summary'] }}</p>
                        </div>
                        <span class="readiness-status" data-status="{{ $check['status'] }}">
                            {{ $check['status_label'] }}
                        </span>
                    </div>

                    <p class="lede">{{ $check['detail'] }}</p>
                    <p class="readiness-action">{{ $check['action'] }}</p>

                    @if ($step['configure_url'] && $step['configure_label'])
                        <p>
                            <a class="button @if ($check['status'] === 'ready') secondary @endif" href="{{ $step['configure_url'] }}">
                                {{ $step['configure_label'] }}
                            </a>
                        </p>
                    @endif

                    <x-operator-readiness-commands :commands="$check['commands'] ?? []" />
                    <x-operator-readiness-confirmation-form :action="$confirmationRoute" :item="$check" return-to="onboarding" />
                </article>
            @endforeach
        </div>

        <div class="notice-copy">
            <p>
                This is the short path to a running install. The operator console has the
                <a class="text-link" href="{{ route('operator.dashboard') }}">full instance diagnostic</a>
                — every check, smoke path, and readiness proof.
            </p>
        </div>
    </section>
</x-layouts.app>
