<x-layouts.account :title="__('account.document_title')">
            @php
                $unknownLanguage = static fn (mixed $value, string $element = 'span'): string => '<'.$element.' lang="">'.e((string) $value).'</'.$element.'>';
            @endphp

            <x-page-header :title="__('account.title')" :subtitle="__('account.subtitle')">
                <x-slot:actions>
                    <span class="lede">{{ trans_choice('account.agent_count', $agentCount, ['count' => \App\Support\ReaderNumber::count($agentCount)]) }}</span>
                </x-slot:actions>
            </x-page-header>

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
                    @if ($visibleSiteCount !== $siteCount)
                    <div class="meta-item">
                        <span class="meta-label">{{ __('account.context.visible') }}</span>
                        <span class="meta-value">{{ trans_choice('account.context.site_count', $visibleSiteCount, ['count' => \App\Support\ReaderNumber::count($visibleSiteCount)]) }}</span>
                    </div>
                    @endif
                    <div class="meta-item">
                        <span class="meta-label">{{ __('account.context.assignments') }}</span>
                        <span class="meta-value">{{ trans_choice('account.context.assignment_count', $supportAssignmentCount, ['count' => \App\Support\ReaderNumber::count($supportAssignmentCount)]) }}</span>
                    </div>
                </div>
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
                                @if ($metric['tone'] !== 'ready')
                                <span class="lede">
                                    <span class="readiness-status" data-status="{{ $metric['tone'] }}">
                                        {{ __('account.external.tones.'.$metric['tone']) }}
                                    </span>
                                </span>
                                @endif
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

            {{-- A standing reminder, not account state: it was a full card
                 weighted the same as the roster and the access matrix. Closed
                 by default, one line until someone wants it. --}}
            <x-details-disclosure class="section" :summary="__('account.data_responsibility.heading')">
                <div class="notice-copy">
                    <p>{{ __('account.data_responsibility.message') }}</p>
                    <p>{{ __('account.data_responsibility.guidance') }}</p>
                    <p>
                        <a class="text-link" href="{{ $dataResponsibility['docs_url'] }}" target="_blank" rel="noreferrer">
                            {{ __('account.data_responsibility.docs') }}
                        </a>
                    </p>
                </div>
            </x-details-disclosure>
</x-layouts.account>
