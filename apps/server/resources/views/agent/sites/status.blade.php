<x-layouts.app :title="__('site_settings.status.title')" :agent="$agent" :account="$account">
            <x-page-header :back-href="route('dashboard.sites.show', $site)" :back-label="__('site_settings.status.back')">
                <x-slot:titleContent><span lang="">{{ $site->name }}</span></x-slot:titleContent>
                <x-slot:subtitleContent>{{ __('site_settings.status.subtitle') }}</x-slot:subtitleContent>
            </x-page-header>

{{-- Read-only, all of it. Seven blocks that used to sit among the twenty-six
     sections of `sites/show`, where a reader configuring the widget scrolled
     past them and a reader diagnosing a problem scrolled past the widget
     settings to reach them (#985).

     Ordered by the question somebody actually arrives with: what needs my
     attention, is support covered, is the ticket path wired, did the install
     land, who changed what, and where is everything. --}}

@php
    $latestVisitor = $site->latestVisitor;
    $lastSeenAt = $latestVisitor?->last_seen_at;
    $lastPageUrl = $canViewSupportWork
        ? data_get($latestVisitor?->metadata, 'last_page_url')
        : null;
    $installAttentionSiteUrl = $site->domain ? 'https://'.$site->domain : null;
    $installAttentionGuidance = [
        'key' => $lastSeenAt ? 'site_settings.setup.stale' : 'site_settings.setup.not_installed',
        ...($site->domain
            ? ['parameters' => ['target' => $site->domain]]
            : ['localized_parameters' => ['target' => __('site_settings.setup.site_fallback')]]),
    ];
    $installVerificationRefreshUrl = route('dashboard.sites.status', [
        'site' => $site,
        'verify' => now()->timestamp,
    ]).'#install-verification';
    $installSnippetUrl = route('dashboard.sites.show', $site).'#install-snippet';
@endphp

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
                            <a class="button secondary" href="{{ $installSnippetUrl }}">{{ __('site_settings.setup.snippet') }}</a>
                            <a class="button" href="{{ $installVerificationRefreshUrl }}">{{ __('site_settings.setup.verify') }}</a>
                        </div>
                    </div>
                </section>
            @endif

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

            @if ($canViewSupportWork)
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
            @endif

            @if ($canManageTickets)
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
            @endif

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

                    @if ($canViewSupportWork)
                        @if ($lastPageUrl)
                            <p><strong>{{ __('site_settings.verification.last_page') }}</strong>: <span lang="">{{ $lastPageUrl }}</span></p>
                        @else
                            <p><strong>{{ __('site_settings.verification.last_page') }}</strong>: {{ __('site_settings.verification.not_reported') }}</p>
                        @endif
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
</x-layouts.app>
