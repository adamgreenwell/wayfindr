<x-layouts.app :title="__('api_tokens.title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('api_tokens.title')" :subtitle="__('api_tokens.subtitle')" :back-href="route('dashboard.account.show')" :back-label="__('api_tokens.back')">
        <x-slot:actions>
            {{-- Usable, not merely un-revoked. A token past its expiry is refused
                 at authentication and labelled Expired in the table below, so
                 counting it as active would contradict the same page. --}}
            <span class="lede">{{ trans_choice('api_tokens.active', $tokens->filter->isUsable()->count(), ['count' => \App\Support\ReaderNumber::count($tokens->filter->isUsable()->count())]) }}</span>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        {{-- A KEY rather than a sentence: the request that redirected and the
             request that renders are different requests, and the agent's
             language is resolved per request. --}}
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    @if ($issuedToken)
        <section class="section" aria-labelledby="api-token-issued-heading">
            <div class="section-header">
                <h2 id="api-token-issued-heading">{{ __('api_tokens.issued.heading') }}</h2>
                <span class="lede">{{ __('api_tokens.issued.once') }}</span>
            </div>
            <div class="notice-copy" data-state="warning">
                <p>{{ __('api_tokens.issued.hashed') }}</p>
                {{-- The credential itself. Not words in any language, and the
                     one string on this page a reader may need to transcribe
                     character by character. --}}
                <p><code lang="">{{ $issuedToken }}</code></p>
                <p>
                    {{-- The header is a WIRE FORMAT, not copy: it is sent
                         verbatim or the request fails. It is passed in rather
                         than translated, and is identical in every language on
                         purpose -- the same reasoning as an IANA zone name. --}}
                    {!! __('api_tokens.issued.send_as', ['header' => '<code lang="">Authorization: Bearer &lt;token&gt;</code>']) !!}
                </p>
            </div>
        </section>
    @endif

    @if ($issuedWebhookSecret)
        <section class="section" aria-labelledby="webhook-secret-issued-heading">
            <div class="section-header">
                <h2 id="webhook-secret-issued-heading">{{ __('outbound_webhooks.issued.heading') }}</h2>
                <span class="lede">{{ __('outbound_webhooks.issued.once') }}</span>
            </div>
            <div class="notice-copy" data-state="warning">
                <p>{{ __('outbound_webhooks.issued.help') }}</p>
                <p><code lang="">{{ $issuedWebhookSecret }}</code></p>
                <p>{!! __('outbound_webhooks.issued.verify', ['header' => '<code lang="">X-Wayfindr-Signature</code>']) !!}</p>
            </div>
        </section>
    @endif

    <section class="section" aria-labelledby="api-token-list-heading">
        <div class="section-header">
            <h2 id="api-token-list-heading">{{ __('api_tokens.list.heading') }}</h2>
            <span class="lede">{{ trans_choice('api_tokens.list.total', $tokens->count(), ['count' => \App\Support\ReaderNumber::count($tokens->count())]) }}</span>
        </div>

        @if ($tokens->isEmpty())
            <div class="notice-copy">
                <p>{{ __('api_tokens.list.empty') }}</p>
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('api_tokens.list.column_name') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_token') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_reaches') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_last_used') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_state') }}</th>
                            <th scope="col">{{ __('api_tokens.list.column_action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tokens as $token)
                            <tr>
                                <td>
                                    {{-- The token's name, the sites it reaches and the
                                         agent who issued it are the account's own words.
                                         `lang=""` is HTML's "unknown": the page around
                                         them is German, these are not. --}}
                                    <strong lang="">{{ $token->name }}</strong>
                                    {{-- Two whole sentences rather than one with an
                                         optional tail. German puts the issuing agent
                                         somewhere English does not, and a sentence
                                         assembled by concatenation cannot move it. --}}
                                    <span class="lede">
                                        @if ($token->createdBy)
                                            {!! __('api_tokens.list.created_by', [
                                                'when' => e($token->created_at->diffForHumans()),
                                                'name' => '<span lang="">'.e($token->createdBy->name).'</span>',
                                            ]) !!}
                                        @else
                                            {{ __('api_tokens.list.created', ['when' => $token->created_at->diffForHumans()]) }}
                                        @endif
                                    </span>
                                </td>
                                <td><code lang="">{{ $token->displayHint() }}</code></td>
                                <td>
                                    @php
                                        // Site access restricts an admin too, so a token can reach sites
                                        // its viewer cannot -- and naming those here would leak exactly
                                        // what site access hides. Named where the viewer supports them,
                                        // acknowledged without names where they do not.
                                        $namedSites = $token->sites->whereIn('id', $visibleSiteIds);
                                        $hiddenSiteCount = $token->sites->count() - $namedSites->count();

                                        // Abilities ARE translated, unlike the header above. The `read`
                                        // slug is what the API takes, but nobody types it -- it is a
                                        // checkbox on this very page, and this column exists so an admin
                                        // can see at a glance what a token may do. An unknown slug falls
                                        // back to itself rather than rendering a missing-key path.
                                        $abilityLabels = collect($token->abilities)
                                            ->map(fn (string $ability): string => \Illuminate\Support\Facades\Lang::has('api_tokens.abilities.'.$ability)
                                                ? __('api_tokens.abilities.'.$ability)
                                                : $ability)
                                            ->join(', ');
                                    @endphp
                                    @if ($token->restricts_sites && $token->sites->isEmpty())
                                        {{-- Restricted, and every site it named has been purged. Reaches
                                             nothing -- the opposite of what an empty relationship means
                                             for an unrestricted token. --}}
                                        <span class="lede">{{ __('api_tokens.reaches.purged') }}</span>
                                    @elseif ($token->sites->isEmpty())
                                        <span class="lede">{{ __('api_tokens.reaches.every_site') }}</span>
                                    @else
                                        <span class="lede">
                                            <span lang="">{{ $namedSites->pluck('name')->join(', ') }}</span>{{ $namedSites->isNotEmpty() && $hiddenSiteCount > 0 ? ', ' : '' }}{{ $hiddenSiteCount > 0 ? __('api_tokens.reaches.unsupported') : '' }}
                                        </span>
                                    @endif
                                    <span class="lede">{{ $token->abilities === [] ? __('api_tokens.reaches.no_abilities') : $abilityLabels }}</span>
                                </td>
                                <td>
                                    {{-- The figure that separates a live token from a forgotten one. --}}
                                    @if ($token->last_used_at)
                                        {{ $token->last_used_at->diffForHumans() }}
                                    @else
                                        <span class="lede">{{ __('api_tokens.last_used.never') }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($token->isRevoked())
                                        {{ __('api_tokens.state.revoked', ['when' => $token->revoked_at->diffForHumans()]) }}
                                    @elseif ($token->isExpired())
                                        {{ __('api_tokens.state.expired', ['when' => $token->expires_at->diffForHumans()]) }}
                                    @elseif ($token->expires_at)
                                        {{ __('api_tokens.state.expires', ['when' => $token->expires_at->diffForHumans()]) }}
                                    @else
                                        {{ __('api_tokens.state.active') }}
                                    @endif
                                </td>
                                <td>
                                    @unless ($token->isRevoked())
                                        <form method="POST" action="{{ route('dashboard.account.api-tokens.destroy', $token) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button secondary" type="submit">{{ __('api_tokens.list.revoke') }}</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="lede">{{ __('api_tokens.list.revoking_keeps') }}</p>
        @endif
    </section>

    <section class="section" aria-labelledby="api-token-create-heading">
        <div class="section-header">
            <h2 id="api-token-create-heading">{{ __('api_tokens.create.heading') }}</h2>
            <span class="lede">{{ __('api_tokens.create.read_only') }}</span>
        </div>

            <form class="section-form" method="POST" action="{{ route('dashboard.account.api-tokens.store') }}">
                @csrf

                <div class="field">
                    <label for="api_token_name">{{ __('api_tokens.create.name_label') }}</label>
                    {{-- The same reset the table's names carry. A token name is
                         what this admin calls their own integration, not a
                         sentence in the language they read the dashboard in.

                         The placeholder is our copy and inherits the reset,
                         which is the accepted cost of a control declaring one
                         language for both -- the value is read back on every
                         keystroke and outlives a hint shown only while the
                         field is empty. --}}
                    <input type="text" id="api_token_name" name="name" maxlength="120" required lang=""
                        placeholder="{{ __('api_tokens.create.name_placeholder') }}" value="{{ old('name') }}">
                    <p class="field-help">{{ __('api_tokens.create.name_help') }}</p>
                    @error('name')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <div class="field">
                    <label for="api_token_abilities">{{ __('api_tokens.create.abilities_label') }}</label>
                    <label for="api_token_read">
                        <input type="checkbox" id="api_token_read" name="abilities[]" value="read"
                            @checked(in_array(\App\Models\ApiToken::ABILITY_READ, $grantableAbilities, true))
                            @disabled(! in_array(\App\Models\ApiToken::ABILITY_READ, $grantableAbilities, true))>
                        {{ __('api_tokens.create.ability_read') }}
                    </label>
                    <label for="api_token_write">
                        <input type="checkbox" id="api_token_write" name="abilities[]" value="write"
                            @disabled(! in_array(\App\Models\ApiToken::ABILITY_WRITE, $grantableAbilities, true))>
                        {{ __('api_tokens.create.ability_write') }}
                    </label>
                    <p class="field-help">{{ __('api_tokens.create.abilities_help') }}</p>
                    @if (count($grantableAbilities) < count(\App\Models\ApiToken::ABILITIES))
                        <p class="field-help">{{ __('api_tokens.create.abilities_limited') }}</p>
                    @endif
                </div>

                <div class="field">
                    <label for="api_token_expires">{{ __('api_tokens.create.expires_label') }}</label>
                    <input type="number" id="api_token_expires" name="expires_in_days" min="1" max="730"
                        placeholder="90" value="{{ old('expires_in_days') }}">
                    <p class="field-help">{{ __('api_tokens.create.expires_help') }}</p>
                    @error('expires_in_days')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                @if ($sites->isNotEmpty())
                    <div class="field">
                        <label for="api_token_sites">{{ __('api_tokens.create.sites_label') }}</label>
                        @foreach ($sites as $site)
                            <label for="api_token_site_{{ $site->id }}">
                                <input type="checkbox" id="api_token_site_{{ $site->id }}" name="site_ids[]" value="{{ $site->id }}">
                                <span lang="">{{ $site->name }}</span>
                            </label>
                        @endforeach
                        <p class="field-help">
                            {{-- Said where the decision is made rather than only in the docs. A token is
                                 always pinned to a list: it cannot reach further than the person issuing
                                 it, and it does not widen later as the account grows. --}}
                            {!! __('api_tokens.create.sites_help', ['today' => '<strong>'.e(__('api_tokens.create.sites_help_today')).'</strong>']) !!}
                        </p>
                    </div>
                @endif

                <button class="button" type="submit">{{ __('api_tokens.create.submit') }}</button>
            </form>

        <div class="notice-copy">
            <p>{!! __('api_tokens.accountability', ['who' => '<em>'.e(__('api_tokens.accountability_who')).'</em>']) !!}</p>
        </div>
    </section>

    <section class="section" aria-labelledby="outbound-webhook-list-heading">
        <div class="section-header">
            <h2 id="outbound-webhook-list-heading">{{ __('outbound_webhooks.endpoints.heading') }}</h2>
            <span class="lede">{{ trans_choice('outbound_webhooks.endpoints.total', $webhookEndpoints->count(), ['count' => \App\Support\ReaderNumber::count($webhookEndpoints->count())]) }}</span>
        </div>

        @if ($webhookEndpoints->isEmpty())
            <div class="notice-copy"><p>{{ __('outbound_webhooks.endpoints.empty') }}</p></div>
        @else
            <div class="management-list">
                @foreach ($webhookEndpoints as $endpoint)
                    @php
                        $namedSites = $endpoint->sites->whereIn('id', $visibleSiteIds);
                        $hiddenSiteCount = $endpoint->sites->count() - $namedSites->count();
                        $eventLabels = collect($endpoint->events)->map(
                            fn (string $event): string => __('outbound_webhooks.events.'.str_replace('.', '_', $event))
                        );
                    @endphp
                    <div class="management-link">
                        <span>
                            <strong lang="">{{ $endpoint->name }}</strong>
                            <span class="lede"><code lang="">{{ $endpoint->url }}</code> · <code lang="">{{ $endpoint->secretHint() }}</code></span>
                            <span class="lede"><strong>{{ __('outbound_webhooks.endpoints.column_events') }}:</strong> {{ $eventLabels->join(', ') }}</span>
                            <span class="lede">
                                <strong>{{ __('outbound_webhooks.endpoints.column_reaches') }}:</strong>
                                @if ($endpoint->restricts_sites && $endpoint->sites->isEmpty())
                                    {{ __('outbound_webhooks.reaches.purged') }}
                                @else
                                    <span lang="">{{ $namedSites->pluck('name')->join(', ') }}</span>{{ $namedSites->isNotEmpty() && $hiddenSiteCount > 0 ? ', ' : '' }}{{ $hiddenSiteCount > 0 ? __('outbound_webhooks.reaches.unsupported') : '' }}
                                @endif
                            </span>
                            <span class="lede">
                                @if ($endpoint->createdBy)
                                    {!! __('outbound_webhooks.endpoints.created_by', [
                                        'when' => e($endpoint->created_at->diffForHumans()),
                                        'name' => '<span lang="">'.e($endpoint->createdBy->name).'</span>',
                                    ]) !!}
                                @else
                                    {{ __('outbound_webhooks.endpoints.created', ['when' => $endpoint->created_at->diffForHumans()]) }}
                                @endif
                            </span>
                        </span>
                        <span class="management-action">
                            <strong>{{ $endpoint->isEnabled()
                                ? __('outbound_webhooks.state.active')
                                : __('outbound_webhooks.state.disabled', ['when' => $endpoint->disabled_at->diffForHumans()]) }}</strong>
                            @if ($endpoint->isEnabled())
                                <form method="POST" action="{{ route('dashboard.account.outbound-webhooks.destroy', $endpoint) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="button secondary" type="submit">{{ __('outbound_webhooks.endpoints.disable') }}</button>
                                </form>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
            <p class="lede">{{ __('outbound_webhooks.endpoints.disabled_keeps') }}</p>
        @endif
    </section>

    <section class="section" aria-labelledby="outbound-webhook-create-heading">
        <div class="section-header">
            <h2 id="outbound-webhook-create-heading">{{ __('outbound_webhooks.create.heading') }}</h2>
            <span class="lede">{{ __('outbound_webhooks.create.thin') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.account.outbound-webhooks.store') }}">
            @csrf

            <div class="field">
                <label for="webhook_name">{{ __('outbound_webhooks.create.name_label') }}</label>
                <input type="text" id="webhook_name" name="webhook[name]" maxlength="120" required lang=""
                    placeholder="{{ __('outbound_webhooks.create.name_placeholder') }}" value="{{ old('webhook.name') }}">
                <p class="field-help">{{ __('outbound_webhooks.create.name_help') }}</p>
                @error('webhook.name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="webhook_url">{{ __('outbound_webhooks.create.url_label') }}</label>
                <input type="url" id="webhook_url" name="webhook[url]" maxlength="2048" required lang=""
                    placeholder="{{ __('outbound_webhooks.create.url_placeholder') }}" value="{{ old('webhook.url') }}">
                <p class="field-help">{{ __('outbound_webhooks.create.url_help') }}</p>
                @error('webhook.url')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="webhook_events">{{ __('outbound_webhooks.create.events_label') }}</label>
                @foreach ($grantableWebhookEvents as $event)
                    <label for="webhook_event_{{ str_replace('.', '_', $event) }}">
                        <input type="checkbox" id="webhook_event_{{ str_replace('.', '_', $event) }}"
                            name="webhook[events][]" value="{{ $event }}"
                            @checked(in_array($event, old('webhook.events', $grantableWebhookEvents), true))>
                        {{ __('outbound_webhooks.events.'.str_replace('.', '_', $event)) }}
                    </label>
                @endforeach
                <p class="field-help">{{ __('outbound_webhooks.create.events_help') }}</p>
                @error('webhook.events')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            @if ($sites->isNotEmpty())
                <div class="field">
                    <label for="webhook_sites">{{ __('outbound_webhooks.create.sites_label') }}</label>
                    @foreach ($sites as $site)
                        <label for="webhook_site_{{ $site->id }}">
                            <input type="checkbox" id="webhook_site_{{ $site->id }}" name="webhook[site_ids][]" value="{{ $site->id }}"
                                @checked(in_array($site->id, old('webhook.site_ids', [])))>
                            <span lang="">{{ $site->name }}</span>
                        </label>
                    @endforeach
                    <p class="field-help">{!! __('outbound_webhooks.create.sites_help', ['today' => '<strong>'.e(__('outbound_webhooks.create.sites_help_today')).'</strong>']) !!}</p>
                </div>
            @endif

            <button class="button" type="submit">{{ __('outbound_webhooks.create.submit') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="outbound-webhook-deliveries-heading">
        <div class="section-header">
            <h2 id="outbound-webhook-deliveries-heading">{{ __('outbound_webhooks.deliveries.heading') }}</h2>
            <span class="lede">{{ trans_choice('outbound_webhooks.deliveries.total', $webhookDeliveries->count(), ['count' => \App\Support\ReaderNumber::count($webhookDeliveries->count())]) }}</span>
        </div>

        @if ($webhookDeliveries->isEmpty())
            <div class="notice-copy"><p>{{ __('outbound_webhooks.deliveries.empty') }}</p></div>
        @else
            <div class="management-list">
                @foreach ($webhookDeliveries as $delivery)
                    @php
                        $eventLabel = __('outbound_webhooks.events.'.str_replace('.', '_', $delivery->event));
                        $stateLabel = match (true) {
                            $delivery->delivered_at !== null => __('outbound_webhooks.deliveries.delivered', ['when' => $delivery->delivered_at->diffForHumans()]),
                            $delivery->cancelled_at !== null => __('outbound_webhooks.deliveries.cancelled', ['when' => $delivery->cancelled_at->diffForHumans()]),
                            $delivery->failed_at !== null => __('outbound_webhooks.deliveries.failed', ['when' => $delivery->failed_at->diffForHumans()]),
                            $delivery->isRetrying() => __('outbound_webhooks.deliveries.retrying'),
                            default => __('outbound_webhooks.deliveries.pending'),
                        };
                    @endphp
                    <details class="details-disclosure">
                        <summary class="details-disclosure__summary">
                            {{ $eventLabel }} · <span lang="">{{ $delivery->endpoint->name }}</span> · {{ $stateLabel }}
                        </summary>
                        <div class="details-disclosure__body">
                            <div class="meta-grid">
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('outbound_webhooks.deliveries.column_event') }}</span>
                                    <span class="meta-value">{{ __('outbound_webhooks.deliveries.sequence', ['number' => \App\Support\ReaderNumber::count($delivery->sequence)]) }}</span>
                                    <span class="lede"><code lang="">{{ $delivery->public_id }}</code></span>
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('outbound_webhooks.deliveries.column_endpoint') }}</span>
                                    <span class="meta-value" lang="">{{ $delivery->endpoint->name }}</span>
                                    @if ($delivery->site)<span class="lede" lang="">{{ $delivery->site->name }}</span>@endif
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('outbound_webhooks.deliveries.column_response') }}</span>
                                    @if ($delivery->response_status)
                                        <span class="meta-value">{{ __('outbound_webhooks.deliveries.status', ['status' => $delivery->response_status]) }}</span>
                                        <span class="lede" lang="">{{ $delivery->response_body ?? __('outbound_webhooks.deliveries.response_omitted') }}</span>
                                    @else
                                        <span class="meta-value">{{ __('outbound_webhooks.deliveries.no_response') }}</span>
                                    @endif
                                </div>
                                <div class="meta-item">
                                    <span class="meta-label">{{ __('outbound_webhooks.deliveries.column_state') }}</span>
                                    <span class="meta-value">{{ $stateLabel }}</span>
                                    <span class="lede">{{ trans_choice('outbound_webhooks.deliveries.attempts', $delivery->attempts, ['count' => \App\Support\ReaderNumber::count($delivery->attempts)]) }}</span>
                                    @if ($delivery->failed_at && $delivery->endpoint->isEnabled())
                                        <form method="POST" action="{{ route('dashboard.account.outbound-webhooks.retry', $delivery) }}">
                                            @csrf
                                            <button class="button secondary" type="submit">{{ __('outbound_webhooks.deliveries.retry') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                            <div>
                                <span class="meta-label">{{ __('outbound_webhooks.deliveries.column_sent') }}</span>
                                <pre><code lang="">{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                            </div>
                        </div>
                    </details>
                @endforeach
            </div>
            <p class="lede">{{ __('outbound_webhooks.deliveries.scope') }}</p>
        @endif
    </section>
</x-layouts.app>
