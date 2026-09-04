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
        @if (! $oidcConnection && ! $canManageOidcAuthority)
            <p class="empty">{{ __('oidc.settings.owner_connect_help') }}</p>
        @else
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
                    <input id="issuer_url" name="issuer_url" type="url" maxlength="2048" value="{{ old('issuer_url', $oidcConnection?->issuer_url) }}" placeholder="https://id.example.com" lang="" required @readonly(! $canManageOidcAuthority)>
                    @unless ($canManageOidcAuthority)<p class="field-help">{{ __('oidc.settings.owner_authority_help') }}</p>@endunless
                    @error('issuer_url')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="client_id">{{ __('oidc.settings.client_id') }}</label>
                    <input id="client_id" name="client_id" type="text" maxlength="255" value="{{ old('client_id', $oidcConnection?->client_id) }}" autocomplete="off" lang="" required @readonly(! $canManageOidcAuthority)>
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
        @endif
    </section>

    @if ($canManageOidcProvisioning && $oidcConnection)
        <section class="section" aria-labelledby="oidc-provisioning-heading">
            <div class="section-header">
                <h2 id="oidc-provisioning-heading">{{ __('oidc.provisioning.heading') }}</h2>
                <span class="lede">{{ $oidcConnection->jit_provisioning_enabled ? __('oidc.provisioning.enabled') : __('oidc.provisioning.disabled') }}</span>
            </div>
            <div class="notice-copy">
                <p>{{ __('oidc.provisioning.help') }}</p>
                <p>{{ __('oidc.provisioning.boundary_help') }}</p>
            </div>

            <form class="section-form" method="POST" action="{{ route('dashboard.account.security.oidc.provisioning.update') }}">
                @csrf
                @method('PUT')
                <div class="field">
                    <label for="role_claim">{{ __('oidc.provisioning.role_claim') }}</label>
                    <input id="role_claim" name="role_claim" type="text" maxlength="255" value="{{ old('role_claim', $oidcConnection->role_claim) }}" placeholder="groups" lang="" required>
                    <p class="field-help">{{ __('oidc.provisioning.role_claim_help') }}</p>
                    @error('role_claim')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <label class="check-row" for="jit_provisioning_enabled">
                    <input id="jit_provisioning_enabled" name="jit_provisioning_enabled" type="checkbox" value="1" @checked(old('jit_provisioning_enabled', $oidcConnection->jit_provisioning_enabled))>
                    <span>{{ __('oidc.provisioning.enable_checkbox') }}</span>
                </label>
                @error('jit_provisioning_enabled')<p class="field-error">{{ $message }}</p>@enderror
                <button class="button" type="submit">{{ __('oidc.provisioning.save') }}</button>
            </form>

            <h3>{{ __('oidc.provisioning.mappings_heading') }}</h3>
            @if ($oidcConnection->roleMappings->isEmpty())
                <p class="empty">{{ __('oidc.provisioning.mappings_empty') }}</p>
            @else
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('oidc.provisioning.claim_value') }}</th>
                                <th scope="col">{{ __('oidc.provisioning.wayfindr_role') }}</th>
                                <th scope="col"><span class="sr-only">{{ __('oidc.provisioning.actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($oidcConnection->roleMappings as $mapping)
                                <tr>
                                    <td><code lang="">{{ $mapping->claim_value }}</code></td>
                                    <td>
                                        {{ $mapping->customRole?->name ?? __('oidc.provisioning.roles.'.$mapping->built_in_role?->value) }}
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('dashboard.account.security.oidc.role-mappings.destroy', $mapping) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button danger" type="submit">{{ __('oidc.provisioning.delete_mapping') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <form class="section-form" method="POST" action="{{ route('dashboard.account.security.oidc.role-mappings.store') }}">
                @csrf
                <div class="field">
                    <label for="claim_value">{{ __('oidc.provisioning.claim_value') }}</label>
                    <input id="claim_value" name="claim_value" type="text" maxlength="255" value="{{ old('claim_value') }}" lang="" required>
                    @error('claim_value')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="role_target">{{ __('oidc.provisioning.wayfindr_role') }}</label>
                    <select id="role_target" name="role_target" required>
                        <option value="built_in:agent" @selected(old('role_target') === 'built_in:agent')>{{ __('oidc.provisioning.roles.agent') }}</option>
                        <option value="built_in:admin" @selected(old('role_target') === 'built_in:admin')>{{ __('oidc.provisioning.roles.admin') }}</option>
                        @foreach ($oidcCustomRoles as $role)
                            <option value="custom:{{ $role->id }}" @selected(old('role_target') === 'custom:'.$role->id)>{{ $role->name }}</option>
                        @endforeach
                    </select>
                    @error('role_target')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <button class="button" type="submit">{{ __('oidc.provisioning.add_mapping') }}</button>
            </form>
        </section>
    @endif
</x-layouts.app>
