@props([
    'title',
    'titleLang' => null,
    'agent',
    'account',
    'site',
])

@php
    // One site's pages, as the rail's context. Each destination is shown only
    // when its controller would open it for this viewer, asking the SAME
    // question the controller asks -- SiteContextRailTest holds each one to a
    // real request, so a link here cannot drift into a 404 there.
    $siteGate = \Illuminate\Support\Facades\Gate::forUser($agent);
    $siteViewable = $siteGate->allows('view', $site);

    $siteSections = [
        // AgentSiteController@show, @status and @tester: the `view` ability.
        ['label' => __('nav.site.settings'), 'href' => route('dashboard.sites.show', $site), 'visible' => $siteViewable, 'active' => request()->routeIs('dashboard.sites.show')],
        ['label' => __('nav.site.status'), 'href' => route('dashboard.sites.status', $site), 'visible' => $siteViewable, 'active' => request()->routeIs('dashboard.sites.status')],
        ['label' => __('nav.site.tester'), 'href' => route('dashboard.sites.tester', $site), 'visible' => $siteViewable, 'active' => request()->routeIs('dashboard.sites.tester')],
        // AgentSiteController@live: `viewLiveBoard`. Offered only while the
        // site collects presence, as the settings page offers it -- with
        // collection off the board has nothing to show. A reader already on
        // the board still sees where they are.
        ['label' => __('nav.site.live'), 'href' => route('dashboard.sites.live', $site), 'visible' => $siteGate->allows('viewLiveBoard', $site)
            && (\App\Support\Sites\SitePresenceReporting::for($site)->enabled || request()->routeIs('dashboard.sites.live')), 'active' => request()->routeIs('dashboard.sites.live')],
        // AgentProactiveMessageRuleController::manager(): automations
        // permission, and a live site this agent supports.
        ['label' => __('nav.site.proactive'), 'href' => route('dashboard.sites.proactive-messages.index', $site), 'visible' => $agent->account_id !== null
            && $agent->hasAccountPermission(\App\Enums\AccountPermission::ManageAutomations)
            && (int) $site->account_id === (int) $agent->account_id
            && ! $site->isArchived()
            && $site->supportsAgent($agent), 'active' => request()->routeIs('dashboard.sites.proactive-messages.*')],
    ];

    $siteSections = array_values(array_filter($siteSections, fn (array $section): bool => $section['visible']));

    // The settings page is the site itself; the rail item above it already
    // says "Sites", so a crumb would only repeat the page's own heading.
    $siteCrumb = request()->routeIs('dashboard.sites.show')
        ? null
        : (collect($siteSections)->firstWhere('active')['label'] ?? null);
@endphp

<x-layouts.app
    :title="$title"
    :title-lang="$titleLang"
    :agent="$agent"
    :account="$account"
    :crumb="$siteCrumb"
    :context="[
        'label' => __('nav.site.sections_label'),
        'title' => $site->name,
        'titleLang' => '',
        'icon' => 'sites',
        'color' => $site->resolvedColor()->cssVariable(),
        'back' => ['href' => route('dashboard.sites.index'), 'label' => __('nav.items.sites')],
        'groups' => [['label' => null, 'sections' => $siteSections]],
    ]"
>
    {{ $slot }}
</x-layouts.app>
