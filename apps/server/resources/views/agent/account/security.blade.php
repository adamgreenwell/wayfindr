<x-layouts.app :title="__('two_factor.policy.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('two_factor.policy.heading')" :subtitle="__('two_factor.policy.subtitle')" />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="two-factor-readiness-heading">
        <div class="section-header">
            <h2 id="two-factor-readiness-heading">{{ __('two_factor.policy.readiness_heading') }}</h2>
            <span class="lede">{{ trans_choice('two_factor.policy.active_count', $activeAgentCount, ['count' => \App\Support\ReaderNumber::count($activeAgentCount)]) }}</span>
        </div>
        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('two_factor.policy.enabled_label') }}</span>
                <span class="meta-value">{{ $enabledCount }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('two_factor.policy.missing_label') }}</span>
                <span class="meta-value">{{ $missingCount }}</span>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="two-factor-policy-heading">
        <div class="section-header">
            <h2 id="two-factor-policy-heading">{{ __('two_factor.policy.setting_heading') }}</h2>
            <span class="lede">{{ $account->requires_two_factor ? __('two_factor.policy.required') : __('two_factor.policy.optional') }}</span>
        </div>
        <div class="notice-copy">
            <p>{{ __('two_factor.policy.help') }}</p>
            <p>{{ __('two_factor.policy.activation_help') }}</p>
        </div>
        <form class="section-form" method="POST" action="{{ route('dashboard.account.security.update') }}">
            @csrf
            @method('PUT')
            <label class="check-row" for="requires_two_factor">
                <input id="requires_two_factor" name="requires_two_factor" type="checkbox" value="1" @checked(old('requires_two_factor', $account->requires_two_factor))>
                <span>{{ __('two_factor.policy.require_checkbox') }}</span>
            </label>
            @error('requires_two_factor')
                <p class="field-error">{{ $message }}</p>
            @enderror
            <button class="button" type="submit">{{ __('two_factor.policy.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="oidc-heading">
        <div class="section-header">
            <h2 id="oidc-heading">{{ __('oidc.settings.heading') }}</h2>
            <span class="lede">{{ $oidcConnection?->is_enabled ? __('oidc.settings.enabled') : __('oidc.settings.disabled') }}</span>
        </div>
        <div class="notice-copy">
            <p>{{ __('oidc.settings.help') }}</p>
            @if ($oidcConnection)
                <p>{{ __('oidc.settings.callback_help') }}</p>
                <p><code lang="">{{ route('oidc.callback', ['connectionPublicId' => $oidcConnection->public_id]) }}</code></p>
            @endif
        </div>
        <form class="section-form" method="POST" action="{{ route('dashboard.account.security.oidc.update') }}">
            @csrf
            @method('PUT')
            <div class="field">
                <label for="oidc_name">{{ __('oidc.settings.name') }}</label>
                <input id="oidc_name" name="name" type="text" maxlength="100" value="{{ old('name', $oidcConnection?->name) }}" lang="" required>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="issuer_url">{{ __('oidc.settings.issuer_url') }}</label>
                <input id="issuer_url" name="issuer_url" type="url" maxlength="2048" value="{{ old('issuer_url', $oidcConnection?->issuer_url) }}" placeholder="https://id.example.com" lang="" required>
                @error('issuer_url')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="client_id">{{ __('oidc.settings.client_id') }}</label>
                <input id="client_id" name="client_id" type="text" maxlength="255" value="{{ old('client_id', $oidcConnection?->client_id) }}" autocomplete="off" lang="" required>
                @error('client_id')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="client_secret">{{ __('oidc.settings.client_secret') }}</label>
                <input id="client_secret" name="client_secret" type="password" maxlength="4096" autocomplete="new-password" @required(! $oidcConnection)>
                <p class="field-help">{{ $oidcConnection ? __('oidc.settings.secret_keep_help') : __('oidc.settings.secret_help') }}</p>
                @error('client_secret')<p class="field-error">{{ $message }}</p>@enderror
            </div>
            <label class="check-row" for="is_enabled">
                <input id="is_enabled" name="is_enabled" type="checkbox" value="1" @checked(old('is_enabled', $oidcConnection?->is_enabled))>
                <span>{{ __('oidc.settings.enable_checkbox') }}</span>
            </label>
            <button class="button" type="submit">{{ __('oidc.settings.save') }}</button>
        </form>
    </section>
</x-layouts.app>
