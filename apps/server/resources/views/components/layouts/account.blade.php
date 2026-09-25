@props([
    'title',
    // An article's title is the account's words, not the dashboard's; see the
    // same prop on the app layout.
    'titleLang' => null,
])

@php
    // Resolved here rather than threaded through a dozen controllers, the same
    // way the operator layout does it. Every route that reaches this layout is
    // behind auth and belongs to an account, so a user is guaranteed.
    $accountAgent = auth()->user();
    $accountAccount = $accountAgent?->account;

    $accountMember = $accountAgent?->account_id !== null;
    $accountCan = static fn (\App\Enums\AccountPermission $permission): bool => $accountAgent?->hasAccountPermission($permission) === true;

    // Each destination is shown only when its controller would open it for
    // this viewer. Authorization lives in the controllers, not in route
    // middleware, so every `visible` below asks the SAME question its
    // controller does -- AccountContextSidebarTest holds each one to a real
    // request, so a link here cannot drift into a 403 there, nor a page this
    // viewer can open drift out of the sidebar.
    $accountSectionGroups = [
        [
            'label' => __('account_shell.groups.account'),
            'sections' => [
                // AgentAccountController: any member of the account.
                ['label' => __('account_shell.sections.overview'), 'href' => route('dashboard.account.show'), 'visible' => $accountMember, 'active' => request()->routeIs('dashboard.account.show')],
                ['label' => __('account_shell.sections.roles'), 'href' => route('dashboard.account.roles.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageRoles), 'active' => request()->routeIs('dashboard.account.roles.*')],
                ['label' => __('account_shell.sections.security'), 'href' => route('dashboard.account.security.show'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageSecurity), 'active' => request()->routeIs('dashboard.account.security.*')],
            ],
        ],
        [
            'label' => __('account_shell.groups.content'),
            'sections' => [
                ['label' => __('account_shell.sections.articles'), 'href' => route('dashboard.account.articles.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageKnowledge), 'active' => request()->routeIs('dashboard.account.articles.*')],
                ['label' => __('account_shell.sections.reply_templates'), 'href' => route('dashboard.account.reply-templates.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageKnowledge), 'active' => request()->routeIs('dashboard.account.reply-templates.*')],
                ['label' => __('account_shell.sections.labels'), 'href' => route('dashboard.account.labels.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageKnowledge), 'active' => request()->routeIs('dashboard.account.labels.*')],
                ['label' => __('account_shell.sections.visitor_attributes'), 'href' => route('dashboard.account.visitor-attributes.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageContacts), 'active' => request()->routeIs('dashboard.account.visitor-attributes.*')],
            ],
        ],
        [
            'label' => __('account_shell.groups.workflow'),
            'sections' => [
                // Rules and macros are one page to the reader: the macro forms
                // are reached from, and return to, the automations list.
                ['label' => __('account_shell.sections.automations'), 'href' => route('dashboard.account.automation-rules.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageAutomations), 'active' => request()->routeIs('dashboard.account.automation-rules.*', 'dashboard.account.automation-macros.*')],
                // SLA targets are site configuration, so ManageSites -- not a
                // support permission.
                ['label' => __('account_shell.sections.sla_policies'), 'href' => route('dashboard.account.sla-policies.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageSites), 'active' => request()->routeIs('dashboard.account.sla-policies.*')],
            ],
        ],
        [
            'label' => __('account_shell.groups.connections'),
            'sections' => [
                // Readable by every member; the page itself decides what a
                // viewer without ManageIntegrations may change.
                ['label' => __('account_shell.sections.integrations'), 'href' => route('dashboard.account.integrations'), 'visible' => $accountMember, 'active' => request()->routeIs('dashboard.account.integrations')],
                ['label' => __('account_shell.sections.api'), 'href' => route('dashboard.account.api-tokens.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageIntegrations), 'active' => request()->routeIs('dashboard.account.api-tokens.*')],
            ],
        ],
        [
            'label' => __('account_shell.groups.oversight'),
            'sections' => [
                ['label' => __('account_shell.sections.audit'), 'href' => route('dashboard.account.audit.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ViewAudit), 'active' => request()->routeIs('dashboard.account.audit.*')],
                ['label' => __('account_shell.sections.operator_access'), 'href' => route('dashboard.account.break-glass.index'), 'visible' => $accountCan(\App\Enums\AccountPermission::ManageOperatorAccess), 'active' => request()->routeIs('dashboard.account.break-glass.*')],
            ],
        ],
    ];

    // A group whose every destination is hidden loses its heading too, rather
    // than announcing a section the viewer has nothing in.
    $accountSectionGroups = collect($accountSectionGroups)
        ->map(fn (array $group): array => [...$group, 'sections' => array_values(array_filter($group['sections'], fn (array $section): bool => $section['visible']))])
        ->filter(fn (array $group): bool => $group['sections'] !== [])
        ->values()
        ->all();

    $accountCrumb = collect($accountSectionGroups)->flatMap(fn (array $group): array => $group['sections'])->firstWhere('active')['label'] ?? null;
@endphp

<x-layouts.app :title="$title" :title-lang="$titleLang" :agent="$accountAgent" :account="$accountAccount" :crumb="$accountCrumb">
    <div class="wf-context">
        {{-- One contextual sidebar in place of the account overview's jump
             list, its directory of management pages, and the three different
             "back to account" links those pages used to get out again. The
             rail says you are in Account; this says where in it. --}}
        <nav class="wf-context-nav" aria-label="{{ __('account_shell.sections_label') }}">
            @foreach ($accountSectionGroups as $group)
                <p class="wf-context-heading">{{ $group['label'] }}</p>

                @foreach ($group['sections'] as $section)
                    <a
                        class="wf-context-link"
                        href="{{ $section['href'] }}"
                        @if ($section['active']) aria-current="page" @endif
                    >{{ $section['label'] }}</a>
                @endforeach
            @endforeach
        </nav>

        {{-- No flash region here, unlike the operator layout: account pages
             already render their own status messages, several with runtime
             parameters of their own. --}}
        <div class="wf-context-body">
            {{ $slot }}
        </div>
    </div>
</x-layouts.app>
