<x-layouts.app title="Site Settings" :agent="$agent" :account="$account">
            <a class="text-link" href="{{ route('dashboard.sites.index') }}">Back to sites</a>
            <h1>{{ $site->name }}</h1>
            <p class="lede">Privacy settings for {{ $site->domain ?? 'an unconfigured domain' }}</p>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="site-support-readiness-heading">
                <div class="section-header">
                    <div>
                        <h2 id="site-support-readiness-heading">Support readiness</h2>
                        <p class="lede">Site support loop</p>
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
                        <p class="lede">Current work for this site</p>
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
                        <p class="lede">Ticket handoff for this site</p>
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

            @php
                $latestVisitor = $site->latestVisitor;
                $lastSeenAt = $latestVisitor?->last_seen_at;
                $lastPageUrl = data_get($latestVisitor?->metadata, 'last_page_url');
                $installHealth = \App\Support\SiteInstallHealth::fromVisitor($latestVisitor);
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
            @endphp

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
            </section>

            <section id="install-snippet" class="section" aria-labelledby="install-snippet-heading">
                <div class="section-header">
                    <h2 id="install-snippet-heading">Install snippet</h2>
                    <div class="section-actions">
                        @if ($agent->isPlatformOperator())
                            <a class="text-link" href="{{ route('operator.dashboard') }}">Open operator console</a>
                        @endif
                        @if ($agent->isAdmin())
                            <a class="text-link" href="{{ route('dashboard.readiness.show') }}">Review readiness</a>
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
                                    <th scope="col">Link</th>
                                    @if ($canManageIntegrations)
                                        <th scope="col">Action</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($siteExternalIssueProjects as $externalIssueProject)
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

                @if ($canManageIntegrations)
                    <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.store') }}">
                        @csrf
                        <input type="hidden" name="site_id" value="{{ $site->id }}">

                        <div class="section-header">
                            <strong>Add provider connection</strong>
                            <span class="lede">Account-owned</span>
                        </div>

                        <div class="field">
                            <label for="provider">Provider</label>
                            <select id="provider" name="provider">
                                @foreach ($externalIssueProviders as $value => $label)
                                    <option value="{{ $value }}" @selected(old('provider', 'github') === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('provider')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="provider_connection_name">Connection name</label>
                            <input id="provider_connection_name" name="name" type="text" value="{{ old('name') }}" placeholder="Engineering GitHub">
                            @error('name')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="base_url">Base URL</label>
                            <input id="base_url" name="base_url" type="url" value="{{ old('base_url') }}" placeholder="https://api.github.com">
                            @error('base_url')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="field">
                            <label for="credential_token">Token or credential placeholder</label>
                            <input id="credential_token" name="credential_token" type="password" value="" autocomplete="new-password">
                            @error('credential_token')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="notice-list">
                            @foreach ($externalIssueCapabilities as $value => $label)
                                <label class="check-row" for="capability_{{ $value }}">
                                    <input
                                        id="capability_{{ $value }}"
                                        name="capabilities[]"
                                        type="checkbox"
                                        value="{{ $value }}"
                                        @checked(in_array($value, $selectedCapabilities, true))
                                    >
                                    <span>Provider can {{ strtolower($label) }}</span>
                                </label>
                            @endforeach
                        </div>

                        @error('capabilities')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                        @error('capabilities.*')
                            <p class="field-error">{{ $message }}</p>
                        @enderror

                        <button class="button" type="submit">Save provider connection</button>
                    </form>

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

                    <div class="notice-list">
                        <p><code>data-wayfindr-mask</code> and <code>data-wayfindr-private</code> force masking for known sensitive areas.</p>
                        <p><code>data-wayfindr-allow</code> is only for deliberate false positives where the content is safe to share.</p>
                    </div>
                @endif
            </section>
</x-layouts.app>
