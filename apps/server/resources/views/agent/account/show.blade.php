<x-layouts.app :title="__('account.document_title')" :agent="$agent" :account="$account">
            @php
                $unknownLanguage = static fn (mixed $value, string $element = 'span'): string => '<'.$element.' lang="">'.e((string) $value).'</'.$element.'>';
            @endphp

            <x-page-header :title="__('account.title')" :subtitle="__('account.subtitle')">
                <x-slot:actions>
                    <span class="lede">{{ trans_choice('account.agent_count', $agents->count(), ['count' => \App\Support\ReaderNumber::count($agents->count())]) }}</span>
                </x-slot:actions>
            </x-page-header>

            @if (session('status'))
                <p class="status-message">{{ __(session('status')) }}</p>
            @endif

            @if (session('created_agent_email') && session('created_agent_password'))
                <section class="section" aria-labelledby="temporary-password-heading">
                    <div class="section-header">
                        <h2 id="temporary-password-heading">{{ __('account.temporary_password.heading') }}</h2>
                        <span class="lede" lang="">{{ session('created_agent_email') }}</span>
                    </div>
                    <div class="notice-copy">
                        <p>{{ __('account.temporary_password.help') }}</p>
                    </div>
                    <pre class="code-block"><code lang="">{{ session('created_agent_password') }}</code></pre>
                </section>
            @endif

            @php
                $accountMapItems = [
                    [
                        'key' => 'account',
                        'href' => '#account-context-heading',
                    ],
                    [
                        'key' => 'role',
                        'href' => '#role-boundary-heading',
                    ],
                    [
                        'key' => 'sites',
                        'href' => '#site-access-matrix',
                    ],
                ];

                if ($canViewExternalIssueReadiness && $externalIssueReadiness) {
                    $accountMapItems[] = [
                        'key' => 'external',
                        'href' => '#external-issue-readiness-heading',
                    ];
                }

                $accountMapItems[] = [
                    'key' => 'activity',
                    'href' => '#account-activity-heading',
                ];

                if ($canCreateAgents) {
                    $accountMapItems[] = [
                        'key' => 'add_agent',
                        'href' => '#add-agent-heading',
                    ];
                }

                if ($canViewAlertDelivery && $agentAlertReadinessSummary) {
                    $accountMapItems[] = [
                        'key' => 'alerts',
                        'href' => '#team-alert-readiness-heading',
                    ];
                }

                $accountMapItems[] = [
                    'key' => 'agents',
                    'href' => '#agents',
                ];
            @endphp

            <section class="section" aria-labelledby="account-map-heading">
                <div class="section-header">
                    <div>
                        <h2 id="account-map-heading">{{ __('account.map.heading') }}</h2>
                    </div>
                    <span class="lede">{{ trans_choice('account.map.count', count($accountMapItems), ['count' => \App\Support\ReaderNumber::count(count($accountMapItems))]) }}</span>
                </div>
                <div class="management-list">
                    @foreach ($accountMapItems as $accountMapItem)
                        <a class="management-link" href="{{ $accountMapItem['href'] }}">
                            <span>
                                <strong>{{ __('account.map.items.'.$accountMapItem['key'].'.label') }}</strong>
                                <span class="lede">{{ __('account.map.items.'.$accountMapItem['key'].'.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.map.open') }}</span>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="account-context-heading">
                <div class="section-header">
                    <h2 id="account-context-heading" lang="">{{ $account->name }}</h2>
                    <span class="lede">{{ __('account.context.boundary') }}</span>
                </div>
                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('account.context.your_role') }}</span>
                        <span class="meta-value" @if ($agent->customRole) lang="" @endif>{{ $agent->customRole?->name ?? ($roleLabels[$agent->account_role?->value] ?? __('profile.roles.agent')) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('account.context.sites') }}</span>
                        <span class="meta-value">{{ trans_choice('account.context.site_count', $siteCount, ['count' => \App\Support\ReaderNumber::count($siteCount)]) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('account.context.visible') }}</span>
                        <span class="meta-value">{{ trans_choice('account.context.site_count', $visibleSiteCount, ['count' => \App\Support\ReaderNumber::count($visibleSiteCount)]) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('account.context.assignments') }}</span>
                        <span class="meta-value">{{ trans_choice('account.context.assignment_count', $supportAssignmentCount, ['count' => \App\Support\ReaderNumber::count($supportAssignmentCount)]) }}</span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="role-boundary-heading">
                <div class="section-header">
                    <h2 id="role-boundary-heading">{{ __('account.role_boundary.heading') }}</h2>
                    <span class="lede">{{ $canManageRoles ? __('account.role_boundary.owner_enabled') : __('account.role_boundary.read_only') }}</span>
                </div>
                <div class="notice-copy">
                    <p>{{ __('account.role_boundary.authority') }}</p>
                    <p>{{ __('account.role_boundary.changes') }}</p>
                    <p>{{ __('account.role_boundary.suspension') }}</p>
                </div>
                @if ($canManageRoles)
                    <div class="section-actions">
                        <a class="button secondary" href="{{ route('dashboard.account.roles.index') }}">{{ __('account.role_boundary.manage_custom_roles') }}</a>
                    </div>
                @endif
            </section>

            <section id="site-access-matrix" class="section" aria-labelledby="site-access-matrix-heading">
                <div class="section-header">
                    <h2 id="site-access-matrix-heading">{{ __('account.site_access.heading') }}</h2>
                    <span class="lede">{{ trans_choice('account.site_access.visible_count', $visibleSites->count(), ['count' => \App\Support\ReaderNumber::count($visibleSites->count())]) }}</span>
                </div>

                @if ($visibleSites->isEmpty())
                    <p class="empty">{{ __('account.site_access.empty') }}</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('account.site_access.columns.site') }}</th>
                                    <th scope="col">{{ __('account.site_access.columns.model') }}</th>
                                    <th scope="col">{{ __('account.site_access.columns.agents') }}</th>
                                    <th scope="col">{{ __('account.site_access.columns.manage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($visibleSites as $site)
                                    @php
                                        $assignedAgents = $site->supportAgents;
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong lang="">{{ $site->name }}</strong>
                                            @if ($site->domain)
                                                <span class="lede" lang="">{{ $site->domain }}</span>
                                            @else
                                                <span class="lede">{{ __('account.site_access.domain_missing') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($assignedAgents->isEmpty())
                                                {{ __('account.site_access.fallback') }}
                                            @else
                                                {{ __('account.site_access.explicit') }}
                                            @endif
                                        </td>
                                        <td>
                                            @if ($assignedAgents->isEmpty())
                                                <strong>{{ __('account.site_access.all_active') }}</strong>
                                                <span class="lede">{{ __('account.site_access.eligible', ['count' => \App\Support\ReaderNumber::count($activeAgentCount)]) }}</span>
                                            @else
                                                <strong>{{ trans_choice('account.site_access.assigned', $assignedAgents->count(), ['count' => \App\Support\ReaderNumber::count($assignedAgents->count())]) }}</strong>
                                                <span class="lede">
                                                    @foreach ($assignedAgents as $supportAgent)
                                                        <span lang="">{{ $supportAgent->name }}</span> (<span @if ($supportAgent->customRole) lang="" @endif>{{ $supportAgent->customRole?->name ?? ($roleLabels[$supportAgent->account_role?->value] ?? __('profile.roles.agent')) }}</span>)@if (! $loop->last), @endif
                                                    @endforeach
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.sites.show', $site) }}">{{ __('account.site_access.manage') }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            @if ($canViewExternalIssueReadiness && $externalIssueReadiness)
                <section class="section" aria-labelledby="external-issue-readiness-heading">
                    <div class="section-header">
                        <div>
                            <h2 id="external-issue-readiness-heading">{{ __('account.external.heading') }}</h2>
                            <p class="lede">{{ $externalIssueReadiness['detail'] }}</p>
                        </div>
                        <span class="readiness-status" data-status="{{ $externalIssueReadiness['tone'] }}">
                            {{ $externalIssueReadiness['label'] }}
                        </span>
                    </div>

                    <div class="meta-grid readiness-summary-grid">
                        @foreach ($externalIssueReadiness['metrics'] as $metric)
                            <div class="meta-item">
                                <span class="meta-label">{{ $metric['label'] }}</span>
                                <span class="meta-value">{{ $metric['value'] }}</span>
                                <span class="lede">
                                    <span class="readiness-status" data-status="{{ $metric['tone'] }}">
                                        {{ __('account.external.tones.'.$metric['tone']) }}
                                    </span>
                                </span>
                                @if (! empty($metric['href']) && ! empty($metric['action']))
                                    <p class="readiness-action">
                                        <a class="text-link" href="{{ $metric['href'] }}">{{ $metric['action'] }}</a>
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if ($externalIssueReadiness['projects']->isEmpty())
                        <p class="empty">{{ __('account.external.projects.empty') }}</p>
                    @else
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('account.external.projects.columns.site') }}</th>
                                        <th scope="col">{{ __('account.external.projects.columns.provider') }}</th>
                                        <th scope="col">{{ __('account.external.projects.columns.project') }}</th>
                                        <th scope="col">{{ __('account.external.projects.columns.capabilities') }}</th>
                                        <th scope="col">{{ __('account.external.projects.columns.handoff') }}</th>
                                        <th scope="col">{{ __('account.external.projects.columns.manage') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($externalIssueReadiness['projects'] as $project)
                                        <tr>
                                            <td>
                                                <strong @if ($project['site_language'] === '') lang="" @endif>{{ $project['site'] }}</strong>
                                                <span class="lede">{{ $project['enabled'] ? __('account.external.projects.connection_enabled') : __('account.external.projects.connection_disabled') }}</span>
                                            </td>
                                            <td>
                                                <strong @if ($project['connection_language'] === '') lang="" @endif>{{ $project['connection'] }}</strong>
                                                <span class="lede" @if ($project['provider_language'] === '') lang="" @endif>{{ $project['provider'] }}</span>
                                            </td>
                                            <td>
                                                <strong lang="">{{ $project['project_key'] }}</strong>
                                                @if ($project['project_name'])
                                                    <span class="lede" lang="">{{ $project['project_name'] }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @forelse ($project['capabilities'] as $capability)
                                                    <span>{{ $capability }}</span>@if (! $loop->last)<br>@endif
                                                @empty
                                                    <span>{{ __('account.external.projects.link_only') }}</span>
                                                @endforelse
                                            </td>
                                            <td>
                                                <span class="readiness-status" data-status="{{ $project['handoff']['tone'] }}">
                                                    {{ $project['handoff']['label'] }}
                                                </span>
                                                <span class="lede">{{ $project['handoff']['detail'] }}</span>
                                            </td>
                                            <td>
                                                <a class="text-link" href="{{ $project['href'] }}">{{ __('account.external.projects.manage') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($canManageTickets)
                        @if ($externalIssueReadiness['recent_failures']->isEmpty())
                            <p class="empty">{{ __('account.external.failures.empty') }}</p>
                        @else
                            <div class="timeline-list">
                                @foreach ($externalIssueReadiness['recent_failures'] as $failure)
                                    @php
                                        $failureProvider = $failure['provider_language'] === ''
                                            ? $unknownLanguage($failure['provider'])
                                            : e($failure['provider']);
                                        $failureProject = $failure['project_language'] === ''
                                            ? $unknownLanguage($failure['project_key'])
                                            : e($failure['project_key']);
                                    @endphp
                                    <article class="timeline-item internal-note">
                                        <div class="timeline-content">
                                            <strong>{{ $loop->first ? __('account.external.failures.last') : __('account.external.failures.earlier') }}</strong>
                                            <p class="message-body">{!! __('account.external.failures.body', [
                                                'provider' => $failureProvider,
                                                'project' => $failureProject,
                                            ]) !!}</p>
                                            <div class="timeline-meta">
                                                @if ($failure['status'])
                                                    <span>{!! __('account.external.failures.status', ['status' => $unknownLanguage($failure['status'])]) !!}</span>
                                                @endif
                                                @if ($failure['occurred_at'])
                                                    <span>{{ $failure['occurred_at']->diffForHumans() }}</span>
                                                @endif
                                                <span>{{ __('account.external.failures.details_withheld') }}</span>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </section>
            @endif

            <section class="section" aria-labelledby="account-management-heading">
                <div class="section-header">
                    <h2 id="account-management-heading">{{ __('account.management.heading') }}</h2>
                    <span class="lede">{{ __('account.management.lede') }}</span>
                </div>
                <div class="management-list">
                    <a class="management-link" href="{{ route('dashboard.account.integrations') }}">
                        <span>
                            <strong>{{ __('account.management.items.integrations.label') }}</strong>
                            <span class="lede">{{ __('account.management.items.integrations.detail') }}</span>
                        </span>
                        <span class="management-action">{{ $canManageIntegrations ? __('account.management.actions.manage') : __('account.management.actions.view') }}</span>
                    </a>
                    @if ($canViewSites)
                        <a class="management-link" href="{{ route('dashboard.sites.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.sites.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.sites.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.open') }}</span>
                        </a>
                    @endif
                    @if ($canManageSecurity)
                        <a class="management-link" href="{{ route('dashboard.account.security.show') }}">
                            <span>
                                <strong>{{ __('two_factor.policy.link_label') }}</strong>
                                <span class="lede">{{ __('two_factor.policy.link_detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.manage') }}</span>
                        </a>
                    @endif
                    @if ($canManageKnowledge)
                        <a class="management-link" href="{{ route('dashboard.account.articles.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.articles.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.articles.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.manage') }}</span>
                        </a>
                        <a class="management-link" href="{{ route('dashboard.account.reply-templates.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.replies.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.replies.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.manage') }}</span>
                        </a>
                        <a class="management-link" href="{{ route('dashboard.account.labels.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.labels.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.labels.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.manage') }}</span>
                        </a>
                    @endif
                    @if ($canViewAudit)
                        <a class="management-link" href="{{ route('dashboard.account.audit.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.audit.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.audit.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.open') }}</span>
                        </a>
                    @endif
                    @if ($canManageIntegrations)
                        <a class="management-link" href="{{ route('dashboard.account.api-tokens.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.tokens.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.tokens.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.manage') }}</span>
                        </a>
                    @endif
                    @if ($canManageOperatorAccess)
                        <a class="management-link" href="{{ route('dashboard.account.break-glass.index') }}">
                            <span>
                                <strong>{{ __('account.management.items.operator_access.label') }}</strong>
                                <span class="lede">{{ __('account.management.items.operator_access.detail') }}</span>
                            </span>
                            <span class="management-action">{{ __('account.management.actions.review') }}</span>
                        </a>
                    @endif
                </div>
            </section>

            <section class="section" aria-labelledby="data-responsibility-heading">
                <div class="section-header">
                    <h2 id="data-responsibility-heading">{{ __('account.data_responsibility.heading') }}</h2>
                    <span class="lede">{{ __('account.data_responsibility.label') }}</span>
                </div>

                <div class="notice-copy">
                    <p>{{ __('account.data_responsibility.message') }}</p>
                    <p>{{ __('account.data_responsibility.guidance') }}</p>
                    <p>
                        <a class="text-link" href="{{ $dataResponsibility['docs_url'] }}" target="_blank" rel="noreferrer">
                            {{ __('account.data_responsibility.docs') }}
                        </a>
                    </p>
                </div>
            </section>

            <section class="section" aria-labelledby="account-activity-heading">
                <div class="section-header">
                    <h2 id="account-activity-heading">{{ __('account.activity.heading') }}</h2>
                    <div class="section-actions">
                        <span class="lede">{{ __('account.activity.shown', ['count' => \App\Support\ReaderNumber::count($accountActivity->count())]) }}</span>
                        @if ($canViewAudit)
                            <a class="button secondary" href="{{ route('dashboard.account.audit.index') }}">{{ __('account.activity.view_audit') }}</a>
                        @endif
                    </div>
                </div>
                @if ($accountActivity->isEmpty())
                    <p class="empty">{{ __('account.activity.empty') }}</p>
                @else
                    <div class="timeline-list">
                        @foreach ($accountActivity as $activity)
                            <article class="timeline-item ticket-activity">
                                <div class="timeline-content">
                                    <div class="message-meta">
                                        <strong>{{ $activity['label'] }}</strong>
                                        <span>{{ $activity['occurred_at']?->diffForHumans() }}</span>
                                    </div>
                                    <div class="timeline-meta">
                                        <span @if ($activity['actor_language'] === '') lang="" @endif>{{ $activity['actor'] }}</span>
                                        <span @if ($activity['subject_language'] === '') lang="" @endif>{{ $activity['subject'] }}</span>
                                        <span>{{ __('account.activity.scope') }}</span>
                                    </div>
                                    <p class="message-body">{{ $activity['body'] }}</p>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($canCreateAgents)
                <section class="section" aria-labelledby="add-agent-heading">
                    <div class="section-header">
                        <h2 id="add-agent-heading">{{ __('account.create.heading') }}</h2>
                        <span class="lede">{{ __('account.create.lede', ['role' => $newAgentRoleLabel]) }}</span>
                    </div>
                    <form class="section-form" method="POST" action="{{ route('dashboard.account.agents.store') }}">
                        @csrf
                        <div class="field">
                            <label for="agent-name">{{ __('account.create.name') }}</label>
                            <input id="agent-name" name="name" value="{{ old('name') }}" @if (filled(old('name'))) lang="" @endif autocomplete="name" required>
                            @error('name')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field">
                            <label for="agent-email">{{ __('account.create.email') }}</label>
                            <input id="agent-email" type="email" name="email" value="{{ old('email') }}" @if (filled(old('email'))) lang="" @endif autocomplete="email" required>
                            @error('email')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <label class="check-row" for="send_welcome_email">
                            <input
                                id="send_welcome_email"
                                name="send_welcome_email"
                                type="checkbox"
                                value="1"
                                @checked(old('send_welcome_email'))
                            >
                            <span>{{ __('account.create.welcome') }}</span>
                        </label>
                        @error('send_welcome_email')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                        <p class="field-help">{{ __('account.create.password_help') }}</p>
                        <p class="field-help">{{ __('account.create.email_help') }}</p>
                        <button class="button" type="submit">{{ __('account.create.submit') }}</button>
                    </form>
                </section>
            @endif

            @if ($canViewAlertDelivery && $agentAlertReadinessSummary)
                <section class="section" aria-labelledby="team-alert-readiness-heading">
                    <div class="section-header">
                        <div>
                            <h2 id="team-alert-readiness-heading">{{ __('account.team_alert.heading') }}</h2>
                            <p class="lede">{{ $agentAlertReadinessSummary['detail'] }}</p>
                        </div>
                        <div class="section-actions">
                            <span class="readiness-status" data-status="{{ $agentAlertReadinessSummary['status'] }}">
                                {{ $agentAlertReadinessSummary['label'] }}
                            </span>
                        </div>
                    </div>
                    <div class="meta-grid readiness-summary-grid">
                        @foreach ($agentAlertReadinessSummary['metrics'] as $metric)
                            <div class="meta-item">
                                <span class="meta-label">{{ $metric['label'] }}</span>
                                <span class="meta-value">{{ $metric['value'] }}</span>
                                <span class="lede">
                                    <span class="readiness-status" data-status="{{ $metric['tone'] }}">
                                        {{ __('account.external.tones.'.$metric['tone']) }}
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section id="agents" class="section" aria-labelledby="agents-heading">
                <div class="section-header">
                    <h2 id="agents-heading">{{ __('account.agents.heading') }}</h2>
                    <span class="lede">{{ __('account.agents.total', ['count' => \App\Support\ReaderNumber::count($agents->count())]) }}</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('account.agents.columns.agent') }}</th>
                                <th scope="col">{{ __('account.agents.columns.status') }}</th>
                                <th scope="col">{{ __('account.agents.columns.role') }}</th>
                                @if ($canViewAlertDelivery)
                                    <th scope="col">{{ __('account.agents.columns.alerts') }}</th>
                                @endif
                                @if ($canManageRoles)
                                    <th scope="col">{{ __('account.agents.columns.manage_role') }}</th>
                                @endif
                                @if ($canManageAgentAccess)
                                    <th scope="col">{{ __('account.agents.columns.manage_access') }}</th>
                                @endif
                                <th scope="col">{{ __('account.agents.columns.scope') }}</th>
                                @if ($canViewConversations || $canManageTickets)
                                    <th scope="col">{{ __('account.agents.columns.workload') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agents as $accountAgent)
                                @php
                                    $visibleOpenConversationCount = $canViewConversations
                                        ? (int) $accountAgent->visible_open_conversations_count
                                        : 0;
                                    $visibleOpenTicketCount = $canManageTickets
                                        ? (int) $accountAgent->visible_open_tickets_count
                                        : 0;
                                    $hasVisibleOpenWork = ($canViewConversations && $visibleOpenConversationCount > 0)
                                        || ($canManageTickets && $visibleOpenTicketCount > 0);
                                    $canManageThisAgentAccess = $canManageAgentAccess
                                        && $agent->can('deactivate', $accountAgent);
                                    $supportScope = $agentSupportScopes[$accountAgent->id] ?? [
                                        'explicitSites' => collect(),
                                        'fallbackSites' => collect(),
                                    ];
                                    $alertDeliverySummary = $agentAlertDeliverySummaries[$accountAgent->id] ?? [
                                        'primary' => __('account.agents.unknown_alerts'),
                                        'lines' => [
                                            ['text' => __('account.agents.alerts_unavailable')],
                                        ],
                                    ];
                                    $explicitSites = $supportScope['explicitSites'];
                                    $fallbackSites = $supportScope['fallbackSites'];
                                    $siteScopePreview = function ($sites) use ($unknownLanguage): string {
                                        $names = $sites->pluck('name');
                                        $preview = $names->take(2)
                                            ->map(fn (string $name): string => $unknownLanguage($name))
                                            ->join(', ');

                                        if ($names->count() > 2) {
                                            $preview .= ' '.e(__('account.agents.more', [
                                                'count' => \App\Support\ReaderNumber::count($names->count() - 2),
                                            ]));
                                        }

                                        return $preview;
                                    };
                                @endphp
                                <tr>
                                    <td>
                                        <strong id="account-agent-{{ $accountAgent->id }}-name" lang="">{{ $accountAgent->name }}</strong>
                                        <span class="lede" lang="">{{ $accountAgent->email }}</span>
                                    </td>
                                    <td>{{ $accountAgent->isDeactivated() ? __('account.agents.status.deactivated') : __('account.agents.status.active') }}</td>
                                    <td @if ($accountAgent->customRole) lang="" @endif>{{ $accountAgent->customRole?->name ?? ($roleLabels[$accountAgent->account_role?->value] ?? __('profile.roles.agent')) }}</td>
                                    @if ($canViewAlertDelivery)
                                        <td>
                                            <strong>{{ $alertDeliverySummary['primary'] }}</strong>
                                            @foreach ($alertDeliverySummary['lines'] as $line)
                                                <span class="lede">
                                                    @if (isset($line['tone']))
                                                        <span class="readiness-status" data-status="{{ $line['tone'] }}">{{ $line['text'] }}</span>
                                                    @else
                                                        {{ $line['text'] }}
                                                    @endif
                                                </span>
                                            @endforeach
                                        </td>
                                    @endif
                                    @if ($canManageRoles)
                                        <td>
                                            @if ($accountAgent->is($agent))
                                                <span class="lede">{{ __('account.agents.current_user') }}</span>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.agents.role.update', $accountAgent) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <label class="sr-only" for="account-role-{{ $accountAgent->id }}">{{ __('account.agents.columns.manage_role') }} <span lang="">{{ $accountAgent->name }}</span></label>
                                                    <select id="account-role-{{ $accountAgent->id }}" name="account_role">
                                                        @foreach ($roleOptions as $roleValue => $roleLabel)
                                                            <option value="{{ $roleValue }}" @selected($accountAgent->roleAssignmentKey() === $roleValue) @if (str_starts_with($roleValue, 'custom:')) lang="" @endif>{{ $roleLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button class="button secondary" type="submit">{{ __('account.agents.save_role') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    @endif
                                    @if ($canManageAgentAccess)
                                        <td>
                                            @if ($accountAgent->is($agent))
                                                <span class="lede">{{ __('account.agents.current_user') }}</span>
                                            @elseif (! $canManageThisAgentAccess)
                                                <span class="lede">{{ __('account.agents.owner_only') }}</span>
                                            @elseif ($accountAgent->isDeactivated())
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.agents.reactivate', $accountAgent) }}">
                                                    @csrf
                                                    <button class="button secondary" type="submit">{{ __('account.agents.reactivate') }}</button>
                                                </form>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.agents.deactivate', $accountAgent) }}">
                                                    @csrf
                                                    <button class="button danger" type="submit">{{ __('account.agents.deactivate') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    @endif
                                    <td>
                                        @if ($explicitSites->isEmpty() && $fallbackSites->isEmpty())
                                            <span class="lede">{{ __('account.agents.no_scope') }}</span>
                                        @else
                                            @if ($explicitSites->isNotEmpty())
                                                <strong>{{ trans_choice('account.agents.explicit_count', $explicitSites->count(), ['count' => \App\Support\ReaderNumber::count($explicitSites->count())]) }}</strong>
                                                <span class="lede">{!! __('account.agents.explicit', ['sites' => $siteScopePreview($explicitSites)]) !!}</span>
                                            @endif
                                            @if ($fallbackSites->isNotEmpty())
                                                <strong>{{ trans_choice('account.agents.fallback_count', $fallbackSites->count(), ['count' => \App\Support\ReaderNumber::count($fallbackSites->count())]) }}</strong>
                                                <span class="lede">{!! __('account.agents.fallback', ['sites' => $siteScopePreview($fallbackSites)]) !!}</span>
                                            @endif
                                            <a class="table-note text-link" href="#site-access-matrix">{{ __('account.agents.review_access') }}</a>
                                        @endif
                                    </td>
                                    @if ($canViewConversations || $canManageTickets)
                                        <td>
                                            @if ($hasVisibleOpenWork)
                                                @if ($canViewConversations && $visibleOpenConversationCount > 0)
                                                    <strong>{{ trans_choice('account.agents.open_conversations', $visibleOpenConversationCount, ['count' => \App\Support\ReaderNumber::count($visibleOpenConversationCount)]) }}</strong>
                                                @endif
                                                @if ($canManageTickets && $visibleOpenTicketCount > 0)
                                                    <span class="lede">{{ trans_choice('account.agents.open_tickets', $visibleOpenTicketCount, ['count' => \App\Support\ReaderNumber::count($visibleOpenTicketCount)]) }}</span>
                                                @endif
                                            @else
                                                <span class="lede">{{ __('account.agents.no_work') }}</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
</x-layouts.app>
