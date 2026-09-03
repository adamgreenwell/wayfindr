<x-layouts.app :title="__('integrations.title')" :agent="$agent" :account="$account">
    @php
        $selectedCapabilities = collect(old('capabilities', ['create_issue']))
            ->filter(fn ($value) => is_string($value))
            ->values()
            ->all();
        $mappedSites = $sites->filter(fn ($site) => $site->externalIssueProjects->isNotEmpty());

        // Provider brands, wire values, and account-owned names are not words
        // from the Wayfindr catalogue. Escape before inserting their markup
        // into a translated sentence, and let assistive technology know their
        // language is unknown rather than inheriting the page's German or
        // Italian by accident.
        $unknownLanguage = static fn (mixed $value, string $element = 'span'): string => '<'.$element.' lang="">'.e((string) $value).'</'.$element.'>';
        $providerHtml = static fn (array $provider): string => $provider['language'] === ''
            ? $unknownLanguage($provider['label'])
            : e($provider['label']);
        $setupProviders = __('integrations.providers.setup_list', [
            'github' => $unknownLanguage('GitHub'),
            'gitlab' => $unknownLanguage('GitLab'),
            'jira' => $unknownLanguage('Jira'),
        ]);
    @endphp

    <x-page-header
        :title="__('integrations.title')"
        :subtitle="__('integrations.subtitle')"
        :back-href="route('dashboard.account.show')"
        :back-label="__('integrations.back')"
    />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="provider-connections-heading">
        <div class="section-header">
            <h2 id="provider-connections-heading">{{ __('integrations.connections.heading') }}</h2>
            <span class="lede">
                {{ trans_choice('integrations.connections.count', $providerConnections->count(), [
                    'count' => \App\Support\ReaderNumber::count($providerConnections->count()),
                ]) }} · {{ __('integrations.connections.account_owned') }}
            </span>
        </div>

        @unless ($canManageIntegrations)
            <p class="lede realtime-note">{{ __('integrations.connections.admin_hint') }}</p>
        @endunless

        @if ($canManageIntegrations)
            <div class="notice-copy notice-copy-bordered" aria-labelledby="integration-setup-order-heading">
                <p><strong id="integration-setup-order-heading">{{ __('integrations.connections.setup.heading') }}</strong></p>
                <div class="notice-list">
                    <p><strong>{{ __('integrations.connections.setup.save_title') }}</strong> {{ __('integrations.connections.setup.save_body') }}</p>
                    <p><strong>{{ __('integrations.connections.setup.copy_title') }}</strong> {{ __('integrations.connections.setup.copy_body') }}</p>
                    <p><strong>{{ __('integrations.connections.setup.configure_title') }}</strong> {!! __('integrations.connections.setup.configure_body', ['providers' => $setupProviders]) !!}</p>
                    <p><strong>{{ __('integrations.connections.setup.map_title') }}</strong> {{ __('integrations.connections.setup.map_body') }}</p>
                </div>
                <p>{{ __('integrations.connections.setup.outbound_only') }}</p>
            </div>
        @endif

        @if ($providerConnections->isEmpty())
            <p class="empty">
                {{ __('integrations.connections.empty') }}
                @if ($canManageIntegrations)
                    {!! __('integrations.connections.empty_admin', ['providers' => $setupProviders]) !!}
                @endif
            </p>
        @else
            <div class="management-list">
                @foreach ($providerConnections as $connection)
                    @php
                        $provider = $externalIssueProviders[$connection->provider] ?? [
                            'label' => __('integrations.providers.external_tracker'),
                            'language' => null,
                        ];
                        $capabilityLabels = collect($externalIssueCapabilities)
                            ->filter(fn (array $capability, string $value): bool => $connection->hasCapability($value))
                            ->pluck('label')
                            ->all();
                    @endphp

                    <div class="management-link">
                        <span>
                            <strong lang="">{{ $connection->name }}</strong>
                            <span class="lede">
                                {!! $providerHtml($provider) !!}
                                @if ($connection->base_url)
                                    · <span lang="">{{ $connection->base_url }}</span>
                                @endif
                                @if ($capabilityLabels !== [])
                                    · {{ implode(', ', $capabilityLabels) }}
                                @endif
                            </span>
                        </span>
                        <span class="management-action">{{ $connection->is_enabled
                            ? __('integrations.connections.enabled')
                            : __('integrations.connections.disabled') }}</span>
                    </div>

                    @if ($canManageIntegrations)
                        <div class="notice-copy notice-copy-bordered">
                            <p><strong>{{ __('integrations.capabilities.heading') }}</strong></p>
                            <p class="lede">{{ __('integrations.capabilities.help') }}</p>
                            <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.capabilities.update', $connection) }}">
                                @csrf
                                @method('PUT')
                                <div class="notice-list" aria-label="{{ __('integrations.capabilities.aria', ['connection' => $connection->name]) }}">
                                    @foreach ($externalIssueCapabilities as $value => $capability)
                                        <label class="check-row" for="connection_{{ $connection->id }}_capability_{{ $value }}">
                                            <input
                                                id="connection_{{ $connection->id }}_capability_{{ $value }}"
                                                name="capabilities[]"
                                                type="checkbox"
                                                value="{{ $value }}"
                                                @checked($connection->hasCapability($value))
                                            >
                                            <span>{{ $capability['permission'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <button class="button secondary" type="submit">{{ __('integrations.capabilities.update') }}</button>
                            </form>
                        </div>
                    @endif

                    @if ($connection->inboundWebhookUrl() && $connection->is_enabled)
                        <div class="notice-copy notice-copy-bordered">
                            @if ($connection->hasWebhookSecret() && $connection->hasVerifiedInboundWebhook())
                                <p class="lede"><strong>{{ __('integrations.webhook.verified_title') }}</strong> {{ __('integrations.webhook.verified_body', ['elapsed' => $connection->last_checked_at->diffForHumans()]) }}</p>
                                @php
                                    $event = data_get($connection->settings, 'inbound_webhook.event');
                                    $statusCode = data_get($connection->settings, 'inbound_webhook.status_code');
                                @endphp
                                <p class="lede">{!! __('integrations.webhook.latest', [
                                    'event' => is_scalar($event) && (string) $event !== ''
                                        ? $unknownLanguage($event, 'code')
                                        : e(__('integrations.webhook.unknown')),
                                    'status' => is_scalar($statusCode) && (string) $statusCode !== ''
                                        ? $unknownLanguage($statusCode)
                                        : e(__('integrations.webhook.unknown')),
                                ]) !!}</p>
                            @elseif ($connection->hasWebhookSecret())
                                <p class="lede"><strong>{{ __('integrations.webhook.configured_title') }}</strong> {{ __('integrations.webhook.configured_body') }}</p>
                            @else
                                <p class="lede"><strong>{{ __('integrations.webhook.missing_title') }}</strong> {{ __('integrations.webhook.missing_body') }}</p>
                            @endif

                            @if ($canManageIntegrations)
                                <p class="lede"><strong>{{ __('integrations.webhook.generated_url') }}</strong></p>
                                <p class="lede"><code lang="">{{ $connection->inboundWebhookUrl() }}</code></p>
                                <div class="notice-list" aria-label="{{ __('integrations.webhook.settings_aria', ['connection' => $connection->name]) }}">
                                    <p><strong>{{ __('integrations.webhook.provider_destination_title') }}</strong> {{ __('integrations.webhook.provider_destination_body') }}</p>
                                    @switch($connection->provider)
                                        @case('github')
                                            <p><strong>{{ __('integrations.webhook.github_title') }}</strong> {!! __('integrations.webhook.github_body', [
                                                'content_type' => $unknownLanguage('application/json', 'code'),
                                                'issues' => $unknownLanguage('Issues', 'strong'),
                                                'comments' => $unknownLanguage('Issue comments', 'strong'),
                                            ]) !!}</p>
                                            @break
                                        @case('gitlab')
                                            <p><strong>{{ __('integrations.webhook.gitlab_title') }}</strong> {!! __('integrations.webhook.gitlab_body', [
                                                'secret_token' => $unknownLanguage('Secret token'),
                                                'issues' => $unknownLanguage('Issues events', 'strong'),
                                                'comments' => $unknownLanguage('Comments', 'strong'),
                                            ]) !!}</p>
                                            @break
                                        @case('jira')
                                            <p><strong>{{ __('integrations.webhook.jira_title') }}</strong> {{ __('integrations.webhook.jira_body') }}</p>
                                            @break
                                    @endswitch
                                    <p><strong>{{ __('integrations.webhook.shared_secret_title') }}</strong> {{ __('integrations.webhook.shared_secret_body') }}</p>
                                </div>
                                <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="field">
                                        <label for="webhook_secret_{{ $connection->id }}">{{ $connection->hasWebhookSecret()
                                            ? __('integrations.webhook.replace_secret')
                                            : __('integrations.webhook.set_secret') }}</label>
                                        <input id="webhook_secret_{{ $connection->id }}" name="webhook_secret" type="password" value="" autocomplete="new-password">
                                        @error('webhook_secret')
                                            <p class="field-error">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <button class="button secondary" type="submit">{{ $connection->hasWebhookSecret()
                                        ? __('integrations.webhook.update_secret')
                                        : __('integrations.webhook.enable') }}</button>
                                </form>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        @if ($canManageIntegrations)
            <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.store') }}">
                @csrf
                <input type="hidden" name="return_to" value="integrations">

                <div class="section-header">
                    <strong>{{ __('integrations.create.heading') }}</strong>
                    <span class="lede">{{ __('integrations.create.available') }}</span>
                </div>

                <div class="field">
                    <label for="provider">{{ __('integrations.create.provider') }}</label>
                    <select id="provider" name="provider">
                        @foreach ($externalIssueProviders as $value => $provider)
                            <option value="{{ $value }}" @if ($provider['language'] === '') lang="" @endif @selected(old('provider', 'github') === $value)>
                                {{ $provider['label'] }}
                            </option>
                        @endforeach
                    </select>
                    @error('provider')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="provider_connection_name">{{ __('integrations.create.name') }}</label>
                    <input id="provider_connection_name" name="name" type="text" lang="" value="{{ old('name') }}" placeholder="{{ __('integrations.create.name_placeholder') }}">
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="base_url">{{ __('integrations.create.base_url') }}</label>
                    <input id="base_url" name="base_url" type="url" lang="" value="{{ old('base_url') }}" placeholder="https://api.github.com">
                    @error('base_url')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="credential_token">{{ __('integrations.create.credential') }}</label>
                    <input id="credential_token" name="credential_token" type="password" value="" autocomplete="new-password">
                    @error('credential_token')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="webhook_secret">{{ __('integrations.create.webhook_secret') }}</label>
                    <input id="webhook_secret" name="webhook_secret" type="password" value="" autocomplete="new-password">
                    <span class="lede">{!! __('integrations.create.webhook_help', [
                        'github' => $unknownLanguage('GitHub'),
                        'github_header' => $unknownLanguage('X-Hub-Signature-256', 'code'),
                        'jira' => $unknownLanguage('Jira'),
                        'jira_header' => $unknownLanguage('X-Hub-Signature', 'code'),
                        'gitlab' => $unknownLanguage('GitLab'),
                        'gitlab_header' => $unknownLanguage('X-Gitlab-Token', 'code'),
                    ]) !!}</span>
                    @error('webhook_secret')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="notice-list">
                    @foreach ($externalIssueCapabilities as $value => $capability)
                        <label class="check-row" for="capability_{{ $value }}">
                            <input
                                id="capability_{{ $value }}"
                                name="capabilities[]"
                                type="checkbox"
                                value="{{ $value }}"
                                @checked(in_array($value, $selectedCapabilities, true))
                            >
                            <span>{{ $capability['permission'] }}</span>
                        </label>
                    @endforeach
                </div>

                @error('capabilities')
                    <p class="field-error">{{ $message }}</p>
                @enderror
                @error('capabilities.*')
                    <p class="field-error">{{ $message }}</p>
                @enderror

                <button class="button" type="submit">{{ __('integrations.create.submit') }}</button>
            </form>
        @endif
    </section>

    <section class="section" aria-labelledby="site-project-mappings-heading">
        <div class="section-header">
            <h2 id="site-project-mappings-heading">{{ __('integrations.mappings.heading') }}</h2>
            <span class="lede">{{ trans_choice('integrations.mappings.count', $sites->count(), [
                'mapped' => \App\Support\ReaderNumber::count($mappedSites->count()),
                'total' => \App\Support\ReaderNumber::count($sites->count()),
            ]) }}</span>
        </div>

        <p class="lede">{{ __('integrations.mappings.help') }}</p>

        @if ($sites->isEmpty())
            <p class="empty">{{ __('integrations.mappings.empty') }}</p>
        @else
            <div class="management-list">
                @foreach ($sites as $site)
                    <a class="management-link" href="{{ route('dashboard.sites.show', $site) }}">
                        <span>
                            <strong lang="">{{ $site->name }}</strong>
                            <span class="lede">
                                @if ($site->externalIssueProjects->isEmpty())
                                    {{ __('integrations.mappings.unmapped') }}
                                @else
                                    @foreach ($site->externalIssueProjects as $project)
                                        @if ($project->providerConnection)
                                            <span lang="">{{ $project->providerConnection->name }}</span>
                                        @else
                                            {{ __('integrations.providers.external_tracker') }}
                                        @endif
                                        → <span lang="">{{ $project->project_key }}</span>@if (! $loop->last), @endif
                                    @endforeach
                                @endif
                            </span>
                        </span>
                        <span class="management-action">{{ $site->externalIssueProjects->isEmpty()
                            ? __('integrations.mappings.map')
                            : __('integrations.mappings.manage') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
