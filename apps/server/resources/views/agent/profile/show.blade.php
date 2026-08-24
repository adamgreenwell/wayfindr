<x-layouts.app :title="__('profile.document_title')" :agent="$agent" :account="$account">
    <x-page-header :title="__('profile.title')" :subtitle="__('profile.subtitle')" />

    @if (session('status'))
        {{-- A catalogue key rather than a sentence, so it is translated in the
             request that shows it -- see AgentProfileController::update(). --}}
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="profile-context-heading">
        <div class="section-header">
            <h2 id="profile-context-heading">{{ $agent->name }}</h2>
            <span class="lede">{{ $roleLabels[$agent->account_role?->value] ?? __('profile.roles.agent') }}</span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">{{ __('profile.context.email') }}</span>
                <span class="meta-value">{{ $agent->email }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('profile.context.account') }}</span>
                <span class="meta-value">{{ $account->name }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('profile.context.role') }}</span>
                <span class="meta-value">{{ $roleLabels[$agent->account_role?->value] ?? __('profile.roles.agent') }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">{{ __('profile.context.member_since') }}</span>
                <span class="meta-value">{{ $agent->created_at?->diffForHumans() ?? __('profile.context.member_since_unknown') }}</span>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="profile-update-heading">
        <div class="section-header">
            <h2 id="profile-update-heading">{{ __('profile.details.heading') }}</h2>
            <span class="lede">{{ __('profile.details.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="name">{{ __('profile.details.name') }}</label>
                <input id="name" name="name" value="{{ old('name', $agent->name) }}" autocomplete="name" required>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <p class="field-help">{{ __('profile.details.email_help') }}</p>

            <div class="field">
                <label for="locale">{{ __('profile.details.language') }}</label>
                <select id="locale" name="locale">
                    {{-- "Whatever the install uses" is the default and stays
                         selectable: an agent who picked a language should be
                         able to stop having picked one. --}}
                    <option value="">{{ __('profile.details.language_default') }}</option>
                    @foreach (\App\Support\DashboardLanguage::options() as $code => $label)
                        <option value="{{ $code }}" @selected(old('locale', $agent->locale) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="field-help">{{ __('profile.details.language_help') }}</p>
                @error('locale')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <button class="button" type="submit">{{ __('profile.details.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="alert-readiness-heading">
        <div class="section-header">
            <h2 id="alert-readiness-heading">{{ __('profile.readiness.heading') }}</h2>
            <span class="lede">{{ __('profile.readiness.lede') }}</span>
        </div>

        <div class="meta-grid">
            @foreach ($alertReadiness as $readinessItem)
                <div class="meta-item">
                    <span class="meta-label">{{ $readinessItem['label'] }}</span>
                    <span class="meta-value">
                        <span class="readiness-status" data-status="{{ $readinessItem['tone'] }}">{{ $readinessItem['status'] }}</span>
                    </span>
                    {{-- A card whose detail is not translated says so; the rest
                         inherit the page. See AgentProfileController. --}}
                    <p class="field-help" lang="{{ str_replace('_', '-', $readinessItem['detail_locale'] ?? app()->getLocale()) }}">{{ $readinessItem['detail'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="section" aria-labelledby="alert-preferences-heading">
        <div class="section-header">
            <h2 id="alert-preferences-heading">{{ __('profile.alerts.heading') }}</h2>
            <span class="lede">{{ __('profile.alerts.lede') }}</span>
        </div>

        <div class="notice-copy notice-copy-bordered" aria-labelledby="alert-preference-guidance-heading">
            <p><strong id="alert-preference-guidance-heading">{{ __('profile.alerts.guidance_heading') }}</strong></p>
            <p>{{ __('profile.alerts.guidance_dashboard') }}</p>
            <p>{{ __('profile.alerts.guidance_email') }}</p>
            <p>{{ __('profile.alerts.guidance_quiet') }}</p>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.profile.alerts.update') }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="alert_mode">{{ __('profile.alerts.mode') }}</label>
                <select id="alert_mode" name="alert_mode" required>
                    @foreach ($alertModeOptions as $modeValue => $modeLabel)
                        <option value="{{ $modeValue }}" @selected(old('alert_mode', $alertMode) === $modeValue)>
                            {{ $modeLabel }}
                        </option>
                    @endforeach
                </select>
                @error('alert_mode')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <label class="check-row" for="email_alerts">
                <input
                    id="email_alerts"
                    name="email_alerts"
                    type="checkbox"
                    value="1"
                    @checked(old('email_alerts', $agent->alertEmailEnabled()))
                >
                <span>{{ __('profile.alerts.email_alerts') }}</span>
            </label>

            <div class="field">
                <label for="alert_cadence">{{ __('profile.alerts.cadence') }}</label>
                <select id="alert_cadence" name="alert_cadence" required>
                    @foreach ($alertCadenceOptions as $cadenceValue => $cadenceLabel)
                        @php
                            $isCurrentCadence = old('alert_cadence', $alertCadence) === $cadenceValue;
                        @endphp
                        <option value="{{ $cadenceValue }}" @selected($isCurrentCadence)>
                            {{ $cadenceLabel }}
                        </option>
                    @endforeach
                </select>
                @error('alert_cadence')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <p class="field-help">{{ __('profile.alerts.cadence_help') }}</p>

            @if ($alertCadence === $agent::ALERT_CADENCE_DIGEST)
                @php
                    $digestDeliveryTone = match ($digestDeliveryStatus['status']) {
                        $agent::ALERT_DIGEST_DELIVERY_QUEUED => 'ready',
                        $agent::ALERT_DIGEST_DELIVERY_FAILED => 'attention',
                        default => 'manual',
                    };
                @endphp
                <p class="field-help">
                    <span class="readiness-status" data-status="{{ $digestDeliveryTone }}">
                        {{ __('profile.alerts.last_digest') }}
                    </span>
                    {{ $digestDeliveryStatus['label'] }}.
                    {{ $digestDeliveryStatus['message'] }}
                    @if ($digestDeliveryStatus['last_attempted_at'])
                        {{ $digestDeliveryStatus['last_attempted_at']->diffForHumans() }}.
                    @endif
                </p>
            @endif

            <p class="field-help">{{ __('profile.alerts.email_help') }}</p>
            <p class="field-help">
                <span class="readiness-status" data-status="{{ $mailReadiness['status'] }}">
                    {{ $mailReadiness['status'] === 'ready' ? __('profile.alerts.delivery_ready') : __('profile.alerts.delivery_attention') }}
                </span>
                {{-- Same exception, same reason: this prose is the operator
                     console's and is deliberately still English. --}}
                <span lang="{{ str_replace('_', '-', \App\Support\DashboardLanguage::FALLBACK) }}">{{ $mailReadiness['summary'] }} {{ $mailReadiness['action'] }}</span>
            </p>

            <button class="button" type="submit">{{ __('profile.alerts.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="password-update-heading">
        <div class="section-header">
            <h2 id="password-update-heading">{{ __('profile.password.heading') }}</h2>
            <span class="lede">{{ __('profile.password.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.profile.password.update') }}">
            @csrf
            @method('PUT')

            <input type="text" name="username" value="{{ $agent->email }}" autocomplete="username" hidden readonly>

            <div class="field">
                <label for="current_password">{{ __('profile.password.current') }}</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                @error('current_password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">{{ __('profile.password.new') }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">{{ __('profile.password.confirm') }}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>

            <button class="button" type="submit">{{ __('profile.password.save') }}</button>
        </form>
    </section>
</x-layouts.app>
