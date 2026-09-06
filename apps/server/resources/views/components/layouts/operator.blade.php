@props(['title'])

@php
    // Resolved here rather than threaded through eleven controllers. Every
    // route that reaches this layout is already behind auth and the platform
    // operator gate, so a user is guaranteed; an account is not, and the app
    // layout falls back to a bare page when one is missing.
    $operatorAgent = auth()->user();
    $operatorAccount = $operatorAgent?->account;

    $operatorSections = [
        ['label' => __('operator.shell.sections.console'), 'href' => route('operator.dashboard'), 'active' => request()->routeIs('operator.dashboard')],
        ['label' => __('operator.shell.sections.onboarding'), 'href' => route('operator.onboarding'), 'active' => request()->routeIs('operator.onboarding')],
        ['label' => __('operator.shell.sections.mail'), 'href' => route('operator.settings.mail.edit'), 'active' => request()->routeIs('operator.settings.mail.*')],
        ['label' => __('operator.shell.sections.webpush'), 'href' => route('operator.settings.webpush.edit'), 'active' => request()->routeIs('operator.settings.webpush.*')],
        ['label' => __('operator.shell.sections.ai'), 'href' => route('operator.settings.ai.edit'), 'active' => request()->routeIs('operator.settings.ai.*')],
        ['label' => __('operator.shell.sections.storage'), 'href' => route('operator.settings.storage.edit'), 'active' => request()->routeIs('operator.settings.storage.*')],
        ['label' => __('operator.shell.sections.scanning'), 'href' => route('operator.settings.scanning.edit'), 'active' => request()->routeIs('operator.settings.scanning.*')],
        ['label' => __('operator.shell.sections.backups'), 'href' => route('operator.settings.backups.edit'), 'active' => request()->routeIs('operator.settings.backups.*')],
        ['label' => __('operator.shell.sections.localization'), 'href' => route('operator.settings.localization.edit'), 'active' => request()->routeIs('operator.settings.localization.*')],
        ['label' => __('operator.shell.sections.operator_access'), 'href' => route('operator.break-glass.index'), 'active' => request()->routeIs('operator.break-glass.*')],
    ];
@endphp

@php
    $operatorCrumb = collect($operatorSections)->firstWhere('active')['label'] ?? null;
@endphp

<x-layouts.app :title="$title" :agent="$operatorAgent" :account="$operatorAccount" :crumb="$operatorCrumb">
    <div class="wf-context">
        {{-- A second sidebar for one deep object, the way an object's own
             sections work in the platforms this direction came from. The rail
             says which part of the product you are in; this says which part of
             the operator console. --}}
        <nav class="wf-context-nav" aria-label="{{ __('operator.shell.sections_label') }}">
            <p class="wf-context-heading">{{ __('operator.shell.heading') }}</p>

            @foreach ($operatorSections as $section)
                <a
                    class="wf-context-link"
                    href="{{ $section['href'] }}"
                    @if ($section['active']) aria-current="page" @endif
                >{{ $section['label'] }}</a>
            @endforeach
        </nav>

        <div class="wf-context-body">
            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
