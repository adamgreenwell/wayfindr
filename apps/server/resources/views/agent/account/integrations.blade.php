<x-layouts.app title="Integrations" :agent="$agent" :account="$account">
            @php
                $selectedCapabilities = collect(old('capabilities', ['create_issue']))
                    ->filter(fn ($value) => is_string($value))
                    ->values()
                    ->all();
                $mappedSites = $sites->filter(fn ($site) => $site->externalIssueProjects->isNotEmpty());
            @endphp

            <x-page-header
                title="Integrations"
                subtitle="Account-wide provider connections and where each site sends external issues."
                :back-href="route('dashboard.account.show')"
                back-label="Back to account"
            />

            @if (session('status'))
                <p class="status-message">{{ session('status') }}</p>
            @endif

            <section class="section" aria-labelledby="provider-connections-heading">
                <div class="section-header">
                    <h2 id="provider-connections-heading">Provider connections</h2>
                    <span class="lede">{{ $providerConnections->count() }} {{ Str::plural('connection', $providerConnections->count()) }} · account-owned</span>
                </div>

                @unless ($canManageIntegrations)
                    <p class="lede realtime-note">Provider connections are managed by an account admin. Ask an admin to add or change connections; every agent can use them from tickets once configured.</p>
                @endunless

                @if ($canManageIntegrations)
                    <div class="notice-copy notice-copy-bordered" aria-labelledby="integration-setup-order-heading">
                        <p><strong id="integration-setup-order-heading">Connection setup order</strong></p>
                        <div class="notice-list">
                            <p><strong>1. Save the provider connection first.</strong> Wayfindr creates its unique inbound webhook URL only after the connection exists.</p>
                            <p><strong>2. Copy the generated webhook URL.</strong> It appears with the saved connection above this form.</p>
                            <p><strong>3. Configure the provider webhook.</strong> Paste that URL into GitHub, GitLab, or Jira, reuse the same webhook secret, and select issue-state and issue-comment events.</p>
                            <p><strong>4. Map a site to a project.</strong> Return here and open the site under Site project mappings so tickets know which repository or project should receive them.</p>
                        </div>
                        <p>Providers without an inbound webhook receiver can still be used for the outbound capabilities they support.</p>
                    </div>
                @endif

                @if ($providerConnections->isEmpty())
                    <p class="empty">
                        No provider connections yet.
                        @if ($canManageIntegrations)
                            Connect GitHub or GitLab below with an API token to let agents hand tickets off as external issues.
                        @endif
                    </p>
                @else
                    <div class="management-list">
                        @foreach ($providerConnections as $connection)
                            <div class="management-link">
                                <span>
                                    <strong>{{ $connection->name }}</strong>
                                    <span class="lede">
                                        {{ $connection->providerLabel() }}
                                        @if ($connection->base_url)
                                            · {{ $connection->base_url }}
                                        @endif
                                        @if ($connection->capabilityLabels() !== [])
                                            · {{ implode(', ', $connection->capabilityLabels()) }}
                                        @endif
                                    </span>
                                </span>
                                <span class="management-action">{{ $connection->is_enabled ? 'Enabled' : 'Disabled' }}</span>
                            </div>
                            @if ($canManageIntegrations)
                                <div class="notice-copy notice-copy-bordered">
                                    <p><strong>Connection capabilities</strong></p>
                                    <p class="lede">Choose what agents may send through this saved connection. Inbound signed webhooks are authenticated separately by the shared secret.</p>
                                    <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.capabilities.update', $connection) }}">
                                        @csrf
                                        @method('PUT')
                                        <div class="notice-list" aria-label="{{ $connection->providerLabel() }} capabilities">
                                            @foreach ($externalIssueCapabilities as $value => $label)
                                                <label class="check-row" for="connection_{{ $connection->id }}_capability_{{ $value }}">
                                                    <input
                                                        id="connection_{{ $connection->id }}_capability_{{ $value }}"
                                                        name="capabilities[]"
                                                        type="checkbox"
                                                        value="{{ $value }}"
                                                        @checked($connection->hasCapability($value))
                                                    >
                                                    <span>Provider can {{ strtolower($label) }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <button class="button secondary" type="submit">Update capabilities</button>
                                    </form>
                                </div>
                            @endif
                            @if ($connection->inboundWebhookUrl() && $connection->is_enabled)
                                <div class="notice-copy notice-copy-bordered">
                                    @if ($connection->hasWebhookSecret() && $connection->hasVerifiedInboundWebhook())
                                        <p class="lede"><strong>Inbound sync verified.</strong> Wayfindr accepted a signed provider delivery {{ $connection->last_checked_at->diffForHumans() }}.</p>
                                        <p class="lede">Latest verified event: <code>{{ data_get($connection->settings, 'inbound_webhook.event', 'unknown') }}</code> · HTTP {{ data_get($connection->settings, 'inbound_webhook.status_code', 'unknown') }}</p>
                                    @elseif ($connection->hasWebhookSecret())
                                        <p class="lede"><strong>Inbound sync configured, not verified.</strong> A secret is saved, but Wayfindr has not accepted a signed provider delivery yet.</p>
                                    @else
                                        <p class="lede"><strong>Inbound sync not configured.</strong> Set a webhook secret on this connection and point the provider at the URL below to sync issue state back.</p>
                                    @endif
                                    @if ($canManageIntegrations)
                                        <p class="lede"><strong>Generated webhook URL</strong></p>
                                        <p class="lede"><code>{{ $connection->inboundWebhookUrl() }}</code></p>
                                        <div class="notice-list" aria-label="{{ $connection->providerLabel() }} webhook settings">
                                            <p><strong>Provider destination:</strong> Paste the generated URL into this connection's webhook settings.</p>
                                            @switch($connection->provider)
                                                @case('github')
                                                    <p><strong>GitHub settings:</strong> Use <code>application/json</code>, keep SSL verification enabled, and select the individual <strong>Issues</strong> and <strong>Issue comments</strong> events.</p>
                                                    @break
                                                @case('gitlab')
                                                    <p><strong>GitLab settings:</strong> Use the generated URL, place the same value in Secret token, and enable <strong>Issues events</strong> and <strong>Comments</strong>.</p>
                                                    @break
                                                @case('jira')
                                                    <p><strong>Jira settings:</strong> Use the generated URL and the same secret, then subscribe to issue state changes and comment-created events.</p>
                                                    @break
                                            @endswitch
                                            <p><strong>Shared secret:</strong> The secret must match in Wayfindr and the provider. If you replace it here, replace it there too.</p>
                                        </div>
                                        <form class="section-form" method="POST" action="{{ route('dashboard.external-issue-provider-connections.webhook-secret.update', $connection) }}">
                                            @csrf
                                            @method('PUT')
                                            <div class="field">
                                                <label for="webhook_secret_{{ $connection->id }}">{{ $connection->hasWebhookSecret() ? 'Replace webhook secret' : 'Set webhook secret' }}</label>
                                                <input id="webhook_secret_{{ $connection->id }}" name="webhook_secret" type="password" value="" autocomplete="new-password">
                                                @error('webhook_secret')
                                                    <p class="field-error">{{ $message }}</p>
                                                @enderror
                                            </div>
                                            <button class="button secondary" type="submit">{{ $connection->hasWebhookSecret() ? 'Update secret' : 'Enable inbound sync' }}</button>
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
                            <strong>Add provider connection</strong>
                            <span class="lede">Available to every site in this account</span>
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

                        <div class="field">
                            <label for="webhook_secret">Inbound webhook secret</label>
                            <input id="webhook_secret" name="webhook_secret" type="password" value="" autocomplete="new-password">
                            <span class="lede">Optional now. Save this connection first to generate its webhook URL. You can enter a secret now and reuse it at the provider, or leave this blank and set both sides after the URL appears. GitHub signs it (X-Hub-Signature-256), Jira signs it (X-Hub-Signature), and GitLab sends it as an X-Gitlab-Token header.</span>
                            @error('webhook_secret')
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
                @endif
            </section>

            <section class="section" aria-labelledby="site-project-mappings-heading">
                <div class="section-header">
                    <h2 id="site-project-mappings-heading">Site project mappings</h2>
                    <span class="lede">{{ $mappedSites->count() }} of {{ $sites->count() }} {{ Str::plural('site', $sites->count()) }} mapped</span>
                </div>

                <p class="lede">Project mappings are site-scoped: each site chooses which external project its tickets hand off to. Map projects from the site's own page.</p>

                @if ($sites->isEmpty())
                    <p class="empty">No sites yet.</p>
                @else
                    <div class="management-list">
                        @foreach ($sites as $site)
                            <a class="management-link" href="{{ route('dashboard.sites.show', $site) }}">
                                <span>
                                    <strong>{{ $site->name }}</strong>
                                    <span class="lede">
                                        @if ($site->externalIssueProjects->isEmpty())
                                            No external projects mapped yet.
                                        @else
                                            @foreach ($site->externalIssueProjects as $project)
                                                {{ $project->providerConnection?->name ?? $project->providerLabel() }} → {{ $project->project_key }}@if (! $loop->last), @endif
                                            @endforeach
                                        @endif
                                    </span>
                                </span>
                                <span class="management-action">{{ $site->externalIssueProjects->isEmpty() ? 'Map a project' : 'Manage' }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
</x-layouts.app>
