@props(['title'])

@php
    // Resolved here rather than threaded through eleven controllers. Every
    // route that reaches this layout is already behind auth and the platform
    // operator gate, so a user is guaranteed; an account is not, and the app
    // layout falls back to a bare page when one is missing.
    $operatorAgent = auth()->user();
    $operatorAccount = $operatorAgent?->account;

    $operatorSections = [
        ['label' => 'Console', 'href' => route('operator.dashboard'), 'active' => request()->routeIs('operator.dashboard')],
        ['label' => 'Setup checklist', 'href' => route('operator.onboarding'), 'active' => request()->routeIs('operator.onboarding')],
        ['label' => 'Mail', 'href' => route('operator.settings.mail.edit'), 'active' => request()->routeIs('operator.settings.mail.*')],
        ['label' => 'Storage', 'href' => route('operator.settings.storage.edit'), 'active' => request()->routeIs('operator.settings.storage.*')],
        ['label' => 'Scanning', 'href' => route('operator.settings.scanning.edit'), 'active' => request()->routeIs('operator.settings.scanning.*')],
        ['label' => 'Backups', 'href' => route('operator.settings.backups.edit'), 'active' => request()->routeIs('operator.settings.backups.*')],
        ['label' => 'Break-glass', 'href' => route('operator.break-glass.index'), 'active' => request()->routeIs('operator.break-glass.*')],
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
        <nav class="wf-context-nav" aria-label="Operator sections">
            <p class="wf-context-heading">Operator</p>

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
