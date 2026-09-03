<x-layouts.operator :title="__('operator.onboarding.document_title')">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.onboarding.title')"
        :subtitle="__('operator.onboarding.subtitle')" />

    @if (session('status'))
        <p class="status-message"><x-operator-feedback :feedback="session('status')" /></p>
    @endif

    @if (session('error'))
        <p class="status-message"><x-operator-feedback :feedback="session('error')" /></p>
    @endif

    <section class="section" aria-labelledby="onboarding-progress-heading">
        <div class="section-header">
            <div>
                <h2 id="onboarding-progress-heading">{{ __('operator.onboarding.essential_steps') }}</h2>
                <p class="lede">{{ __('operator.onboarding.progress', [
                    'ready' => \App\Support\ReaderNumber::count($readyCount),
                    'total' => \App\Support\ReaderNumber::count($totalCount),
                ]) }}</p>
            </div>
            <span class="readiness-status" data-status="{{ $readyCount === $totalCount ? 'ready' : 'attention' }}">
                {{ $readyCount === $totalCount
                    ? __('operator.onboarding.all_ready')
                    : trans_choice('operator.onboarding.to_go', $totalCount - $readyCount, [
                        'count' => \App\Support\ReaderNumber::count($totalCount - $readyCount),
                    ]) }}
            </span>
        </div>

        @if ($site)
            <article class="readiness-check" data-status="manual">
                <div class="readiness-check-main">
                    <div>
                        <h3>{{ __('operator.onboarding.connect_site') }}</h3>
                        <p>{!! __('operator.onboarding.connect_site_body', [
                            'site' => '<strong lang="">'.e($site->name).'</strong>',
                        ]) !!}</p>
                    </div>
                    <a class="button" href="{{ route('dashboard.sites.show', $site) }}#install-snippet">{{ __('operator.onboarding.install_snippet') }}</a>
                </div>
            </article>
        @endif
    </section>

    <section class="section" aria-labelledby="onboarding-steps-heading">
        <div class="section-header">
            <h2 id="onboarding-steps-heading">{{ __('operator.onboarding.configure_essentials') }}</h2>
            <span class="lede">{{ trans_choice('operator.onboarding.steps', $totalCount, [
                'count' => \App\Support\ReaderNumber::count($totalCount),
            ]) }}</span>
        </div>

        <div class="readiness-list">
            @foreach ($steps as $step)
                @php($check = $step['check'])
                <article class="readiness-check" data-status="{{ $check['status'] }}">
                    <div class="readiness-check-main">
                        <div>
                            <h3>{{ $check['label'] }}</h3>
                            <p><x-operator-feedback :feedback="$check['summary']" /></p>
                        </div>
                        <span class="readiness-status" data-status="{{ $check['status'] }}">
                            {{ $check['status_label'] }}
                        </span>
                    </div>

                    <p class="lede"><x-operator-feedback :feedback="$check['detail']" /></p>
                    <p class="readiness-action"><x-operator-feedback :feedback="$check['action']" /></p>

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
            <p>{!! __('operator.onboarding.full_diagnostic', [
                'link' => '<a class="text-link" href="'.e(route('operator.dashboard')).'">'.e(__('operator.onboarding.full_diagnostic_link')).'</a>',
            ]) !!}</p>
        </div>
    </section>
</x-layouts.operator>
