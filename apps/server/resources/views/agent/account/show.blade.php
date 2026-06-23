<x-layouts.app title="Account" :agent="$agent" :account="$account">
            <div class="section-header">
                <div>
                    <h1>Account</h1>
                    <p class="lede">Your role, team roster, and visible support scope.</p>
                </div>
                <div class="section-actions">
                    <span class="lede">{{ $agents->count() }} {{ \Illuminate\Support\Str::plural('agent', $agents->count()) }}</span>
                </div>
            </div>

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            @if (session('created_agent_email') && session('created_agent_password'))
                <section class="section" aria-labelledby="temporary-password-heading">
                    <div class="section-header">
                        <h2 id="temporary-password-heading">Temporary password</h2>
                        <span class="lede">{{ session('created_agent_email') }}</span>
                    </div>
                    <div class="notice-copy">
                        <p>Share this password securely. It is shown once and should be changed by the agent after sign-in.</p>
                    </div>
                    <pre class="code-block"><code>{{ session('created_agent_password') }}</code></pre>
                </section>
            @endif

            @php
                $accountMapItems = [
                    [
                        'label' => 'Account boundary',
                        'detail' => 'Role, site count, visible scope, and support assignments.',
                        'href' => '#account-context-heading',
                    ],
                    [
                        'label' => 'Role boundary',
                        'detail' => 'How account authority differs from site-level access.',
                        'href' => '#role-boundary-heading',
                    ],
                    [
                        'label' => 'Site access',
                        'detail' => 'Which support sites and queues are visible to the roster.',
                        'href' => '#site-access-matrix',
                    ],
                ];

                if ($canViewExternalIssueReadiness && $externalIssueReadiness) {
                    $accountMapItems[] = [
                        'label' => 'External issue readiness',
                        'detail' => 'Provider routing health for ticket handoff.',
                        'href' => '#external-issue-readiness-heading',
                    ];
                }

                $accountMapItems[] = [
                    'label' => 'Account activity',
                    'detail' => 'Recent account access, roster, and support-scope changes.',
                    'href' => '#account-activity-heading',
                ];

                if ($canCreateAgents) {
                    $accountMapItems[] = [
                        'label' => 'Add agent',
                        'detail' => 'Invite a teammate with a generated temporary password.',
                        'href' => '#add-agent-heading',
                    ];
                }

                if ($canViewAlertDelivery && $agentAlertReadinessSummary) {
                    $accountMapItems[] = [
                        'label' => 'Team alert readiness',
                        'detail' => 'Notification delivery posture across active agents.',
                        'href' => '#team-alert-readiness-heading',
                    ];
                }

                $accountMapItems[] = [
                    'label' => 'Agents',
                    'detail' => 'Roster, role, support scope, workload, and delivery state.',
                    'href' => '#agents',
                ];
            @endphp

            <section class="section" aria-labelledby="account-map-heading">
                <div class="section-header">
                    <div>
                        <h2 id="account-map-heading">Account map</h2>
                        <p class="lede">Jump to the account sections available to your role.</p>
                    </div>
                    <span class="lede">{{ count($accountMapItems) }} {{ \Illuminate\Support\Str::plural('section', count($accountMapItems)) }}</span>
                </div>
                <div class="management-list">
                    @foreach ($accountMapItems as $accountMapItem)
                        <a class="management-link" href="{{ $accountMapItem['href'] }}">
                            <span>
                                <strong>{{ $accountMapItem['label'] }}</strong>
                                <span class="lede">{{ $accountMapItem['detail'] }}</span>
                            </span>
                            <span class="management-action">Open</span>
                        </a>
                    @endforeach
                </div>
            </section>

            <section class="section" aria-labelledby="account-context-heading">
                <div class="section-header">
                    <h2 id="account-context-heading">{{ $account->name }}</h2>
                    <span class="lede">Account boundary</span>
                </div>
                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Your role</span>
                        <span class="meta-value">{{ $roleLabels[$agent->account_role?->value] ?? 'Agent' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Sites</span>
                        <span class="meta-value">{{ $siteCount }} {{ \Illuminate\Support\Str::plural('site', $siteCount) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Visible to you</span>
                        <span class="meta-value">{{ $visibleSiteCount }} {{ \Illuminate\Support\Str::plural('site', $visibleSiteCount) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Support assignments</span>
                        <span class="meta-value">{{ $supportAssignmentCount }} {{ \Illuminate\Support\Str::plural('support assignment', $supportAssignmentCount) }}</span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="role-boundary-heading">
                <div class="section-header">
                    <h2 id="role-boundary-heading">Role boundary</h2>
                    <span class="lede">{{ $canManageRoles ? 'Owner controls enabled' : 'Read-only for your role' }}</span>
                </div>
                <div class="notice-copy">
                    <p>Account roles describe authority. Site access still decides which support queues an agent can work.</p>
                    <p>Role changes are limited to account owners. Owners cannot change their own role here, and every role change is audited.</p>
                    <p>Owners and admins can suspend access without deleting account history. Admins can only suspend agents; owners can manage any other same-account user.</p>
                </div>
            </section>

            <section id="site-access-matrix" class="section" aria-labelledby="site-access-matrix-heading">
                <div class="section-header">
                    <h2 id="site-access-matrix-heading">Site access matrix</h2>
                    <span class="lede">{{ $visibleSites->count() }} visible {{ \Illuminate\Support\Str::plural('site', $visibleSites->count()) }}</span>
                </div>

                @if ($visibleSites->isEmpty())
                    <p class="empty">No support sites are visible to your account yet.</p>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Site</th>
                                    <th scope="col">Access model</th>
                                    <th scope="col">Active support agents</th>
                                    <th scope="col">Manage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($visibleSites as $site)
                                    @php
                                        $assignedAgents = $site->supportAgents;
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $site->name }}</strong>
                                            <span class="lede">{{ $site->domain ?? 'Domain not set' }}</span>
                                        </td>
                                        <td>
                                            @if ($assignedAgents->isEmpty())
                                                Account-wide fallback
                                            @else
                                                Explicit access
                                            @endif
                                        </td>
                                        <td>
                                            @if ($assignedAgents->isEmpty())
                                                <strong>All active account agents</strong>
                                                <span class="lede">{{ $activeAgentCount }} eligible until assignments are saved</span>
                                            @else
                                                <strong>{{ $assignedAgents->count() }} assigned active {{ \Illuminate\Support\Str::plural('agent', $assignedAgents->count()) }}</strong>
                                                <span class="lede">
                                                    {{ $assignedAgents
                                                        ->map(fn ($supportAgent) => $supportAgent->name.' ('.($roleLabels[$supportAgent->account_role?->value] ?? 'Agent').')')
                                                        ->join(', ') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <a class="text-link" href="{{ route('dashboard.sites.show', $site) }}">Manage access</a>
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
                            <h2 id="external-issue-readiness-heading">External issue readiness</h2>
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
                                        {{ ucfirst($metric['tone']) }}
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
                        <p class="empty">No external issue projects are mapped yet.</p>
                    @else
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col">Site</th>
                                        <th scope="col">Provider</th>
                                        <th scope="col">Project</th>
                                        <th scope="col">Capabilities</th>
                                        <th scope="col">External issue handoff</th>
                                        <th scope="col">Manage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($externalIssueReadiness['projects'] as $project)
                                        <tr>
                                            <td>
                                                <strong>{{ $project['site'] }}</strong>
                                                <span class="lede">{{ $project['enabled'] ? 'Connection enabled' : 'Connection disabled' }}</span>
                                            </td>
                                            <td>
                                                <strong>{{ $project['connection'] }}</strong>
                                                <span class="lede">{{ $project['provider'] }}</span>
                                            </td>
                                            <td>
                                                <strong>{{ $project['project_key'] }}</strong>
                                                @if ($project['project_name'])
                                                    <span class="lede">{{ $project['project_name'] }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @forelse ($project['capabilities'] as $capability)
                                                    <span>{{ $capability }}</span>@if (! $loop->last)<br>@endif
                                                @empty
                                                    <span>Link only</span>
                                                @endforelse
                                            </td>
                                            <td>
                                                <span class="readiness-status" data-status="{{ $project['handoff']['tone'] }}">
                                                    {{ $project['handoff']['label'] }}
                                                </span>
                                                <span class="lede">{{ $project['handoff']['detail'] }}</span>
                                            </td>
                                            <td>
                                                <a class="text-link" href="{{ $project['href'] }}">Manage routing</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if ($externalIssueReadiness['recent_failures']->isEmpty())
                        <p class="empty">No recent external sync failures for this account.</p>
                    @else
                        <div class="timeline-list">
                            @foreach ($externalIssueReadiness['recent_failures'] as $failure)
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
            @endif

            <section class="section" aria-labelledby="account-activity-heading">
                <div class="section-header">
                    <h2 id="account-activity-heading">Recent account activity</h2>
                    <div class="section-actions">
                        <span class="lede">{{ $accountActivity->count() }} shown</span>
                        @if ($canViewAudit)
                            <a class="button secondary" href="{{ route('dashboard.account.audit.index') }}">View audit log</a>
                        @endif
                    </div>
                </div>
                @if ($accountActivity->isEmpty())
                    <p class="empty">No account activity yet.</p>
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
                                        <span>{{ $activity['actor'] }}</span>
                                        <span>{{ $activity['subject'] }}</span>
                                        <span>Account access</span>
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
                        <h2 id="add-agent-heading">Add agent</h2>
                        <span class="lede">New agents start with the Agent role</span>
                    </div>
                    <form class="section-form" method="POST" action="{{ route('dashboard.account.agents.store') }}">
                        @csrf
                        <div class="field">
                            <label for="agent-name">Name</label>
                            <input id="agent-name" name="name" value="{{ old('name') }}" autocomplete="name" required>
                            @error('name')
                                <p class="field-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="field">
                            <label for="agent-email">Email</label>
                            <input id="agent-email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
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
                            <span>Email the welcome message and temporary password</span>
                        </label>
                        @error('send_welcome_email')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                        <p class="field-help">A temporary password will be generated. Site access follows the current account-wide fallback until you scope agents on each site.</p>
                        <p class="field-help">Use the email option after outbound mail is configured. The password is still shown once here as a fallback.</p>
                        <button class="button" type="submit">Create agent</button>
                    </form>
                </section>
            @endif

            @if ($canViewAlertDelivery && $agentAlertReadinessSummary)
                <section class="section" aria-labelledby="team-alert-readiness-heading">
                    <div class="section-header">
                        <div>
                            <h2 id="team-alert-readiness-heading">Team alert readiness</h2>
                            <p class="lede">{{ $agentAlertReadinessSummary['detail'] }}</p>
                        </div>
                        <div class="section-actions">
                            <span class="readiness-status" data-status="{{ $agentAlertReadinessSummary['status'] }}">
                                {{ $agentAlertReadinessSummary['label'] }}
                            </span>
                            <a class="button secondary" href="{{ route('dashboard.readiness.show') }}">Readiness</a>
                        </div>
                    </div>
                    <div class="meta-grid readiness-summary-grid">
                        @foreach ($agentAlertReadinessSummary['metrics'] as $metric)
                            <div class="meta-item">
                                <span class="meta-label">{{ $metric['label'] }}</span>
                                <span class="meta-value">{{ $metric['value'] }}</span>
                                <span class="lede">
                                    <span class="readiness-status" data-status="{{ $metric['tone'] }}">
                                        {{ ucfirst($metric['tone']) }}
                                    </span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section id="agents" class="section" aria-labelledby="agents-heading">
                <div class="section-header">
                    <h2 id="agents-heading">Agents</h2>
                    <span class="lede">{{ $agents->count() }} total</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Agent</th>
                                <th scope="col">Status</th>
                                <th scope="col">Role</th>
                                @if ($canViewAlertDelivery)
                                    <th scope="col">Alert delivery</th>
                                @endif
                                @if ($canManageRoles)
                                    <th scope="col">Manage role</th>
                                @endif
                                @if ($canManageAgentAccess)
                                    <th scope="col">Manage access</th>
                                @endif
                                <th scope="col">Support scope</th>
                                <th scope="col">Workload</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($agents as $accountAgent)
                                @php
                                    $visibleOpenConversationCount = (int) $accountAgent->visible_open_conversations_count;
                                    $visibleOpenTicketCount = (int) $accountAgent->visible_open_tickets_count;
                                    $hasVisibleOpenWork = $visibleOpenConversationCount > 0 || $visibleOpenTicketCount > 0;
                                    $canManageThisAgentAccess = $canManageAgentAccess
                                        && ! $accountAgent->is($agent)
                                        && ($agent->isOwner() || $accountAgent->account_role === \App\Enums\AccountRole::Agent);
                                    $supportScope = $agentSupportScopes[$accountAgent->id] ?? [
                                        'explicitSites' => collect(),
                                        'fallbackSites' => collect(),
                                    ];
                                    $alertDeliverySummary = $agentAlertDeliverySummaries[$accountAgent->id] ?? [
                                        'primary' => 'Unknown',
                                        'lines' => [
                                            ['text' => 'Alert delivery state is unavailable.'],
                                        ],
                                    ];
                                    $explicitSites = $supportScope['explicitSites'];
                                    $fallbackSites = $supportScope['fallbackSites'];
                                    $siteScopePreview = function ($sites): string {
                                        $names = $sites->pluck('name');
                                        $preview = $names->take(2)->join(', ');

                                        if ($names->count() > 2) {
                                            $preview .= ' + '.($names->count() - 2).' more';
                                        }

                                        return $preview;
                                    };
                                @endphp
                                <tr>
                                    <td>
                                        <strong>{{ $accountAgent->name }}</strong>
                                        <span class="lede">{{ $accountAgent->email }}</span>
                                    </td>
                                    <td>{{ $accountAgent->isDeactivated() ? 'Deactivated' : 'Active' }}</td>
                                    <td>{{ $roleLabels[$accountAgent->account_role?->value] ?? 'Agent' }}</td>
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
                                                <span class="lede">Current user</span>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.agents.role.update', $accountAgent) }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <label class="sr-only" for="account-role-{{ $accountAgent->id }}">Manage role for {{ $accountAgent->name }}</label>
                                                    <select id="account-role-{{ $accountAgent->id }}" name="account_role">
                                                        @foreach ($roleOptions as $roleValue => $roleLabel)
                                                            <option value="{{ $roleValue }}" @selected($accountAgent->account_role?->value === $roleValue)>{{ $roleLabel }}</option>
                                                        @endforeach
                                                    </select>
                                                    <button class="button secondary" type="submit">Save role</button>
                                                </form>
                                            @endif
                                        </td>
                                    @endif
                                    @if ($canManageAgentAccess)
                                        <td>
                                            @if ($accountAgent->is($agent))
                                                <span class="lede">Current user</span>
                                            @elseif (! $canManageThisAgentAccess)
                                                <span class="lede">Owner only</span>
                                            @elseif ($accountAgent->isDeactivated())
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.agents.reactivate', $accountAgent) }}">
                                                    @csrf
                                                    <button class="button secondary" type="submit">Reactivate</button>
                                                </form>
                                            @else
                                                <form class="compact-form" method="POST" action="{{ route('dashboard.account.agents.deactivate', $accountAgent) }}">
                                                    @csrf
                                                    <button class="button danger" type="submit">Deactivate</button>
                                                </form>
                                            @endif
                                        </td>
                                    @endif
                                    <td>
                                        @if ($explicitSites->isEmpty() && $fallbackSites->isEmpty())
                                            <span class="lede">No active support scope</span>
                                        @else
                                            @if ($explicitSites->isNotEmpty())
                                                <strong>{{ $explicitSites->count() }} explicit {{ \Illuminate\Support\Str::plural('site', $explicitSites->count()) }}</strong>
                                                <span class="lede">Explicit: {{ $siteScopePreview($explicitSites) }}</span>
                                            @endif
                                            @if ($fallbackSites->isNotEmpty())
                                                <strong>{{ $fallbackSites->count() }} fallback {{ \Illuminate\Support\Str::plural('site', $fallbackSites->count()) }}</strong>
                                                <span class="lede">Fallback: {{ $siteScopePreview($fallbackSites) }}</span>
                                            @endif
                                            <a class="table-note text-link" href="#site-access-matrix">Review site access</a>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($hasVisibleOpenWork)
                                            @if ($visibleOpenConversationCount > 0)
                                                <strong>{{ $visibleOpenConversationCount }} {{ \Illuminate\Support\Str::plural('open conversation', $visibleOpenConversationCount) }}</strong>
                                            @endif
                                            @if ($visibleOpenTicketCount > 0)
                                                <span class="lede">{{ $visibleOpenTicketCount }} {{ \Illuminate\Support\Str::plural('open ticket', $visibleOpenTicketCount) }}</span>
                                            @endif
                                        @else
                                            <span class="lede">No assigned open work</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
</x-layouts.app>
