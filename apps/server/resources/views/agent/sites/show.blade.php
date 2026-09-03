<x-layouts.app title="Site Settings" :agent="$agent" :account="$account">
            <x-page-header :title="$site->name" :subtitle="'Settings for '.($site->domain ?? 'an unconfigured domain')" :back-href="route('dashboard.sites.index')" back-label="Back to sites" />

            @if (session('status'))
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @if ($site->isArchived())
                <section class="section" aria-labelledby="site-archived-heading">
                    <div class="section-header">
                        <h2 id="site-archived-heading">This site is archived</h2>
                        <span class="readiness-status">Archived {{ $site->archived_at->diffForHumans() }}</span>
                    </div>

                    <p class="lede">
                        The widget stopped serving this site when it was archived, so the install and readiness panels below describe a widget that is no longer answering. Nothing has been deleted &mdash; every conversation, ticket and audit record is intact, and restoring the site puts it straight back into service.
                    </p>

                    @can('archive', $site)
                        <form method="POST" action="{{ route('dashboard.sites.unarchive', $site) }}">
                            @csrf
                            <button class="button" type="submit">Restore this site</button>
                        </form>
                    @endcan
                </section>
            @endif

            @php
                $latestVisitor = $site->latestVisitor;
                $lastSeenAt = $latestVisitor?->last_seen_at;
                $lastPageUrl = data_get($latestVisitor?->metadata, 'last_page_url');
                $installHealth = \App\Support\SiteInstallHealth::fromVisitor($latestVisitor);
                $installHostDiagnostic = \App\Support\SiteInstallHealth::hostDiagnostic($latestVisitor, $site->domain);
                $installAttentionTarget = $site->domain ?? 'the site';
                $installAttentionSiteUrl = $site->domain ? 'https://'.$site->domain : null;
                $installAttentionGuidance = $installHealth['label'] === 'Not installed'
                    ? "Finish the widget install by copying the snippet below, loading {$installAttentionTarget}, then using Verify again."
                    : "Check whether the widget still loads on {$installAttentionTarget}. If it does, use Verify again. If it does not, revisit the snippet.";
                $installVerificationRefreshUrl = route('dashboard.sites.show', [
                    'site' => $site,
                    'verify' => now()->timestamp,
                ]).'#install-verification';
                $installVerification = match (true) {
                    ! $lastSeenAt => [
                        'status' => 'Not seen yet',
                        'tone' => 'attention',
                        'message' => 'Wayfindr has not seen this widget check in yet.',
                        'guidance' => 'Copy the snippet, load the site, then refresh this page.',
                    ],
                    $lastSeenAt->greaterThanOrEqualTo(now()->subMinutes(30)) => [
                        'status' => 'Seen '.$lastSeenAt->diffForHumans(),
                        'tone' => 'ready',
                        'message' => 'The widget has checked in recently.',
                        'guidance' => 'Send a test message from the widget if you want to confirm the full support loop.',
                    ],
                    default => [
                        'status' => 'Last seen '.$lastSeenAt->diffForHumans(),
                        'tone' => 'manual',
                        'message' => 'Wayfindr has seen this widget before, but not recently.',
                        'guidance' => 'Visit the site and refresh this page if it should still be active.',
                    ],
                };
                $selectedSupportAgentIds = collect(old('support_agent_ids', $supportAgentIds))
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $selectedCapabilities = collect(old('capabilities', ['create_issue']))
                    ->filter()
                    ->map(fn ($capability) => (string) $capability)
                    ->all();
                $siteMapSections = [
                    ['label' => 'Support readiness', 'href' => '#site-support-readiness-heading'],
                    ['label' => 'Support load', 'href' => '#site-support-load-heading'],
                    ['label' => 'External issue readiness', 'href' => '#site-external-issue-readiness-heading'],
                ];

                if ($installHealth['needs_attention']) {
                    $siteMapSections[] = ['label' => 'Setup attention', 'href' => '#setup-attention-heading'];
                }

                $siteMapSections[] = ['label' => 'Site', 'href' => '#site-context-heading'];
                $siteMapSections[] = ['label' => 'Install verification', 'href' => '#install-verification-heading'];
                $siteMapSections[] = ['label' => 'Install snippet', 'href' => '#install-snippet-heading'];
                $siteMapSections[] = ['label' => 'Support access', 'href' => '#support-access-heading'];

                if ($canViewSiteActivity) {
                    $siteMapSections[] = ['label' => 'Site access activity', 'href' => '#site-access-activity-heading'];
                }

                $siteMapSections[] = ['label' => 'External issue routing', 'href' => '#external-issue-routing-heading'];
                $siteMapSections[] = ['label' => 'Asking how it went', 'href' => '#rating-prompt-heading'];
                $siteMapSections[] = ['label' => 'Data responsibility', 'href' => '#data-responsibility-heading'];
                $siteMapSections[] = ['label' => 'Live visitor presence', 'href' => '#presence-settings-heading'];
                $siteMapSections[] = ['label' => 'Mask selectors', 'href' => '#privacy-settings-heading'];
            @endphp

            <section class="section" aria-labelledby="site-map-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-map-heading">Site map</h2>
                    </div>
                    <span class="lede">{{ count($siteMapSections) }} sections</span>
                </div>

                <div class="filter-summary" aria-label="Site detail sections">
                    <div>
                        <strong>Jump to</strong>
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
                        <h2 id="site-support-readiness-heading">Support readiness</h2>
                    </div>
                    <a class="button secondary" href="{{ route('dashboard.sites.tester', $site) }}">Open tester</a>
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
                                    {{ ucfirst($readinessItem['tone']) }}
                                </span>
                            </div>
                            <p class="readiness-action">
                                <a class="text-link" href="{{ $readinessItem['href'] }}">Review {{ strtolower($readinessItem['label']) }}</a>
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="site-support-load-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-support-load-heading">Support load</h2>
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
                        <h2 id="site-external-issue-readiness-heading">External issue readiness</h2>
                    </div>
                    <span class="readiness-status" data-status="{{ $externalIssueHealth['tone'] }}">{{ $externalIssueHealth['label'] }}</span>
                </div>

                <div class="notice-copy notice-copy-bordered">
                    <p>{{ $externalIssueHealth['detail'] }}</p>
                    <p><a class="text-link" href="#external-issue-routing-heading">Review routing</a></p>
                </div>

                <div class="meta-grid">
                    @foreach ($externalIssueHealth['metrics'] as $metric)
                        <div class="meta-item">
                            <span class="meta-label">{{ $metric['label'] }}</span>
                            <span class="meta-value">{{ $metric['value'] }}</span>
                            <span class="readiness-status" data-status="{{ $metric['tone'] }}">{{ ucfirst($metric['tone']) }}</span>
                            @if (! empty($metric['href']) && ! empty($metric['action']))
                                <p class="readiness-action">
                                    <a class="text-link" href="{{ $metric['href'] }}">{{ $metric['action'] }}</a>
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($externalIssueHealth['recent_failures']->isEmpty())
                    <p class="empty">No recent external sync failures for this site.</p>
                @else
                    <div class="timeline-list">
                        @foreach ($externalIssueHealth['recent_failures'] as $failure)
                            <article class="timeline-item internal-note">
                                <div class="timeline-content">
                                    <strong>{{ $loop->first ? 'Last external sync failure' : 'Earlier external sync failure' }}</strong>
                                    <p class="message-body">{{ $failure['provider'] }} could not sync {{ $failure['project_key'] }}.</p>
                                    <div class="timeline-meta">
                                        @if ($failure['status'])
                                            <span>{{ $failure['status'] }}</span>
                                        @endif
                                        @if ($failure['occurred_at'])
                                            <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                        @endif
                                        <span>Provider details withheld</span>
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
                        <h2 id="setup-attention-heading">Setup attention</h2>
                        <span class="readiness-status" data-status="{{ $installHealth['tone'] }}">{{ $installHealth['label'] }}</span>
                    </div>

                    <div class="notice-copy">
                        <p><strong>{{ $installVerification['message'] }}</strong></p>
                        <p>{{ $installAttentionGuidance }}</p>
                        <div class="notice-actions">
                            @if ($installAttentionSiteUrl)
                                <a class="button secondary" href="{{ $installAttentionSiteUrl }}" rel="noopener noreferrer" target="_blank">Open site</a>
                            @endif
                            <a class="button secondary" href="#install-snippet">Jump to snippet</a>
                            <a class="button" href="{{ $installVerificationRefreshUrl }}">Verify again</a>
                        </div>
                    </div>
                </section>
            @endif

            <section class="section" aria-labelledby="site-context-heading">
                <div class="section-header">
                    <h2 id="site-context-heading">Site</h2>
                    <span class="lede">Widget install target</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Name</span>
                        <span class="meta-value">{{ $site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Domain</span>
                        <span class="meta-value">{{ $site->domain ?? 'Not set' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Public key</span>
                        <span class="meta-value">{{ $site->public_key }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Public config</span>
                        <span class="meta-value">Mask selectors only</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Latest check-in</span>
                        <span class="meta-value">
                            @if ($latestVisitor?->last_seen_at)
                                Seen {{ $latestVisitor->last_seen_at->diffForHumans() }}
                            @else
                                Not seen yet
                            @endif
                        </span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Last page</span>
                        <span class="meta-value">{{ $lastPageUrl ?: 'Not reported' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Verification lab</span>
                        <a class="text-link" href="{{ route('dashboard.sites.tester', $site) }}">Open tester</a>
                    </div>
                </div>

                @can('update', $site)
                    <x-details-disclosure summary="Edit name and domain">
                        <form class="section-form" method="POST" action="{{ route('dashboard.sites.details.update', $site) }}">
                            @csrf
                            @method('PUT')

                            <div class="field">
                                <label for="site_name">Name</label>
                                <input id="site_name" name="name" type="text" value="{{ old('name', $site->name) }}" maxlength="255" required>
                                @error('name')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="site_domain">Domain</label>
                                <input id="site_domain" name="domain" type="text" value="{{ old('domain', $site->domain) }}" maxlength="255" placeholder="support.example.com" autocomplete="off">
                                @error('domain')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <fieldset class="field">
                                <legend>Colour</legend>
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
                                                {{ $option->label() }}
                                            </label>
                                        </span>
                                    @endforeach
                                </div>
                                @error('color')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                                <p class="field-help">
                                    How this site is recognised across the queues, in conversation transcripts, and on the widget your visitors see. Pick a different colour for each site so an agent covering several can tell them apart without reading.
                                </p>
                            </fieldset>

                            <p class="field-help">
                                Name and domain are labels. The widget identifies this site by its public key, so changing either is safe and will not interrupt a live install &mdash; the domain is used for display and for the install checks on this page.
                            </p>

                            <button class="button" type="submit">Save site details</button>
                        </form>
                    </x-details-disclosure>
                @endcan
            </section>

            <section id="install-verification" class="section" aria-labelledby="install-verification-heading">
                <div class="section-header">
                    <h2 id="install-verification-heading">Install verification</h2>
                    <div class="section-actions">
                        <a class="text-link" href="{{ $installVerificationRefreshUrl }}">Verify again</a>
                        <span class="readiness-status" data-status="{{ $installVerification['tone'] }}">{{ $installVerification['status'] }}</span>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>{{ $installVerification['message'] }}</p>
                    <p>{{ $installVerification['guidance'] }}</p>

                    @if ($lastPageUrl)
                        <p><strong>Last verified page</strong>: {{ $lastPageUrl }}</p>
                    @else
                        <p><strong>Last verified page</strong>: Not reported yet.</p>
                    @endif
                </div>

                <div class="meta-grid realtime-grid">
                    <div class="meta-item">
                        <span class="meta-label">Host check</span>
                        <span class="readiness-status" data-status="{{ $installHostDiagnostic['tone'] }}" data-install-host-status>{{ $installHostDiagnostic['label'] }}</span>
                        <span class="lede" data-install-host-detail>{{ $installHostDiagnostic['detail'] }}</span>
                    </div>
                </div>
            </section>

            <section id="install-snippet" class="section" aria-labelledby="install-snippet-heading">
                <div class="section-header">
                    <h2 id="install-snippet-heading">Install snippet</h2>
                    <div class="section-actions">
                        @if ($agent->isPlatformOperator())
                            <a class="text-link" href="{{ route('operator.dashboard') }}">Open operator console</a>
                        @endif
                        <span class="lede">Copy-ready widget script</span>
                    </div>
                </div>

                <div class="notice-copy">
                    <p>
                        @if ($site->domain)
                            Use this snippet on {{ $site->domain }} to load Wayfindr.
                        @else
                            Use this snippet on the site where Wayfindr should appear.
                        @endif
                    </p>
                    <p>Paste this before the closing <code>&lt;/body&gt;</code> tag, then visit the site and send a test message from the widget.</p>

                    <div class="notice-list" aria-label="Next steps">
                        <p><strong>Next steps</strong></p>
                        <p>Copy this snippet into {{ $site->domain ?? 'your site' }}.</p>
                        <p>Use the tester when you want to confirm the widget loop without changing a public page.</p>
                        <p>Visit the site and send a test message from the widget.</p>
                        @if ($agent->isPlatformOperator())
                            <p>Open the operator console to review instance health, release identity, and self-hosting docs.</p>
                        @endif
                        <p>Review readiness if queues, scheduler, storage, or realtime still need attention.</p>
                    </div>
                </div>

                <pre class="code-block"><code>{{ $widgetInstallSnippet }}</code></pre>
                <div class="notice-actions">
                    <a class="button secondary" href="{{ route('dashboard.sites.tester', $site) }}">Open tester</a>
                </div>
            </section>

            @if ($agent->isAdmin() || $agent->isPlatformOperator())
                <x-operator-smoke-path :smoke-path="$operatorSmokePath" />
            @endif

            <section class="section" aria-labelledby="support-access-heading">
                <div class="section-header">
                    <h2 id="support-access-heading">Support access</h2>
                    <span class="lede">
                        @if ($siteHasExplicitSupportAgents)
                            {{ count($supportAgentIds) }} assigned
                        @else
                            Account-wide fallback
                        @endif
                    </span>
                </div>

                @if (! $siteHasExplicitSupportAgents)
                    <div class="notice-copy">
                        <p>No support agents are assigned yet, so all account agents can support this site. Saving assignments will switch this site to explicit access.</p>
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
                                        <th scope="col">Assigned</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Role</th>
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
                                                <label for="support_agent_{{ $accountAgent->id }}">{{ $accountAgent->name }}</label>
                                            </td>
                                            <td>{{ $accountAgent->email }}</td>
                                            <td>{{ ucfirst($accountAgent->account_role?->value ?? 'agent') }}</td>
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
                            Choose at least one support agent and keep at least one owner or admin assigned. Empty assignments are blocked here so site access does not accidentally reopen to the whole account.
                        </p>

                        <button class="button" type="submit">Save site access</button>
                    </form>
                @else
                    @if ($supportAgents->isEmpty())
                        <p class="empty">All account agents can support this site until an owner or admin configures explicit access.</p>
                    @else
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Name</th>
                                        <th scope="col">Email</th>
                                        <th scope="col">Role</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($supportAgents as $supportAgent)
                                        <tr>
                                            <td>{{ $supportAgent->name }}</td>
                                            <td>{{ $supportAgent->email }}</td>
                                            <td>{{ ucfirst($supportAgent->account_role?->value ?? 'agent') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <p class="empty">Account owners and admins manage site support access.</p>
                @endif
            </section>

            @if ($canViewSiteActivity)
                <section id="site-access-activity" class="section" aria-labelledby="site-access-activity-heading">
                    <div class="section-header">
                        <h2 id="site-access-activity-heading">Recent site access activity</h2>
                        <div class="section-actions">
                            <span class="lede">{{ $siteActivity->count() }} shown</span>
                            @if ($siteActivityAuditUrl)
                                <a class="button secondary" href="{{ $siteActivityAuditUrl }}">View full audit log</a>
                            @endif
                        </div>
                    </div>

                    @if ($siteActivity->isEmpty())
                        <p class="empty">No site access changes have been recorded yet.</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($siteActivity as $activity)
                                <article class="timeline-item internal-note">
                                    <div class="timeline-content">
                                        <strong>{{ $activity['label'] }}</strong>
                                        <p class="message-body">{{ $activity['body'] }}</p>
                                        <div class="timeline-meta">
                                            <span>{{ $activity['actor'] }}</span>
                                            <span>{{ $activity['subject'] }}</span>
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
                    <h2 id="external-issue-routing-heading">External issue routing</h2>
                    <span class="lede">{{ $siteExternalIssueProjects->count() }} mapped</span>
                </div>

                @if ($siteExternalIssueProjects->isEmpty())
                    <p class="empty">No external issue projects are mapped to this site yet.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Provider</th>
                                    <th scope="col">Project</th>
                                    <th scope="col">Capabilities</th>
                                    <th scope="col">External issue handoff</th>
                                    <th scope="col">Link</th>
                                    @if ($canManageIntegrations)
                                        <th scope="col">Action</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($siteExternalIssueProjects as $externalIssueProject)
                                    @php
                                        $handoffState = $externalIssueProject->issueCreationHandoffState();
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $externalIssueProject->providerConnection?->name ?? $externalIssueProject->providerLabel() }}</strong>
                                            <span class="lede">{{ $externalIssueProject->providerLabel() }}</span>
                                        </td>
                                        <td>
                                            <strong>{{ $externalIssueProject->project_key }}</strong>
                                            @if ($externalIssueProject->project_name)
                                                <span class="lede">{{ $externalIssueProject->project_name }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @forelse ($externalIssueProject->capabilityLabels() as $capabilityLabel)
                                                <span>{{ $capabilityLabel }}</span>@if (! $loop->last)<br>@endif
                                            @empty
                                                <span>Link only</span>
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
                                                <a class="text-link" href="{{ $externalIssueProject->web_url }}" rel="noopener noreferrer" target="_blank">Open project</a>
                                            @else
                                                <span class="lede">Not set</span>
                                            @endif
                                        </td>
                                        @if ($canManageIntegrations)
                                            <td>
                                                <form method="POST" action="{{ route('dashboard.sites.external-issue-projects.destroy', [$site, $externalIssueProject]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="button secondary" type="submit">Remove</button>
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
                        <h2 id="external-issue-health-heading">External issue health</h2>
                        <span class="readiness-status" data-status="{{ $externalIssueHealth['tone'] }}">{{ $externalIssueHealth['label'] }}</span>
                    </div>

                    <div class="meta-grid">
                        @foreach ($externalIssueHealth['status_counts'] as $statusCount)
                            <div class="meta-item">
                                <span class="meta-label">{{ $statusCount['label'] }}</span>
                                <span class="meta-value">{{ $statusCount['count'] }} {{ strtolower($statusCount['label']) }}</span>
                            </div>
                        @endforeach
                    </div>

                    @if ($externalIssueHealth['recent_failures']->isEmpty())
                        <p class="empty">No recent external sync failures for this site.</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($externalIssueHealth['recent_failures'] as $failure)
                                <article class="timeline-item internal-note">
                                    <div class="timeline-content">
                                        <strong>{{ $loop->first ? 'Last failure' : 'Earlier failure' }}</strong>
                                        <p class="message-body">{{ $failure['provider'] }} could not sync {{ $failure['project_key'] }}.</p>
                                        <div class="timeline-meta">
                                            @if ($failure['status'])
                                                <span>{{ $failure['status'] }}</span>
                                            @endif
                                            @if ($failure['occurred_at'])
                                                <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                            @endif
                                            <span>Provider details withheld</span>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="section-header">
                    <strong>Provider connections</strong>
                    <span class="lede">Account-owned</span>
                </div>

                <p class="lede">
                    Provider connections are shared by every site in this account and managed from the
                    <a class="text-link" href="{{ route('dashboard.account.integrations') }}">Integrations home</a>.
                    @unless ($canManageIntegrations)
                        Connections are managed by an account admin.
                    @endunless
                </p>

                @if ($canManageIntegrations)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.external-issue-projects.store', $site) }}">
                        @csrf

                        <div class="section-header">
                            <strong>Map project</strong>
                            <span class="lede">Site-scoped</span>
                        </div>

                        @if ($externalIssueProviderConnections->isEmpty())
                            <p class="empty">Add a provider connection before mapping this site to an external project.</p>
                        @else
                            <div class="field">
                                <label for="external_issue_provider_connection_id">Provider connection</label>
                                <select id="external_issue_provider_connection_id" name="external_issue_provider_connection_id">
                                    @foreach ($externalIssueProviderConnections as $connection)
                                        <option value="{{ $connection->id }}" @selected((int) old('external_issue_provider_connection_id') === $connection->id)>
                                            {{ $connection->name }} - {{ $connection->providerLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('external_issue_provider_connection_id')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="project_key">Project or repository</label>
                                <input id="project_key" name="project_key" type="text" value="{{ old('project_key') }}" placeholder="owner/repository, group/project, or project key">
                                @error('project_key')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="project_name">Project name</label>
                                <input id="project_name" name="project_name" type="text" value="{{ old('project_name') }}" placeholder="Wayfindr">
                                @error('project_name')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="field">
                                <label for="web_url">Project URL</label>
                                <input id="web_url" name="web_url" type="url" value="{{ old('web_url') }}" placeholder="https://github.com/adamgreenwell/wayfindr">
                                @error('web_url')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <button class="button" type="submit">Map project</button>
                        @endif
                    </form>
                @else
                    <p class="empty">Account owners and admins manage external issue routing.</p>
                @endif
            </section>

            <section class="section" aria-labelledby="data-responsibility-heading">
                <div class="section-header">
                    <h2 id="data-responsibility-heading">Data responsibility</h2>
                    <span class="lede">{{ $dataResponsibility['label'] }}</span>
                </div>

                <div class="notice-copy">
                    <p>{{ $dataResponsibility['message'] }}</p>
                    <p>{{ $dataResponsibility['guidance'] }}</p>
                </div>
            </section>

            <section class="section" aria-labelledby="inbound-email-heading">
                <div class="section-header">
                    <div>
                        <h2 id="inbound-email-heading">Email to this site</h2>
                        <p class="lede">Mail sent here becomes a conversation, and replies thread back into it.</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $site->inbound_address ? 'ready' : 'manual' }}">
                        {{ $site->inbound_address ? 'Receiving' : 'Not receiving' }}
                    </span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.inbound-address.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="inbound_address">Address</label>
                            <input type="email" id="inbound_address" name="inbound_address" maxlength="255"
                                placeholder="support@example.com" value="{{ old('inbound_address', $site->inbound_address) }}">
                            <p class="field-hint">
                                Point your mail provider's inbound route at Wayfindr, then put the address it
                                receives on here. Leave it empty to stop accepting mail for this site.
                            </p>
                            @error('inbound_address')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Save address</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Only an account admin can change where this site receives mail.</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="widget-appearance-heading">
                <div class="section-header">
                    <div>
                        <h2 id="widget-appearance-heading">What the widget looks like</h2>
                        <p class="lede">The only part of Wayfindr your visitors ever see.</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $appearance->accent ? 'ready' : 'manual' }}">
                        {{ $appearance->accent ? 'Branded' : 'Wayfindr default' }}
                    </span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.appearance.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="widget_accent">Accent colour</label>
                            <input type="text" id="widget_accent" name="widget_accent" maxlength="9"
                                placeholder="#7C3AED" value="{{ old('widget_accent', $appearance->accent) }}">
                            <p class="field-hint">
                                Your brand colour, not the
                                <a href="#site-colour-heading">colour agents recognise this site by</a> —
                                one is what visitors see, the other is how your desk tells sites apart.
                                @if ($appearance->accent)
                                    Rendered as <code>{{ $appearance->accentLight }}</code> on light backgrounds
                                    and <code>{{ $appearance->accentDark }}</code> on dark ones, so it stays legible in both.
                                @else
                                    Leave it empty to keep Wayfindr's.
                                @endif
                            </p>
                            @error('widget_accent')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="widget_position">Launcher position</label>
                            <select id="widget_position" name="widget_position">
                                <option value="right" @selected(old('widget_position', $appearance->position) === 'right')>Bottom right</option>
                                <option value="left" @selected(old('widget_position', $appearance->position) === 'left')>Bottom left</option>
                            </select>
                            <p class="field-hint">Move it if it collides with something already in that corner.</p>
                        </div>

                        <div class="field">
                            <label for="widget_greeting">Greeting</label>
                            <input type="text" id="widget_greeting" name="widget_greeting" maxlength="120"
                                placeholder="How can we help?" value="{{ old('widget_greeting', $appearance->greeting) }}">
                        </div>

                        <div class="field">
                            <label for="widget_placeholder">Composer placeholder</label>
                            <input type="text" id="widget_placeholder" name="widget_placeholder" maxlength="120"
                                placeholder="Type your message..." value="{{ old('widget_placeholder', $appearance->placeholder) }}">
                            <p class="field-hint">Both are shown as typed. A site that sets neither uses the widget's own copy, in the visitor's language.</p>
                        </div>

                        <button class="button" type="submit">Save appearance</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Only an account admin can change how the widget looks.</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="support-hours-heading">
                <div class="section-header">
                    <div>
                        <h2 id="support-hours-heading">When the desk is open</h2>
                        <p class="lede">What a visitor sees when they arrive outside your hours.</p>
                    </div>
                    <span class="readiness-status" data-status="{{ $availability->open ? 'ready' : 'manual' }}">
                        @if ($availability->closedUntil)
                            Closed early
                        @elseif ($availability->scheduled)
                            {{ $availability->open ? 'Open now' : 'Away' }}
                        @else
                            Always open
                        @endif
                    </span>
                </div>

                @if ($canUpdateSite)
                    <div class="desk-closure">
                        @if ($availability->closedUntil)
                            <p class="desk-closure-state">
                                Desk closed early.
                                @if ($availability->opensAt)
                                    {{-- Deliberately NOT ReaderClock: this reports what
                                         VISITORS are told, and `opensAt` already carries the
                                         site's own zone. Moving it to the reader's clock would
                                         make the sentence untrue for an agent sitting in a
                                         different one. --}}
                                    Visitors are told support is back at
                                    {{ $availability->opensAt->format('H:i') }}
                                    on {{ $availability->opensAt->format('j M') }}.
                                @else
                                    The schedule has no opening to return to, so visitors
                                    are not promised a time.
                                @endif
                            </p>

                            <form method="POST" action="{{ route('dashboard.sites.availability.reopen', $site) }}">
                                @csrf
                                @method('DELETE')
                                <button class="button" type="submit">Reopen now</button>
                            </form>
                        @else
                            <p class="desk-closure-state">
                                Close the desk early without changing the schedule. Every choice
                                expires on its own.
                            </p>

                            <form class="desk-closure-actions" method="POST"
                                action="{{ route('dashboard.sites.availability.close', $site) }}">
                                @csrf
                                <button class="button secondary" type="submit" name="closure" value="hour">For an hour</button>
                                <button class="button secondary" type="submit" name="closure" value="today">Rest of today</button>
                                <button class="button secondary" type="submit" name="closure" value="tomorrow">Until tomorrow</button>
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
                                Keep support hours for this site
                            </label>
                            <p class="field-help">
                                Off means the widget behaves the same at 3pm Tuesday and 3am Sunday, which is how every
                                site works until you turn this on.
                            </p>
                        </div>

                        <div class="field">
                            <label for="availability_timezone">Timezone</label>
                            <select id="availability_timezone" name="availability_timezone">
                                @foreach (DateTimeZone::listIdentifiers() as $identifier)
                                    <option value="{{ $identifier }}"
                                        @selected(old('availability_timezone', $availabilitySettings['timezone'] ?? config('app.timezone')) === $identifier)>
                                        {{ $identifier }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="field-help">Hours are read in this zone, so they hold across daylight saving.</p>
                            @error('availability_timezone')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr><th>Day</th><th>Open</th><th>From</th><th>To</th></tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Support\Sites\SiteAvailability::DAYS as $day)
                                        <tr>
                                            <td>{{ ucfirst($day) }}</td>
                                            <td>
                                                <input type="hidden" name="availability_open[{{ $day }}]" value="0">
                                                <input type="checkbox" name="availability_open[{{ $day }}]" value="1"
                                                    aria-label="{{ ucfirst($day) }} open"
                                                    @checked(old('availability_open.'.$day, $availabilityWeekdays[$day]['open']))>
                                            </td>
                                            <td>
                                                <input type="time" name="availability_from[{{ $day }}]"
                                                    aria-label="{{ ucfirst($day) }} opens at"
                                                    value="{{ old('availability_from.'.$day, $availabilityWeekdays[$day]['from']) }}">
                                            </td>
                                            <td>
                                                <input type="time" name="availability_to[{{ $day }}]"
                                                    aria-label="{{ ucfirst($day) }} closes at"
                                                    value="{{ old('availability_to.'.$day, $availabilityWeekdays[$day]['to']) }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="field">
                            <label for="availability_away_message">What to tell a visitor who arrives out of hours</label>
                            <textarea id="availability_away_message" name="availability_away_message" rows="3"
                                placeholder="We are closed right now. Leave a message and we will reply when we are back.">{{ old('availability_away_message', $availabilitySettings['away_message'] ?? '') }}</textarea>
                            <p class="field-help">
                                Write this in your visitors' language. It is shown in the widget exactly as typed, so
                                keep it free of anything private.
                            </p>
                            @error('availability_away_message')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Save support hours</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Only an account admin can set support hours.</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="widget-language-heading">
                <div class="section-header">
                    <div>
                        <h2 id="widget-language-heading">What language the widget speaks</h2>
                        <p class="lede">A default for visitors whose own browser has not answered.</p>
                    </div>
                    <span class="lede">{{ $widgetLocale ? ($widgetLanguages[$widgetLocale] ?? $widgetLocale) : 'Following the visitor' }}</span>
                </div>

                <div class="notice-copy">
                    <p>The widget already follows each visitor's browser language, and falls back to English. This setting only decides what a visitor sees when their browser has asked for a language Wayfindr does not carry.</p>
                    <p>Your own words &mdash; the away message and the intake introduction &mdash; are shown exactly as you wrote them whatever this is set to.</p>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.language.update', $site) }}">
                        @csrf
                        @method('PUT')
                        <div class="meta-grid">
                            <div class="meta-item">
                                <label class="meta-label" for="widget_locale">Default language</label>
                                <select id="widget_locale" name="widget_locale">
                                    <option value="" @selected(old('widget_locale', $widgetLocale) === null || old('widget_locale', $widgetLocale) === '')>Follow the visitor's browser</option>
                                    @foreach ($widgetLanguages as $code => $label)
                                        <option value="{{ $code }}" @selected(old('widget_locale', $widgetLocale) === $code)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="meta-item">
                                <span class="meta-label">Widget language</span>
                                <button class="button" type="submit">Save language</button>
                            </div>
                        </div>
                    </form>
                @else
                    <p class="lede">Only an account admin can change this.</p>
                @endif

                <div class="notice-copy">
                    <p>If your own application knows which language a visitor reads &mdash; because they chose it, or because they are signed in &mdash; tell the widget directly by adding <code>data-wayfindr-locale="de"</code> to the install snippet. That outranks both this setting and the browser, because your application knows better than either.</p>
                </div>
            </section>

            <section class="section" aria-labelledby="rating-prompt-heading">
                <div class="section-header">
                    <div>
                        <h2 id="rating-prompt-heading">Asking how it went</h2>
                        <p class="lede">Every other figure on the reports page says how fast your desk moved, not whether it helped.</p>
                    </div>
                    <span class="lede">{{ $ratingPrompt->enabled ? 'Asking' : 'Not asking' }}</span>
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
                                Ask the visitor how it went when a conversation closes
                            </label>
                            <p class="field-help">
                                Three answers &mdash; good, ok, bad &mdash; and an optional comment. Three rather
                                than five: the signal worth having is <em>did this go badly</em>, and finer scales
                                mostly add noise and translation problems.
                            </p>
                        </div>

                        <div class="field">
                            <label for="rating_intro">What to ask</label>
                            <input type="text" id="rating_intro" name="rating_intro" maxlength="160"
                                placeholder="How did we do?" value="{{ old('rating_intro', $ratingPrompt->intro) }}">
                            <p class="field-help">Written in your visitors' language, and shown to all of them. Left empty, the widget asks in the visitor's own language.</p>
                            @error('rating_intro')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Save rating prompt</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Only an account admin can change whether visitors are asked.</p>
                    </div>
                @endif

                <div class="notice-copy">
                    <p>
                        A visitor answers once per close. Changing their mind replaces the answer rather than
                        adding one, so a small number of responses cannot be swamped &mdash; but a conversation
                        that is reopened and closed again is asked afresh, because that is a genuinely
                        different question.
                    </p>
                    <p>
                        Comments are a visitor's own words and are kept under this site's retention rules like
                        any other message. See <a class="text-link" href="#data-responsibility-heading">Data responsibility</a>.
                    </p>
                </div>
            </section>

            <section class="section" aria-labelledby="visitor-intake-heading">
                <div class="section-header">
                    <div>
                        <h2 id="visitor-intake-heading">What to ask before a conversation starts</h2>
                        <p class="lede">An anonymous visitor leaves no way to reach them once the chat ends.</p>
                    </div>
                    <span class="lede">{{ $intake->asks() ? 'Asking' : 'Asking nothing' }}</span>
                </div>

                @if ($canUpdateSite)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.intake.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr><th>Ask for</th><th>Off</th><th>Optional</th><th>Required</th></tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Support\Sites\SiteIntake::FIELDS as $field)
                                        <tr>
                                            <td>{{ ucfirst($field) }}</td>
                                            @foreach ([\App\Support\Sites\SiteIntake::OFF, \App\Support\Sites\SiteIntake::OPTIONAL, \App\Support\Sites\SiteIntake::REQUIRED] as $mode)
                                                <td>
                                                    <input type="radio" name="intake_fields[{{ $field }}]" value="{{ $mode }}"
                                                        aria-label="{{ ucfirst($field) }} {{ $mode }}"
                                                        @checked(old('intake_fields.'.$field, $intake->fields[$field]) === $mode)>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <p class="field-help">
                            A visitor who has already answered is not asked again: a name or email you hold turns
                            that question off on their next conversation, and a stored address also covers the
                            out-of-hours rule. A reason is asked every time, because it belongs to the conversation.
                        </p>
                        <p class="field-help">
                            Being identified through the SDK does not by itself skip these questions. That
                            identifier is supplied by your own page and could be set by anyone, so it cannot
                            switch off something you made required. Out of hours an email is always asked for
                            unless you already have one.
                        </p>

                        <div class="field">
                            <label for="intake_intro">What to say above the form</label>
                            <textarea id="intake_intro" name="intake_intro" rows="2"
                                placeholder="Tell us who you are so we can get back to you.">{{ old('intake_intro', $intake->intro) }}</textarea>
                            <p class="field-help">Written in your visitors' language, and shown to all of them.</p>
                            @error('intake_intro')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <button class="button" type="submit">Save visitor intake</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Only an account admin can change what visitors are asked.</p>
                    </div>
                @endif
            </section>

            <section class="section" aria-labelledby="presence-settings-heading">
                <div class="section-header">
                    <h2 id="presence-settings-heading">Live visitor presence</h2>
                    <span class="lede">{{ $presenceEnabled ? 'On' : 'Off' }}</span>
                </div>

                @if ($canUpdatePrivacy)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.presence.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field field-check">
                            <label for="presence_enabled">
                                <input type="checkbox" id="presence_enabled" name="presence_enabled" value="1" @checked(old('presence_enabled', $presenceEnabled))>
                                Show visitors who are browsing but have not made contact
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
                            With this on, the widget reports every {{ $presenceEvery }} seconds that somebody is on the site{{ $presencePageUrls ? ', and which page they are on' : '' }}. Visitors are told in the widget and can decline.{{ $presencePageUrls ? ' Addresses are stored without query strings.' : '' }} A visitor who never makes contact is deleted {{ $presenceRetentionDays }} {{ Str::plural('day', $presenceRetentionDays) }} after they were last seen.
                        </p>

                        <div class="field field-check">
                            <label for="presence_page_urls">
                                <input type="checkbox" id="presence_page_urls" name="presence_page_urls" value="1" @checked(old('presence_page_urls', $presencePageUrls))>
                                Include which page they are on
                            </label>
                        </div>

                        <p class="field-help">
                            Page addresses are stored with the query string removed and path segments that look like tokens replaced. That check is a good guess, not a guarantee &mdash; if this site puts invitation codes, order numbers or reset tokens in the path itself, turn this off. No page address is then kept for any visitor, whether they were browsing, opened the widget or started a conversation, and the ones already stored are deleted. The page a conversation was started from stays on that conversation, because it is part of a support record somebody wrote in about.
                        </p>

                        <p class="field-help">
                            Leave presence off and nothing changes: the install records people only once they open the widget or get in touch. Turning it off later deletes the visitors it collected who never made contact.
                        </p>

                        <button class="button" type="submit">Save presence setting</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Account owners and admins decide whether this site watches visitors who have not made contact.</p>
                        <p>{{ $presenceEnabled ? 'It is on for this site.' : 'It is off for this site.' }}</p>
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
                        <a href="{{ route('dashboard.sites.live', $site) }}">Open the live visitor board</a> to see who is on the site now.
                    </p>
                @endif
            </section>

            <section class="section" aria-labelledby="privacy-settings-heading">
                <div class="section-header">
                    <h2 id="privacy-settings-heading">Mask selectors</h2>
                    <span class="lede">{{ count($maskSelectors) }} configured</span>
                </div>

                @if ($canUpdatePrivacy)
                    <form class="section-form" method="POST" action="{{ route('dashboard.sites.update', $site) }}">
                        @csrf
                        @method('PUT')

                        <div class="field">
                            <label for="mask_selectors">Selectors to mask before cobrowse sharing</label>
                            <textarea id="mask_selectors" name="mask_selectors" spellcheck="false">{{ old('mask_selectors', implode("\n", $maskSelectors)) }}</textarea>
                            @error('mask_selectors')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="field-help">
                            Add one CSS selector per line. These selectors are sent to the widget as public configuration, so do not put private notes or secrets here.
                        </p>

                        <div class="field">
                            <label for="mask_terms">Extra sensitive field terms</label>
                            <textarea id="mask_terms" name="mask_terms" spellcheck="false">{{ old('mask_terms', implode("\n", $maskTerms)) }}</textarea>
                            @error('mask_terms')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <p class="field-help">
                            Add one term per line to extend automatic sensitive-field detection for this site's language or domain (for example <code>contraseña</code> or <code>NHS number</code>). Terms are matched against field labels and attributes. They are public widget configuration, so use plain words only, never secrets.
                        </p>

                        <div class="notice-list">
                            <p><code>data-wayfindr-mask</code> and <code>data-wayfindr-private</code> force masking for known sensitive areas.</p>
                            <p><code>data-wayfindr-allow</code> is only for deliberate false positives where the content is safe to share.</p>
                        </div>

                        <button class="button" type="submit">Save privacy settings</button>
                    </form>
                @else
                    <div class="notice-copy">
                        <p>Account owners and admins manage privacy settings.</p>
                    </div>

                    @if (count($maskSelectors) === 0)
                        <p class="empty">No custom mask selectors are configured.</p>
                    @else
                        <div class="notice-list">
                            @foreach ($maskSelectors as $maskSelector)
                                <p><code>{{ $maskSelector }}</code></p>
                            @endforeach
                        </div>
                    @endif

                    @if (count($maskTerms) === 0)
                        <p class="empty">No extra sensitive field terms are configured.</p>
                    @else
                        <div class="notice-list">
                            @foreach ($maskTerms as $maskTerm)
                                <p><code>{{ $maskTerm }}</code></p>
                            @endforeach
                        </div>
                    @endif

                    <div class="notice-list">
                        <p><code>data-wayfindr-mask</code> and <code>data-wayfindr-private</code> force masking for known sensitive areas.</p>
                        <p><code>data-wayfindr-allow</code> is only for deliberate false positives where the content is safe to share.</p>
                    </div>
                @endif
            </section>

            @can('archive', $site)
                @unless ($site->isArchived())
                    <section class="section" aria-labelledby="retire-site-heading">
                        <div class="section-header">
                            <h2 id="retire-site-heading">Retire this site</h2>
                            <span class="lede">Stop serving the widget</span>
                        </div>

                        <p class="lede">
                            Archiving takes this site out of service immediately: the widget stops answering everywhere, and the site leaves the working site list. Nothing is deleted. Conversations, tickets, visitors and audit history stay exactly as they are, and you can restore the site at any time.
                        </p>

                        <p class="field-help">
                            Visitors with the widget open will stop being served as soon as you archive, including anyone part-way through a conversation.
                        </p>

                        <form method="POST" action="{{ route('dashboard.sites.archive', $site) }}">
                            @csrf
                            <button class="button secondary" type="submit">Archive this site</button>
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
                            <h2 id="purge-site-heading">Delete this site permanently</h2>
                            <span class="readiness-status" data-status="attention">Cannot be undone</span>
                        </div>

                        <p class="lede">
                            This deletes the site and every record beneath it. Unlike archiving, nothing here is recoverable &mdash; the only way back is restoring a backup taken before the deletion.
                        </p>

                        <div class="notice-list">
                            <p>{{ $purgeConversationCount }} {{ \Illuminate\Support\Str::plural('conversation', $purgeConversationCount) }} and all their messages and attachments</p>
                            <p>{{ $purgeTicketCount }} {{ \Illuminate\Support\Str::plural('ticket', $purgeTicketCount) }}, including any links to external issues</p>
                            <p>{{ $purgeVisitorCount }} {{ \Illuminate\Support\Str::plural('visitor', $purgeVisitorCount) }} and their cobrowse history</p>
                            <p>This site's audit history. A record that you deleted it is kept against the account.</p>
                        </div>

                        <form class="section-form" method="POST" action="{{ route('dashboard.sites.purge', $site) }}">
                            @csrf
                            @method('DELETE')

                            <div class="field">
                                <label for="confirm_name">Type <strong>{{ $site->name }}</strong> to confirm</label>
                                <input id="confirm_name" name="confirm_name" type="text" autocomplete="off" spellcheck="false" required>
                                @error('confirm_name')
                                    <p class="field-error">{{ $message }}</p>
                                @enderror
                            </div>

                            <button class="button danger" type="submit">Permanently delete this site</button>
                        </form>
                    </section>
                @endif
            @endcan
</x-layouts.app>
