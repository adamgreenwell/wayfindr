<x-layouts.app :title="__('site_settings.title')" :agent="$agent" :account="$account">
            <x-page-header :back-href="route('dashboard.sites.index')" :back-label="__('site_settings.back')">
                <x-slot:titleContent><span lang="">{{ $site->name }}</span></x-slot:titleContent>
                <x-slot:subtitleContent><x-translated-feedback :feedback="[
                    'key' => 'site_settings.subtitle',
                    ...($site->domain
                        ? ['parameters' => ['domain' => $site->domain]]
                        : ['localized_parameters' => ['domain' => __('site_settings.unconfigured_domain')]]),
                ]" /></x-slot:subtitleContent>
            </x-page-header>

            @if ($siteStatusFeedback)
                <p class="status-message">
                    @if (is_array($siteStatusFeedback))
                        <x-translated-feedback :feedback="$siteStatusFeedback" />
                    @else
                        {{ __($siteStatusFeedback) }}
                    @endif
                </p>
            @endif

            @if ($site->isArchived())
                <section class="section" aria-labelledby="site-archived-heading">
                    <div class="section-header">
                        <h2 id="site-archived-heading">{{ __('site_settings.archived.heading') }}</h2>
                        <span class="readiness-status">{{ __('site_settings.archived.status', ['elapsed' => $site->archived_at->diffForHumans()]) }}</span>
                    </div>

                    <p class="lede">
                        {{ __('site_settings.archived.body') }}
                    </p>

                    @can('archive', $site)
                        <form method="POST" action="{{ route('dashboard.sites.unarchive', $site) }}">
                            @csrf
                            <button class="button" type="submit">{{ __('site_settings.archived.restore') }}</button>
                        </form>
                    @endcan
                </section>
            @endif

            @php
                $latestVisitor = $site->latestVisitor;
                $lastSeenAt = $latestVisitor?->last_seen_at;
                $lastPageUrl = data_get($latestVisitor?->metadata, 'last_page_url');
                $installAttentionSiteUrl = $site->domain ? 'https://'.$site->domain : null;
                $installAttentionGuidance = [
                    'key' => $lastSeenAt ? 'site_settings.setup.stale' : 'site_settings.setup.not_installed',
                    ...($site->domain
                        ? ['parameters' => ['target' => $site->domain]]
                        : ['localized_parameters' => ['target' => __('site_settings.setup.site_fallback')]]),
                ];
                $installVerificationRefreshUrl = route('dashboard.sites.show', [
                    'site' => $site,
                    'verify' => now()->timestamp,
                ]).'#install-verification';
                $selectedSupportAgentIds = collect(old('support_agent_ids', $supportAgentIds))
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $selectedCapabilities = collect(old('capabilities', ['create_issue']))
                    ->filter()
                    ->map(fn ($capability) => (string) $capability)
                    ->all();
                $siteMapSections = [
                    ['label' => __('site_settings.map.sections.readiness'), 'href' => '#site-support-readiness-heading'],
                    ['label' => __('site_settings.map.sections.load'), 'href' => '#site-support-load-heading'],
                    ['label' => __('site_settings.map.sections.external_readiness'), 'href' => '#site-external-issue-readiness-heading'],
                ];

                if ($installHealth['needs_attention']) {
                    $siteMapSections[] = ['label' => __('site_settings.map.sections.setup'), 'href' => '#setup-attention-heading'];
                }

                $siteMapSections[] = ['label' => __('site_settings.map.sections.site'), 'href' => '#site-context-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.verification'), 'href' => '#install-verification-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.snippet'), 'href' => '#install-snippet-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.access'), 'href' => '#support-access-heading'];

                if ($canViewSiteActivity) {
                    $siteMapSections[] = ['label' => __('site_settings.map.sections.activity'), 'href' => '#site-access-activity-heading'];
                }

                $siteMapSections[] = ['label' => __('site_settings.map.sections.routing'), 'href' => '#external-issue-routing-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.rating'), 'href' => '#rating-prompt-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.data'), 'href' => '#data-responsibility-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.presence'), 'href' => '#presence-settings-heading'];
                $siteMapSections[] = ['label' => __('site_settings.map.sections.privacy'), 'href' => '#privacy-settings-heading'];
            @endphp

            <section class="section" aria-labelledby="site-map-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-map-heading">{{ __('site_settings.map.heading') }}</h2>
                    </div>
                    <span class="lede">{{ trans_choice('site_settings.map.count', count($siteMapSections), ['count' => \App\Support\ReaderNumber::count(count($siteMapSections))]) }}</span>
                </div>

                <div class="filter-summary" aria-label="{{ __('site_settings.map.aria') }}">
                    <div>
                        <strong>{{ __('site_settings.map.jump') }}</strong>
                    </div>
                    <div class="filter-chips">
                        @foreach ($siteMapSections as $siteMapSection)
                            <a class="filter-chip" href="{{ $siteMapSection['href'] }}">{{ $siteMapSection['label'] }}</a>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="site-support-readiness-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-support-readiness-heading">{{ __('site_settings.readiness.heading') }}</h2>
                    </div>
                    <a class="button secondary" href="{{ route('dashboard.sites.tester', $site) }}">{{ __('site_settings.common.open_tester') }}</a>
                </div>

                <div class="readiness-list">
                    @foreach ($siteSupportReadiness as $readinessItem)
                        <article class="readiness-check" data-status="{{ $readinessItem['tone'] }}">
                            <div class="readiness-check-main">
                                <div>
                                    <span class="meta-label">{{ $readinessItem['label'] }}</span>
                                    <h3>{{ $readinessItem['value'] }}</h3>
                                    <p>{{ $readinessItem['detail'] }}</p>
                                </div>
                                <span class="readiness-status" data-status="{{ $readinessItem['tone'] }}">
                                    {{ __('site_settings.common.tones.'.$readinessItem['tone']) }}
                                </span>
                            </div>
                            <p class="readiness-action">
                                <a class="text-link" href="{{ $readinessItem['href'] }}">{{ $readinessItem['action'] }}</a>
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="site-support-load-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-support-load-heading">{{ __('site_settings.load.heading') }}</h2>
                    </div>
                </div>

                <div class="meta-grid">
                    @foreach ($siteSupportLoad as $loadItem)
                        <div class="meta-item">
                            <span class="meta-label">{{ $loadItem['label'] }}</span>
                            <span class="meta-value">{{ $loadItem['value'] }}</span>
                            <p class="lede">{{ $loadItem['detail'] }}</p>
                            <p class="readiness-action">
                                <a class="text-link" href="{{ $loadItem['href'] }}">{{ $loadItem['action'] }}</a>
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="site-external-issue-readiness-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-external-issue-readiness-heading">{{ __('site_settings.external.heading') }}</h2>
                    </div>
                    <span class="readiness-status" data-status="{{ $externalIssueHealth['tone'] }}">{{ $externalIssueHealth['label'] }}</span>
                </div>

                <div class="notice-copy notice-copy-bordered">
                    <p>{{ $externalIssueHealth['detail'] }}</p>
                    <p><a class="text-link" href="#external-issue-routing-heading">{{ __('site_settings.external.review_routing') }}</a></p>
                </div>

                <div class="meta-grid">
                    @foreach ($externalIssueHealth['metrics'] as $metric)
                        <div class="meta-item">
                            <span class="meta-label">{{ $metric['label'] }}</span>
                            <span class="meta-value">{{ $metric['value'] }}</span>
                            <span class="readiness-status" data-status="{{ $metric['tone'] }}">{{ __('site_settings.common.tones.'.$metric['tone']) }}</span>
                            @if (! empty($metric['href']) && ! empty($metric['action']))
                                <p class="readiness-action">
                                    <a class="text-link" href="{{ $metric['href'] }}">{{ $metric['action'] }}</a>
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($externalIssueHealth['recent_failures']->isEmpty())
                    <p class="empty">{{ __('site_settings.external.failures.empty') }}</p>
                @else
                    <div class="timeline-list">
                        @foreach ($externalIssueHealth['recent_failures'] as $failure)
                            <article class="timeline-item internal-note">
                                <div class="timeline-content">
                                    <strong>{{ __($loop->first ? 'site_settings.external.failures.last' : 'site_settings.external.failures.earlier') }}</strong>
                                    <p class="message-body"><x-translated-feedback :feedback="$failure['body_feedback']" /></p>
                                    <div class="timeline-meta">
                                        @if ($failure['status'])
                                            <span><x-translated-feedback :feedback="['key' => 'site_settings.external.failures.status', 'parameters' => ['status' => $failure['status']]]" /></span>
                                        @endif
                                        @if ($failure['occurred_at'])
                                            <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                        @endif
                                        <span>{{ __('site_settings.external.failures.details_withheld') }}</span>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($installHealth['needs_attention'])
                <section class="section" aria-labelledby="setup-attention-heading">
                    <div class="section-header">
                        <h2 id="setup-attention-heading">{{ __('site_settings.setup.heading') }}</h2>
                        <span class="readiness-status" data-status="{{ $installHealth['tone'] }}">{{ $installHealth['label'] }}</span>
                    </div>

                    <div class="notice-copy">
                        <p><strong>{{ $installVerification['message'] }}</strong></p>
                        <p><x-translated-feedback :feedback="$installAttentionGuidance" /></p>
                        <div class="notice-actions">
                            @if ($installAttentionSiteUrl)
                                <a class="button secondary" href="{{ $installAttentionSiteUrl }}" rel="noopener noreferrer" target="_blank">{{ __('site_settings.setup.open_site') }}</a>
                            @endif
                            <a class="button secondary" href="#install-snippet">{{ __('site_settings.setup.snippet') }}</a>
                            <a class="button" href="{{ $installVerificationRefreshUrl }}">{{ __('site_settings.setup.verify') }}</a>
                        </div>
                    </div>
                </section>
            @endif

            <section class="section" aria-labelledby="site-context-heading">
                <div class="section-header">
                    <h2 id="site-context-heading">{{ __('site_settings.site.heading') }}</h2>
                    <span class="lede">{{ __('site_settings.site.lede') }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.name') }}</span>
                        <span class="meta-value" lang="">{{ $site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.domain') }}</span>
                        @if ($site->domain)
                            <span class="meta-value" lang="">{{ $site->domain }}</span>
                        @else
                            <span class="meta-value">{{ __('site_settings.common.not_set') }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.public_key') }}</span>
                        <span class="meta-value" lang="">{{ $site->public_key }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.public_config') }}</span>
                        <span class="meta-value">{{ __('site_settings.site.mask_only') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.latest') }}</span>
                        <span class="meta-value">
                            @if ($latestVisitor?->last_seen_at)
                                {{ __('site_settings.site.seen', ['elapsed' => $latestVisitor->last_seen_at->diffForHumans()]) }}
                            @else
                                {{ __('site_settings.site.not_seen') }}
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.last_page') }}</span>
                        @if ($lastPageUrl)
                            <span class="meta-value" lang="">{{ $lastPageUrl }}</span>
                        @else
                            <span class="meta-value">{{ __('site_settings.common.not_reported') }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.site.lab') }}</span>
                        <a class="text-link" href="{{ route('dashboard.sites.tester', $site) }}">{{ __('site_settings.common.open_tester') }}</a>
                    </div>
                </div>

                @can('update', $site)
                    <x-details-disclosure :summary="__('site_settings.site.edit')">
                        <form class="section-form" method="POST" action="{{ route('dashboard.sites.details.update', $site) }}">
                            @csrf
                            @method('PUT')

                            <div class="field">
                                <label for="site_name">{{ __('site_settings.site.name') }}</label>
                                <input id="site_name" name="name" type="text" value="{{ old('name', $site->name) }}" maxlength="255" lang="" required>
                                @error('name')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="site_domain">{{ __('site_settings.site.domain') }}</label>
                                <input id="site_domain" name="domain" type="text" value="{{ old('domain', $site->domain) }}" maxlength="255" placeholder="support.example.com" lang="" autocomplete="off">
                                @error('domain')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <fieldset class="field">
                                <legend>{{ __('site_settings.site.colour') }}</legend>
                                <div class="wf-color-picker">
                                    @foreach (\App\Enums\SiteColor::cases() as $option)
                                        <span class="wf-color-option">
                                            <input
                                                id="site_color_{{ $option->value }}"
                                                name="color"
                                                type="radio"
                                                value="{{ $option->value }}"
                                                @checked(old('color', $site->resolvedColor()->value) === $option->value)
                                            >
                                            <label class="wf-color-swatch" for="site_color_{{ $option->value }}">
                                                <i style="background: var({{ $option->cssVariable() }})"></i>
                                                {{ __('site_settings.site.colours.'.$option->value) }}
                                            </label>
                                        </span>
                                    @endforeach
                                </div>
                                @error('color')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                                <p class="field-help">
                                    {{ __('site_settings.site.colour_help') }}
                                </p>
                            </fieldset>

                            <p class="field-help">
                                {{ __('site_settings.site.details_help') }}
                            </p>

                            <button class="button" type="submit">{{ __('site_settings.site.save') }}</button>
                        </form>
                    </x-details-disclosure>
                @endcan
            </section>

            <section id="install-verification" class="section" aria-labelledby="install-verification-heading">
                <div class="section-header">
                    <h2 id="install-verification-heading">{{ __('site_settings.verification.heading') }}</h2>
                    <div class="section-actions">
                        <a class="text-link" href="{{ $installVerificationRefreshUrl }}">{{ __('site_settings.verification.verify') }}</a>
                        <span class="readiness-status" data-status="{{ $installVerification['tone'] }}">{{ $installVerification['status'] }}</span>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>{{ $installVerification['message'] }}</p>
                    <p>{{ $installVerification['guidance'] }}</p>

                    @if ($lastPageUrl)
                        <p><strong>{{ __('site_settings.verification.last_page') }}</strong>: <span lang="">{{ $lastPageUrl }}</span></p>
                    @else
                        <p><strong>{{ __('site_settings.verification.last_page') }}</strong>: {{ __('site_settings.verification.not_reported') }}</p>
                    @endif
                </div>

                <div class="meta-grid realtime-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('site_settings.verification.host_check') }}</span>
                        <span class="readiness-status" data-status="{{ $installHostDiagnostic['tone'] }}" data-install-host-status>{{ $installHostDiagnostic['label'] }}</span>
                        <span class="lede" data-install-host-detail><x-translated-feedback :feedback="$installHostDiagnostic['detail_feedback']" /></span>
                    </div>
                </div>
            </section>

            <section id="install-snippet" class="section" aria-labelledby="install-snippet-heading">
                <div class="section-header">
                    <h2 id="install-snippet-heading">{{ __('site_settings.snippet.heading') }}</h2>
                    <div class="section-actions">
                        @if ($agent->isPlatformOperator())
                            <a class="text-link" href="{{ route('operator.dashboard') }}">{{ __('site_settings.snippet.operator') }}</a>
                        @endif
                        <span class="lede">{{ __('site_settings.snippet.copy_ready') }}</span>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>
                        @if ($site->domain)
                            <x-translated-feedback :feedback="['key' => 'site_settings.snippet.use_domain', 'parameters' => ['domain' => $site->domain]]" />
                        @else
                            {{ __('site_settings.snippet.use_site') }}
                        @endif
                    </p>
                    <p>{!! __('site_settings.snippet.paste', ['closing_tag' => '<code lang="">&lt;/body&gt;</code>']) !!}</p>

                    <div class="notice-list" aria-label="{{ __('site_settings.snippet.steps_aria') }}">
                        <p><strong>{{ __('site_settings.snippet.steps.heading') }}</strong></p>
                        <p><x-translated-feedback :feedback="[
                            'key' => 'site_settings.snippet.steps.copy',
                            ...($site->domain
                                ? ['parameters' => ['site' => $site->domain]]
                                : ['localized_parameters' => ['site' => __('site_settings.snippet.steps.fallback_site')]]),
                        ]" /></p>
                        <p>{{ __('site_settings.snippet.steps.tester') }}</p>
                        <p>{{ __('site_settings.snippet.steps.message') }}</p>
                        @if ($agent->isPlatformOperator())
                            <p>{{ __('site_settings.snippet.steps.operator') }}</p>
                        @endif
                        <p>{{ __('site_settings.snippet.steps.readiness') }}</p>
                    </div>
                </div>

                <pre class="code-block"><code lang="">{{ $widgetInstallSnippet }}</code></pre>
                <div class="notice-actions">
                    <a class="button secondary" href="{{ route('dashboard.sites.tester', $site) }}">{{ __('site_settings.common.open_tester') }}</a>
                </div>
            </section>

            @if ($agent->isAdmin() || $agent->isPlatformOperator())
                <x-operator-smoke-path :smoke-path="$operatorSmokePath" />
            @endif

            <section class="section" aria-labelledby="support-access-heading">
                <div class="section-header">
                    <h2 id="support-access-heading">{{ __('site_settings.access.heading') }}</h2>
                    <span class="lede">
                        @if ($siteHasExplicitSupportAgents)
                            {{ trans_choice('site_settings.access.assigned', count($supportAgentIds), ['count' => \App\Support\ReaderNumber::count(count($supportAgentIds))]) }}
                        @else
                            {{ __('site_settings.access.fallback') }}
                        @endif
                    </span>
                </div>

                @if (! $siteHasExplicitSupportAgents)
                    <div class="notice-copy">
                        <p>{{ __('site_settings.access.fallback_help') }}</p>
                    </div>
                @endif

                @if ($canManageSiteAccess)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.support-agents.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('site_settings.access.columns.assigned') }}</th>
                                        <th scope="col">{{ __('site_settings.access.columns.name') }}</th>
                                        <th scope="col">{{ __('site_settings.access.columns.email') }}</th>
                                        <th scope="col">{{ __('site_settings.access.columns.role') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($accountAgents as $accountAgent)
                                        <tr>
                                            <td>
                                                <input
                                                    id="support_agent_{{ $accountAgent->id }}"
                                                    name="support_agent_ids[]"
                                                    type="checkbox"
                                                    value="{{ $accountAgent->id }}"
                                                    @checked(in_array($accountAgent->id, $selectedSupportAgentIds, true))
                                                >
                                            </td>
                                            <td>
                                                <label for="support_agent_{{ $accountAgent->id }}" lang="">{{ $accountAgent->name }}</label>
                                            </td>
                                            <td lang="">{{ $accountAgent->email }}</td>
                                            <td>{{ __('site_settings.common.roles.'.($accountAgent->account_role?->value ?? 'agent')) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @error('support_agent_ids')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                        @error('support_agent_ids.*')
                            <p class="field-error">{{ $message }}</p>
                        @enderror

                        <p class="field-help">
                            {{ __('site_settings.access.help') }}
                        </p>

                        <button class="button" type="submit">{{ __('site_settings.access.save') }}</button>
                    </form>
                @else
                    @if ($supportAgents->isEmpty())
                        <p class="empty">{{ __('site_settings.access.all_agents') }}</p>
                    @else
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('site_settings.access.columns.name') }}</th>
                                        <th scope="col">{{ __('site_settings.access.columns.email') }}</th>
                                        <th scope="col">{{ __('site_settings.access.columns.role') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($supportAgents as $supportAgent)
                                        <tr>
                                            <td lang="">{{ $supportAgent->name }}</td>
                                            <td lang="">{{ $supportAgent->email }}</td>
                                            <td>{{ __('site_settings.common.roles.'.($supportAgent->account_role?->value ?? 'agent')) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <p class="empty">{{ __('site_settings.access.restricted') }}</p>
                @endif
            </section>

            @if ($canViewSiteActivity)
                <section id="site-access-activity" class="section" aria-labelledby="site-access-activity-heading">
                    <div class="section-header">
                        <h2 id="site-access-activity-heading">{{ __('site_settings.activity.heading') }}</h2>
                        <div class="section-actions">
                            <span class="lede">{{ __('site_settings.activity.shown', ['count' => \App\Support\ReaderNumber::count($siteActivity->count())]) }}</span>
                            @if ($siteActivityAuditUrl)
                                <a class="button secondary" href="{{ $siteActivityAuditUrl }}">{{ __('site_settings.activity.view') }}</a>
                            @endif
                        </div>
                    </div>

                    @if ($siteActivity->isEmpty())
                        <p class="empty">{{ __('site_settings.activity.empty') }}</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($siteActivity as $activity)
                                <article class="timeline-item internal-note">
                                    <div class="timeline-content">
                                        <strong>{{ $activity['label'] }}</strong>
                                        <p class="message-body">{{ $activity['body'] }}</p>
                                        <div class="timeline-meta">
                                            <span @if($activity['actor_is_authored']) lang="" @endif>{{ $activity['actor'] }}</span>
                                            <span lang="">{{ $activity['subject'] }}</span>
                                            @if ($activity['occurred_at'])
                                                <span>{{ $activity['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            <section class="section" aria-labelledby="external-issue-routing-heading">
                <div class="section-header">
                    <h2 id="external-issue-routing-heading">{{ __('site_settings.external.routing.heading') }}</h2>
                    <span class="lede">{{ trans_choice('site_settings.external.routing.mapped', $siteExternalIssueProjects->count(), ['count' => \App\Support\ReaderNumber::count($siteExternalIssueProjects->count())]) }}</span>
                </div>

                @if ($siteExternalIssueProjects->isEmpty())
                    <p class="empty">{{ __('site_settings.external.routing.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('site_settings.external.routing.columns.provider') }}</th>
                                    <th scope="col">{{ __('site_settings.external.routing.columns.project') }}</th>
                                    <th scope="col">{{ __('site_settings.external.routing.columns.capabilities') }}</th>
                                    <th scope="col">{{ __('site_settings.external.routing.columns.handoff') }}</th>
                                    <th scope="col">{{ __('site_settings.external.routing.columns.link') }}</th>
                                    @if ($canManageIntegrations)
                                        <th scope="col">{{ __('site_settings.external.routing.columns.action') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($siteExternalIssueProjects as $externalIssueProject)
                                    @php
                                        $providerParts = $externalIssueProviderParts->get(
                                            $externalIssueProject->providerConnection?->id,
                                            ['label' => __('integrations.providers.external_tracker'), 'language' => null],
                                        );
                                        $handoffKey = ! $externalIssueProject->providerConnection?->is_enabled
                                            ? 'blocked'
                                            : (! $externalIssueProject->hasSupportedIssueCreationProvider()
                                                ? 'unsupported'
                                                : ($externalIssueProject->supportsIssueCreationHandoff() ? 'ready' : 'disabled'));
                                        $handoffState = [
                                            'label' => __('account.external.handoff.'.$handoffKey.'.label'),
                                            'detail' => __('account.external.handoff.'.$handoffKey.'.detail'),
                                            'tone' => $handoffKey === 'blocked' ? 'attention' : ($handoffKey === 'ready' ? 'ready' : 'manual'),
                                        ];
                                        $capabilityFlags = \App\Support\ExternalIssueCapability::flags($externalIssueProject->providerConnection?->capabilities);
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong lang="">{{ $externalIssueProject->providerConnection?->name ?? $providerParts['label'] }}</strong>
                                            <span class="lede" @if ($providerParts['language'] !== null) lang="{{ $providerParts['language'] }}" @endif>{{ $providerParts['label'] }}</span>
                                        </td>
                                        <td>
                                            <strong lang="">{{ $externalIssueProject->project_key }}</strong>
                                            @if ($externalIssueProject->project_name)
                                                <span class="lede" lang="">{{ $externalIssueProject->project_name }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @forelse (collect($capabilityFlags)->filter()->keys() as $capability)
                                                <span>{{ __('integrations.capabilities.labels.'.$capability) }}</span>@if (! $loop->last)<br>@endif
                                            @empty
                                                <span>{{ __('site_settings.external.routing.link_only') }}</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            <span class="readiness-status" data-status="{{ $handoffState['tone'] }}">
                                                {{ $handoffState['label'] }}
                                            </span>
                                            <span class="lede">{{ $handoffState['detail'] }}</span>
                                        </td>
                                        <td>
                                            @if ($externalIssueProject->web_url)
                                                <a class="text-link" href="{{ $externalIssueProject->web_url }}" rel="noopener noreferrer" target="_blank">{{ __('site_settings.external.routing.open_project') }}</a>
                                            @else
                                                <span class="lede">{{ __('site_settings.common.not_set') }}</span>
                                            @endif
                                        </td>
                                        @if ($canManageIntegrations)
                                            <td>
                                                <form method="POST" action="{{ route('dashboard.sites.external-issue-projects.destroy', [$site, $externalIssueProject]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="button secondary" type="submit">{{ __('site_settings.external.routing.remove') }}</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div id="external-issue-health" aria-labelledby="external-issue-health-heading">
                    <div class="section-header">
                        <h2 id="external-issue-health-heading">{{ __('site_settings.external.health_heading') }}</h2>
                        <span class="readiness-status" data-status="{{ $externalIssueHealth['tone'] }}">{{ $externalIssueHealth['label'] }}</span>
                    </div>

                    <div class="meta-grid">
                        @foreach ($externalIssueHealth['status_counts'] as $statusCount)
                            <div class="meta-item">
                                <span class="meta-label">{{ $statusCount['label'] }}</span>
                                <span class="meta-value">{{ $statusCount['value'] }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if ($externalIssueHealth['recent_failures']->isEmpty())
                        <p class="empty">{{ __('site_settings.external.failures.empty') }}</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($externalIssueHealth['recent_failures'] as $failure)
                                <article class="timeline-item internal-note">
                                    <div class="timeline-content">
                                        <strong>{{ __($loop->first ? 'site_settings.external.failures.last_short' : 'site_settings.external.failures.earlier_short') }}</strong>
                                        <p class="message-body"><x-translated-feedback :feedback="$failure['body_feedback']" /></p>
                                        <div class="timeline-meta">
                                            @if ($failure['status'])
                                                <span><x-translated-feedback :feedback="['key' => 'site_settings.external.failures.status', 'parameters' => ['status' => $failure['status']]]" /></span>
                                            @endif
                                            @if ($failure['occurred_at'])
                                                <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                            <span>{{ __('site_settings.external.failures.details_withheld') }}</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="section-header">
                    <strong>{{ __('site_settings.external.routing.connections') }}</strong>
                    <span class="lede">{{ __('site_settings.external.routing.account_owned') }}</span>
                </div>

                <p class="lede">
                    {!! __('site_settings.external.routing.connections_help', ['integrations' => '<a class="text-link" href="'.e(route('dashboard.account.integrations')).'">'.e(__('site_settings.external.routing.integrations')).'</a>']) !!}
                    @unless ($canManageIntegrations)
                        {{ __('site_settings.external.routing.admin_help') }}
                    @endunless
                </p>

                @if ($canManageIntegrations)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.external-issue-projects.store', $site) }}">
                        @csrf

                        <div class="section-header">
                            <strong>{{ __('site_settings.external.routing.map') }}</strong>
                            <span class="lede">{{ __('site_settings.external.routing.site_scoped') }}</span>
                        </div>

                        @if ($externalIssueProviderConnections->isEmpty())
                            <p class="empty">{{ __('site_settings.external.routing.add_connection') }}</p>
                        @else
                            <div class="field">
                                <label for="external_issue_provider_connection_id">{{ __('site_settings.external.routing.connection') }}</label>
                                <select id="external_issue_provider_connection_id" name="external_issue_provider_connection_id">
                                    @foreach ($externalIssueProviderConnections as $connection)
                                        <option value="{{ $connection->id }}" lang="" @selected((int) old('external_issue_provider_connection_id') === $connection->id)>
                                            {{ $connection->name }} - {{ $externalIssueProviderParts->get($connection->id, ['label' => __('integrations.providers.external_tracker')])['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('external_issue_provider_connection_id')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="project_key">{{ __('site_settings.external.routing.project_key') }}</label>
                                <input id="project_key" name="project_key" type="text" value="{{ old('project_key') }}" placeholder="{{ __('site_settings.external.routing.project_key_placeholder') }}" lang="">
                                @error('project_key')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="project_name">{{ __('site_settings.external.routing.project_name') }}</label>
                                <input id="project_name" name="project_name" type="text" value="{{ old('project_name') }}" placeholder="Wayfindr" lang="">
                                @error('project_name')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="web_url">{{ __('site_settings.external.routing.project_url') }}</label>
                                <input id="web_url" name="web_url" type="url" value="{{ old('web_url') }}" placeholder="https://github.com/adamgreenwell/wayfindr" lang="">
                                @error('web_url')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <button class="button" type="submit">{{ __('site_settings.external.routing.submit') }}</button>
                        @endif
                    </form>
                @else
                    <p class="empty">{{ __('site_settings.external.routing.restricted') }}</p>
                @endif
            </section>

            <section class="section" aria-labelledby="data-responsibility-heading">
                <div class="section-header">
                    <h2 id="data-responsibility-heading">{{ __('account.data_responsibility.heading') }}</h2>
                    <span class="lede"><x-translated-feedback :feedback="$dataResponsibility['label']" /></span>
                </div>

                <div class="notice-copy">
                    <p><x-translated-feedback :feedback="$dataResponsibility['message']" /></p>
                    <p><x-translated-feedback :feedback="$dataResponsibility['guidance']" /></p>
                </div>
            </section>

            <section class="section" aria-labelledby="inbound-email-heading">
                <div class="section-header">
                    <div>
                        <h2 id="inbound-email-heading">{{ __('site_settings.inbound.heading') }}</h2>
                        <p class="lede">{{ __('site_settings.inbound.lede') }}</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $site->inbound_address ? 'ready' : 'manual' }}">
                        {{ __($site->inbound_address ? 'site_settings.inbound.receiving' : 'site_settings.inbound.not_receiving') }}
                    </span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.inbound-address.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="inbound_address">{{ __('site_settings.inbound.address') }}</label>
                            <input type="email" id="inbound_address" name="inbound_address" maxlength="255"
                                placeholder="support@example.com" value="{{ old('inbound_address', $site->inbound_address) }}" lang="">
                            <p class="field-hint">
                                {{ __('site_settings.inbound.help') }}
                            </p>
                            @error('inbound_address')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">{{ __('site_settings.inbound.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.inbound.restricted') }}</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="widget-appearance-heading">
                <div class="section-header">
                    <div>
                        <h2 id="widget-appearance-heading">{{ __('site_settings.appearance.heading') }}</h2>
                        <p class="lede">{{ __('site_settings.appearance.lede') }}</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $appearance->accent ? 'ready' : 'manual' }}">
                        {{ __($appearance->accent ? 'site_settings.appearance.branded' : 'site_settings.appearance.default') }}
                    </span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.appearance.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="widget_accent">{{ __('site_settings.appearance.accent') }}</label>
                            <input type="text" id="widget_accent" name="widget_accent" maxlength="9"
                                placeholder="#7C3AED" value="{{ old('widget_accent', $appearance->accent) }}" lang="">
                            <p class="field-hint">
                                {!! __('site_settings.appearance.accent_help', ['site_colour' => '<a href="#site-colour-heading">'.e(__('site_settings.appearance.site_colour')).'</a>']) !!}
                                @if ($appearance->accent)
                                    <x-translated-feedback :feedback="['key' => 'site_settings.appearance.rendered', 'parameters' => ['light' => $appearance->accentLight, 'dark' => $appearance->accentDark]]" />
                                @else
                                    {{ __('site_settings.appearance.empty') }}
                                @endif
                            </p>
                            @error('widget_accent')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="widget_position">{{ __('site_settings.appearance.position') }}</label>
                            <select id="widget_position" name="widget_position">
                                <option value="right" @selected(old('widget_position', $appearance->position) === 'right')>{{ __('site_settings.appearance.right') }}</option>
                                <option value="left" @selected(old('widget_position', $appearance->position) === 'left')>{{ __('site_settings.appearance.left') }}</option>
                            </select>
                            <p class="field-hint">{{ __('site_settings.appearance.position_help') }}</p>
                        </div>

                        <div class="field">
                            <label for="widget_greeting">{{ __('site_settings.appearance.greeting') }}</label>
                            <input type="text" id="widget_greeting" name="widget_greeting" maxlength="120"
                                placeholder="{{ __('site_settings.appearance.greeting_placeholder') }}" value="{{ old('widget_greeting', $appearance->greeting) }}" lang="">
                        </div>

                        <div class="field">
                            <label for="widget_placeholder">{{ __('site_settings.appearance.placeholder') }}</label>
                            <input type="text" id="widget_placeholder" name="widget_placeholder" maxlength="120"
                                placeholder="{{ __('site_settings.appearance.composer_placeholder') }}" value="{{ old('widget_placeholder', $appearance->placeholder) }}" lang="">
                            <p class="field-hint">{{ __('site_settings.appearance.copy_help') }}</p>
                        </div>

                        <button class="button" type="submit">{{ __('site_settings.appearance.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.appearance.restricted') }}</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="support-hours-heading">
                <div class="section-header">
                    <div>
                        <h2 id="support-hours-heading">{{ __('site_settings.hours.heading') }}</h2>
                        <p class="lede">{{ __('site_settings.hours.lede') }}</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $availability->open ? 'ready' : 'manual' }}">
                        @if ($availability->closedUntil)
                            {{ __('site_settings.hours.states.closed_early') }}
                        @elseif ($availability->scheduled)
                            {{ __($availability->open ? 'site_settings.hours.states.open' : 'site_settings.hours.states.away') }}
                        @else
                            {{ __('site_settings.hours.states.always') }}
                        @endif
                    </span>
                </div>

                @if ($canUpdateSite)
                    <div class="desk-closure">
                        @if ($availability->closedUntil)
                            <p class="desk-closure-state">
                                {{ __('site_settings.hours.closed') }}
                                @if ($availability->opensAt)
                                    {{-- Deliberately NOT ReaderClock: this reports what
                                         VISITORS are told, and `opensAt` already carries the
                                         site's own zone. Moving it to the reader's clock would
                                         make the sentence untrue for an agent sitting in a
                                         different one. --}}
                                    {{ __('site_settings.hours.back', [
                                        'time' => $availability->opensAt->format('H:i'),
                                        'date' => $availability->opensAt->translatedFormat('j M'),
                                    ]) }}
                                @else
                                    {{ __('site_settings.hours.no_return') }}
                                @endif
                            </p>

                            <form method="POST" action="{{ route('dashboard.sites.availability.reopen', $site) }}">
                                @csrf
                                @method('DELETE')
                                <button class="button" type="submit">{{ __('site_settings.hours.reopen') }}</button>
                            </form>
                        @else
                            <p class="desk-closure-state">
                                {{ __('site_settings.hours.close_help') }}
                            </p>

                            <form class="desk-closure-actions" method="POST"
                                action="{{ route('dashboard.sites.availability.close', $site) }}">
                                @csrf
                                <button class="button secondary" type="submit" name="closure" value="hour">{{ __('site_settings.hours.hour') }}</button>
                                <button class="button secondary" type="submit" name="closure" value="today">{{ __('site_settings.hours.today') }}</button>
                                <button class="button secondary" type="submit" name="closure" value="tomorrow">{{ __('site_settings.hours.tomorrow') }}</button>
                            </form>
                        @endif

                        @error('closure')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.availability.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="availability_enabled">
                                {{-- Unchecked boxes send nothing, so old() would fall back to the
                                     saved value and silently re-check a box just cleared. --}}
                                <input type="hidden" name="availability_enabled" value="0">
                                <input type="checkbox" id="availability_enabled" name="availability_enabled" value="1"
                                    @checked(old('availability_enabled', $availabilitySettings['enabled'] ?? false))>
                                {{ __('site_settings.hours.enabled') }}
                            </label>
                            <p class="field-help">
                                {{ __('site_settings.hours.enabled_help') }}
                            </p>
                        </div>

                        <div class="field">
                            <label for="availability_timezone">{{ __('site_settings.hours.timezone') }}</label>
                            <select id="availability_timezone" name="availability_timezone">
                                @foreach (DateTimeZone::listIdentifiers() as $identifier)
                                    <option value="{{ $identifier }}" lang=""
                                        @selected(old('availability_timezone', $availabilitySettings['timezone'] ?? config('app.timezone')) === $identifier)>
                                        {{ $identifier }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="field-help">{{ __('site_settings.hours.timezone_help') }}</p>
                            @error('availability_timezone')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr><th>{{ __('site_settings.hours.columns.day') }}</th><th>{{ __('site_settings.hours.columns.open') }}</th><th>{{ __('site_settings.hours.columns.from') }}</th><th>{{ __('site_settings.hours.columns.to') }}</th></tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Support\Sites\SiteAvailability::DAYS as $day)
                                        <tr>
                                            <td>{{ __('site_settings.hours.days.'.$day) }}</td>
                                            <td>
                                                <input type="hidden" name="availability_open[{{ $day }}]" value="0">
                                                <input type="checkbox" name="availability_open[{{ $day }}]" value="1"
                                                    aria-label="{{ __('site_settings.hours.day_open', ['day' => __('site_settings.hours.days.'.$day)]) }}"
                                                    @checked(old('availability_open.'.$day, $availabilityWeekdays[$day]['open']))>
                                            </td>
                                            <td>
                                                <input type="time" name="availability_from[{{ $day }}]"
                                                    aria-label="{{ __('site_settings.hours.day_from', ['day' => __('site_settings.hours.days.'.$day)]) }}"
                                                    value="{{ old('availability_from.'.$day, $availabilityWeekdays[$day]['from']) }}">
                                                @error('availability_from.'.$day)
                                                    <p class="field-error">{{ $message }}</p>
                                                @enderror
                                            </td>
                                            <td>
                                                <input type="time" name="availability_to[{{ $day }}]"
                                                    aria-label="{{ __('site_settings.hours.day_to', ['day' => __('site_settings.hours.days.'.$day)]) }}"
                                                    value="{{ old('availability_to.'.$day, $availabilityWeekdays[$day]['to']) }}">
                                                @error('availability_to.'.$day)
                                                    <p class="field-error">{{ $message }}</p>
                                                @enderror
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="field">
                            <label for="availability_away_message">{{ __('site_settings.hours.away_message') }}</label>
                            <textarea id="availability_away_message" name="availability_away_message" rows="3"
                                placeholder="{{ __('site_settings.hours.away_placeholder') }}" lang="">{{ old('availability_away_message', $availabilitySettings['away_message'] ?? '') }}</textarea>
                            <p class="field-help">
                                {{ __('site_settings.hours.away_help') }}
                            </p>
                            @error('availability_away_message')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">{{ __('site_settings.hours.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.hours.restricted') }}</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="widget-language-heading">
                <div class="section-header">
                    <div>
                        <h2 id="widget-language-heading">{{ __('site_settings.language.heading') }}</h2>
                        <p class="lede">{{ __('site_settings.language.lede') }}</p>
                    </div>
                    @if ($widgetLocale)
                        <span class="lede" lang="">{{ $widgetLanguages[$widgetLocale] ?? $widgetLocale }}</span>
                    @else
                        <span class="lede">{{ __('site_settings.language.following') }}</span>
                    @endif
                </div>

                <div class="notice-copy">
                    <p>{{ __('site_settings.language.body') }}</p>
                    <p>{{ __('site_settings.language.authored') }}</p>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.language.update', $site) }}">
                        @csrf
                        @method('PUT')
                        <div class="meta-grid">
                            <div class="meta-item">
                                <label class="meta-label" for="widget_locale">{{ __('site_settings.language.default') }}</label>
                                <select id="widget_locale" name="widget_locale">
                                    <option value="" @selected(old('widget_locale', $widgetLocale) === null || old('widget_locale', $widgetLocale) === '')>{{ __('site_settings.language.browser') }}</option>
                                    @foreach ($widgetLanguages as $code => $label)
                                        <option value="{{ $code }}" lang="" @selected(old('widget_locale', $widgetLocale) === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">{{ __('site_settings.language.label') }}</span>
                                <button class="button" type="submit">{{ __('site_settings.language.save') }}</button>
                            </div>
                        </div>
                    </form>
                @else
                    <p class="lede">{{ __('site_settings.language.restricted') }}</p>
                @endif

                <div class="notice-copy">
                    <p>{!! __('site_settings.language.sdk', ['attribute' => '<code lang="">data-wayfindr-locale="de"</code>']) !!}</p>
                </div>
            </section>

            <section class="section" aria-labelledby="rating-prompt-heading">
                <div class="section-header">
                    <div>
                        <h2 id="rating-prompt-heading">{{ __('site_settings.rating.heading') }}</h2>
                        <p class="lede">{{ __('site_settings.rating.lede') }}</p>
                    </div>
                    <span class="lede">{{ __($ratingPrompt->enabled ? 'site_settings.rating.asking' : 'site_settings.rating.not_asking') }}</span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.rating.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="rating_enabled">
                                {{-- Unchecked boxes send nothing, so old() would fall back to the
                                     saved value and silently re-check a box just cleared. --}}
                                <input type="hidden" name="rating_enabled" value="0">
                                <input type="checkbox" id="rating_enabled" name="rating_enabled" value="1"
                                    @checked(old('rating_enabled', $ratingPrompt->enabled))>
                                {{ __('site_settings.rating.enabled') }}
                            </label>
                            <p class="field-help">
                                {{ __('site_settings.rating.enabled_help') }}
                            </p>
                        </div>

                        <div class="field">
                            <label for="rating_intro">{{ __('site_settings.rating.intro') }}</label>
                            <input type="text" id="rating_intro" name="rating_intro" maxlength="160"
                                placeholder="{{ __('site_settings.rating.intro_placeholder') }}" value="{{ old('rating_intro', $ratingPrompt->intro) }}" lang="">
                            <p class="field-help">{{ __('site_settings.rating.intro_help') }}</p>
                            @error('rating_intro')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">{{ __('site_settings.rating.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.rating.restricted') }}</p>
                    </div>
                @endif

                <div class="notice-copy">
                    <p>
                        {{ __('site_settings.rating.once') }}
                    </p>
                    <p>
                        {!! __('site_settings.rating.comments', ['data' => '<a class="text-link" href="#data-responsibility-heading">'.e(__('account.data_responsibility.heading')).'</a>']) !!}
                    </p>
                </div>
            </section>

            <section class="section" aria-labelledby="visitor-intake-heading">
                <div class="section-header">
                    <div>
                        <h2 id="visitor-intake-heading">{{ __('site_settings.intake.heading') }}</h2>
                        <p class="lede">{{ __('site_settings.intake.lede') }}</p>
                    </div>
                    <span class="lede">{{ __($intake->asks() ? 'site_settings.intake.asking' : 'site_settings.intake.nothing') }}</span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.intake.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr><th>{{ __('site_settings.intake.columns.field') }}</th><th>{{ __('site_settings.intake.columns.off') }}</th><th>{{ __('site_settings.intake.columns.optional') }}</th><th>{{ __('site_settings.intake.columns.required') }}</th></tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Support\Sites\SiteIntake::FIELDS as $field)
                                        <tr>
                                            <td>{{ __('site_settings.intake.fields.'.$field) }}</td>
                                            @foreach ([\App\Support\Sites\SiteIntake::OFF, \App\Support\Sites\SiteIntake::OPTIONAL, \App\Support\Sites\SiteIntake::REQUIRED] as $mode)
                                                <td>
                                                    <input type="radio" name="intake_fields[{{ $field }}]" value="{{ $mode }}"
                                                        aria-label="{{ __('site_settings.intake.choice', ['field' => __('site_settings.intake.fields.'.$field), 'mode' => __('site_settings.intake.modes.'.$mode)]) }}"
                                                        @checked(old('intake_fields.'.$field, $intake->fields[$field]) === $mode)>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="field-help">
                            {{ __('site_settings.intake.known_help') }}
                        </p>
                        <p class="field-help">
                            {{ __('site_settings.intake.identity_help') }}
                        </p>

                        <div class="field">
                            <label for="intake_intro">{{ __('site_settings.intake.intro') }}</label>
                            <textarea id="intake_intro" name="intake_intro" rows="2"
                                placeholder="{{ __('site_settings.intake.intro_placeholder') }}" lang="">{{ old('intake_intro', $intake->intro) }}</textarea>
                            <p class="field-help">{{ __('site_settings.intake.intro_help') }}</p>
                            @error('intake_intro')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">{{ __('site_settings.intake.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.intake.restricted') }}</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="presence-settings-heading">
                <div class="section-header">
                    <h2 id="presence-settings-heading">{{ __('site_settings.presence.heading') }}</h2>
                    <span class="lede">{{ __($presenceEnabled ? 'site_settings.common.on' : 'site_settings.common.off') }}</span>
                </div>

                @if ($canUpdatePrivacy)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.presence.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field field-check">
                            <label for="presence_enabled">
                                <input type="checkbox" id="presence_enabled" name="presence_enabled" value="1" @checked(old('presence_enabled', $presenceEnabled))>
                                {{ __('site_settings.presence.enabled') }}
                            </label>
                            @error('presence_enabled')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="field-help">
                            {{-- Echoes rather than inline conditionals. A Blade directive
                                 written flush against a word character is not compiled at
                                 all, so this paragraph shipped to stage with two directives
                                 as literal text in it -- and the one that DID compile then
                                 wrapped the wrong span, because its partner had been left
                                 behind. An echo has no such adjacency rule, and reads no
                                 worse here. --}}
                            {{ __('site_settings.presence.summary', [
                                'seconds' => \App\Support\ReaderNumber::count($presenceEvery),
                                'pages' => $presencePageUrls ? __('site_settings.presence.pages') : '',
                                'storage' => $presencePageUrls ? __('site_settings.presence.storage') : '',
                                'retention' => trans_choice('site_settings.presence.retention', $presenceRetentionDays, ['count' => \App\Support\ReaderNumber::count($presenceRetentionDays)]),
                            ]) }}
                        </p>

                        <div class="field field-check">
                            <label for="presence_page_urls">
                                <input type="checkbox" id="presence_page_urls" name="presence_page_urls" value="1" @checked(old('presence_page_urls', $presencePageUrls))>
                                {{ __('site_settings.presence.include_page') }}
                            </label>
                        </div>

                        <p class="field-help">
                            {{ __('site_settings.presence.page_help') }}
                        </p>

                        <p class="field-help">
                            {{ __('site_settings.presence.off_help') }}
                        </p>

                        <button class="button" type="submit">{{ __('site_settings.presence.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.presence.restricted') }}</p>
                        <p>{{ __($presenceEnabled ? 'site_settings.presence.restricted_on' : 'site_settings.presence.restricted_off') }}</p>
                    </div>
                @endif

                {{-- Outside the permission branch above, because the board and
                     this link answer to different permissions. Changing the
                     SETTING is an owner-and-admin decision; using the board is
                     open to any agent who can view the site, which is what the
                     route and the broadcast channel both authorise. Keeping the
                     only link inside the admin half left the agents it was
                     built for with no way to reach it. --}}
                @if ($presenceEnabled)
                    <p class="field-help">
                        {!! __('site_settings.presence.board_help', ['board' => '<a href="'.e(route('dashboard.sites.live', $site)).'">'.e(__('site_settings.presence.board')).'</a>']) !!}
                    </p>
                @endif
            </section>

            <section class="section" aria-labelledby="privacy-settings-heading">
                <div class="section-header">
                    <h2 id="privacy-settings-heading">{{ __('site_settings.privacy.heading') }}</h2>
                    <span class="lede">{{ trans_choice('site_settings.privacy.configured', count($maskSelectors), ['count' => \App\Support\ReaderNumber::count(count($maskSelectors))]) }}</span>
                </div>

                @if ($canUpdatePrivacy)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="mask_selectors">{{ __('site_settings.privacy.selectors') }}</label>
                            <textarea id="mask_selectors" name="mask_selectors" spellcheck="false" lang="">{{ old('mask_selectors', implode("\n", $maskSelectors)) }}</textarea>
                            @error('mask_selectors')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="field-help">
                            {{ __('site_settings.privacy.selectors_help') }}
                        </p>

                        <div class="field">
                            <label for="mask_terms">{{ __('site_settings.privacy.terms') }}</label>
                            <textarea id="mask_terms" name="mask_terms" spellcheck="false" lang="">{{ old('mask_terms', implode("\n", $maskTerms)) }}</textarea>
                            @error('mask_terms')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="field-help">
                            {!! __('site_settings.privacy.terms_help', ['password' => '<code lang="">contraseña</code>', 'number' => '<code lang="">NHS number</code>']) !!}
                        </p>

                        <div class="notice-list">
                            <p>{!! __('site_settings.privacy.force', ['mask' => '<code lang="">data-wayfindr-mask</code>', 'private' => '<code lang="">data-wayfindr-private</code>']) !!}</p>
                            <p>{!! __('site_settings.privacy.allow', ['allow' => '<code lang="">data-wayfindr-allow</code>']) !!}</p>
                        </div>

                        <button class="button" type="submit">{{ __('site_settings.privacy.save') }}</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>{{ __('site_settings.privacy.restricted') }}</p>
                    </div>

                    @if (count($maskSelectors) === 0)
                        <p class="empty">{{ __('site_settings.privacy.empty_selectors') }}</p>
                    @else
                        <div class="notice-list">
                            @foreach ($maskSelectors as $maskSelector)
                                <p><code lang="">{{ $maskSelector }}</code></p>
                            @endforeach
                        </div>
                    @endif

                    @if (count($maskTerms) === 0)
                        <p class="empty">{{ __('site_settings.privacy.empty_terms') }}</p>
                    @else
                        <div class="notice-list">
                            @foreach ($maskTerms as $maskTerm)
                                <p><code lang="">{{ $maskTerm }}</code></p>
                            @endforeach
                        </div>
                    @endif

                    <div class="notice-list">
                        <p>{!! __('site_settings.privacy.force', ['mask' => '<code lang="">data-wayfindr-mask</code>', 'private' => '<code lang="">data-wayfindr-private</code>']) !!}</p>
                        <p>{!! __('site_settings.privacy.allow', ['allow' => '<code lang="">data-wayfindr-allow</code>']) !!}</p>
                    </div>
                @endif
            </section>

            @can('archive', $site)
                @unless ($site->isArchived())
                    <section class="section" aria-labelledby="retire-site-heading">
                        <div class="section-header">
                            <h2 id="retire-site-heading">{{ __('site_settings.retire.heading') }}</h2>
                            <span class="lede">{{ __('site_settings.retire.lede') }}</span>
                        </div>

                        <p class="lede">
                            {{ __('site_settings.retire.body') }}
                        </p>

                        <p class="field-help">
                            {{ __('site_settings.retire.warning') }}
                        </p>

                        <form method="POST" action="{{ route('dashboard.sites.archive', $site) }}">
                            @csrf
                            <button class="button secondary" type="submit">{{ __('site_settings.retire.archive') }}</button>
                        </form>
                    </section>
                @endunless
            @endcan

            @can('purge', $site)
                @if ($site->isArchived())
                    @php
                        $purgeConversationCount = $site->conversations()->count();
                        $purgeTicketCount = $site->tickets()->count();
                        $purgeVisitorCount = $site->visitors()->count();
                    @endphp

                    <section class="section" aria-labelledby="purge-site-heading">
                        <div class="section-header">
                            <h2 id="purge-site-heading">{{ __('site_settings.purge.heading') }}</h2>
                            <span class="readiness-status" data-status="attention">{{ __('site_settings.purge.irreversible') }}</span>
                        </div>

                        <p class="lede">
                            {{ __('site_settings.purge.body') }}
                        </p>

                        <div class="notice-list">
                            <p>{{ trans_choice('site_settings.purge.conversations', $purgeConversationCount, ['count' => \App\Support\ReaderNumber::count($purgeConversationCount)]) }}</p>
                            <p>{{ trans_choice('site_settings.purge.tickets', $purgeTicketCount, ['count' => \App\Support\ReaderNumber::count($purgeTicketCount)]) }}</p>
                            <p>{{ trans_choice('site_settings.purge.visitors', $purgeVisitorCount, ['count' => \App\Support\ReaderNumber::count($purgeVisitorCount)]) }}</p>
                            <p>{{ __('site_settings.purge.audit') }}</p>
                        </div>

                        <form class="section-form" method="POST" action="{{ route('dashboard.sites.purge', $site) }}">
                            @csrf
                            @method('DELETE')

                            <div class="field">
                                <label for="confirm_name"><x-translated-feedback :feedback="['key' => 'site_settings.purge.confirm', 'parameters' => ['site' => $site->name]]" /></label>
                                <input id="confirm_name" name="confirm_name" type="text" autocomplete="off" spellcheck="false" lang="" required>
                                @error('confirm_name')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <button class="button danger" type="submit">{{ __('site_settings.purge.submit') }}</button>
                        </form>
                    </section>
                @endif
            @endcan
</x-layouts.app>
