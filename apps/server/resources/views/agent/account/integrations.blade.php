<x-layouts.account :title="__('integrations.title')">
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
            {{-- The order matters once, before the first connection exists. After
                 that it is reference, and left open it put the same four steps
                 above the data on every visit. --}}
            <div class="notice-copy notice-copy-bordered">
                <x-details-disclosure :summary="__('integrations.connections.setup.heading')" :open="$providerConnections->isEmpty()">
                    <div class="notice-list">
                        <p><strong>{{ __('integrations.connections.setup.save_title') }}</strong> {{ __('integrations.connections.setup.save_body') }}</p>
                        <p><strong>{{ __('integrations.connections.setup.copy_title') }}</strong> {{ __('integrations.connections.setup.copy_body') }}</p>
                        <p><strong>{{ __('integrations.connections.setup.configure_title') }}</strong> {!! __('integrations.connections.setup.configure_body', ['providers' => $setupProviders]) !!}</p>
                        <p><strong>{{ __('integrations.connections.setup.map_title') }}</strong> {{ __('integrations.connections.setup.map_body') }}</p>
                    </div>
                    <p>{{ __('integrations.connections.setup.outbound_only') }}</p>
                </x-details-disclosure>
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

                        $inboundSync = $connection->inboundWebhookUrl() && $connection->is_enabled;
                        // The connection the last write was for: named by the
                        // hidden field of a form that failed validation, or
                        // flashed by the controller after one that saved.
                        $connectionFocused = (string) (old('connection_id') ?? session('integrations_connection')) === (string) $connection->id;
                        // Closed by default: a connection's settings are
                        // reference once it works. Open for the one just
                        // written, and for one half set up -- a secret saved
                        // here that no signed delivery has proved yet.
                        $settingsOpen = $connectionFocused
                            || ($inboundSync && $connection->hasWebhookSecret() && ! $connection->hasVerifiedInboundWebhook());
                    @endphp

                    {{-- One connection: its row, its sync status, and its settings,
                         with the divider under all three rather than between the
                         row and the rest. The id is what a write returns to. --}}
                    <div class="connection-item" id="connection-{{ $connection->id }}">
                        <div class="management-link">
                            <span>
                                <strong id="connection_{{ $connection->id }}_name" lang="">{{ $connection->name }}</strong>
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
                            {{-- A state, not a destination. `.management-action` is the
                                 accent-coloured verb of a row that navigates, and this
                                 row does not. --}}
                            <span class="readiness-status" data-status="{{ $connection->is_enabled ? 'ready' : 'manual' }}">{{ $connection->is_enabled
                                ? __('integrations.connections.enabled')
                                : __('integrations.connections.disabled') }}</span>
                        </div>

                        {{-- Status, for every reader, above the settings that change it. --}}
                        @if ($inboundSync)
                            <div class="connection-sync-status">
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
                            </div>
                        @endif

                        @if ($canManageIntegrations)
                            <x-details-disclosure class="connection-settings" :open="$settingsOpen">
                                <x-slot:summary>{{ $inboundSync ? __('integrations.connections.settings') : __('integrations.connections.settings_capabilities') }} <span class="sr-only" lang="">{{ $connection->name }}</span></x-slot:summary>
                                <div class="notice-copy notice-copy-bordered">
                                    <p><strong id="connection_{{ $connection->id }}_capabilities_heading">{{ __('integrations.capabilities.heading') }}</strong></p>
                                    <p class="lede">{{ __('integrations.capabilities.help') }}</p>
                                    <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.capabilities.update', $connection) }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="connection_id" value="{{ $connection->id }}">
                                        <div class="notice-list" aria-labelledby="connection_{{ $connection->id }}_capabilities_heading connection_{{ $connection->id }}_name">
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
                                        @if ($connectionFocused && ($errors->has('capabilities') || $errors->has('capabilities.*')))
                                            <p class="field-error">{{ $errors->first('capabilities') ?: $errors->first('capabilities.*') }}</p>
                                        @endif
                                        <button class="button secondary" type="submit">{{ __('integrations.capabilities.update') }}</button>
                                    </form>
                                </div>

                                @if ($inboundSync)
                                    <div class="notice-copy notice-copy-bordered">
                                        <p class="lede"><strong>{{ __('integrations.webhook.generated_url') }}</strong></p>
                                        <p class="lede"><code lang="">{{ $connection->inboundWebhookUrl() }}</code></p>
                                        {{-- Instructions for the provider's side, needed until a
                                             signed delivery proves that side is configured and
                                             reference afterwards. The URL above and the secret
                                             form below stay outside it: they are the values,
                                             not the instructions. --}}
                                        <x-details-disclosure :open="! ($connection->hasWebhookSecret() && $connection->hasVerifiedInboundWebhook())">
                                            <x-slot:summary><span id="connection_{{ $connection->id }}_webhook_settings_label">{{ __('integrations.webhook.settings_aria') }}</span></x-slot:summary>
                                            <div class="notice-list" aria-labelledby="connection_{{ $connection->id }}_webhook_settings_label connection_{{ $connection->id }}_name">
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
                                        </x-details-disclosure>
                                        <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="connection_id" value="{{ $connection->id }}">
                                            <div class="field">
                                                <label for="webhook_secret_{{ $connection->id }}">{{ $connection->hasWebhookSecret()
                                                    ? __('integrations.webhook.replace_secret')
                                                    : __('integrations.webhook.set_secret') }}</label>
                                                <input id="webhook_secret_{{ $connection->id }}" name="webhook_secret" type="password" value="" autocomplete="new-password">
                                                @if ($connectionFocused && $errors->has('webhook_secret'))
                                                    <p class="field-error">{{ $errors->first('webhook_secret') }}</p>
                                                @endif
                                            </div>
                                            <button class="button secondary" type="submit">{{ $connection->hasWebhookSecret()
                                                ? __('integrations.webhook.update_secret')
                                                : __('integrations.webhook.enable') }}</button>
                                        </form>
                                    </div>
                                @endif
                            </x-details-disclosure>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($canManageIntegrations)
            <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.store') }}" aria-labelledby="integration-create-heading">
                @csrf
                <input type="hidden" name="return_to" value="integrations">

                {{-- A heading element, so a reader moving by headings reaches the
                     form. An h3 because it is part of Provider connections, not a
                     section beside it. --}}
                <div class="section-header">
                    <h3 id="integration-create-heading">{{ __('integrations.create.heading') }}</h3>
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
                    <input id="provider_connection_name" name="name" type="text" value="{{ old('name') }}" @if (filled(old('name'))) lang="" @endif placeholder="{{ __('integrations.create.name_placeholder') }}">
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
                    {{-- Only this form's own failure: a connection's secret form
                         fails under the same key and names its connection. --}}
                    @if (old('connection_id') === null)
                        @error('webhook_secret')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    @endif
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

                @if (old('connection_id') === null)
                    @error('capabilities')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                    @error('capabilities.*')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                @endif

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
</x-layouts.account>
