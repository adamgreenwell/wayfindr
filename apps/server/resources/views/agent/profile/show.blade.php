<x-layouts.app title="Agent Profile" :agent="$agent" :account="$account">
    <x-page-header title="Agent profile" subtitle="Keep your agent identity and sign-in password current." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    <section class="section" aria-labelledby="profile-context-heading">
        <div class="section-header">
            <h2 id="profile-context-heading">{{ $agent->name }}</h2>
            <span class="lede">{{ $roleLabels[$agent->account_role?->value] ?? 'Agent' }}</span>
        </div>

        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-label">Email</span>
                <span class="meta-value">{{ $agent->email }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Account</span>
                <span class="meta-value">{{ $account->name }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Role</span>
                <span class="meta-value">{{ $roleLabels[$agent->account_role?->value] ?? 'Agent' }}</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Member since</span>
                <span class="meta-value">{{ $agent->created_at?->diffForHumans() ?? 'Unknown' }}</span>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="profile-update-heading">
        <div class="section-header">
            <h2 id="profile-update-heading">Display name</h2>
            <span class="lede">Shown to agents and visitors</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.profile.update') }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="name">Name</label>
                <input id="name" name="name" value="{{ old('name', $agent->name) }}" autocomplete="name" required>
                @error('name')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <p class="field-help">Your email is used for sign-in. Ask an owner if it needs changed.</p>

            <button class="button" type="submit">Save profile</button>
        </form>
    </section>

    <section class="section" aria-labelledby="alert-readiness-heading">
        <div class="section-header">
            <h2 id="alert-readiness-heading">Alert readiness</h2>
            <span class="lede">Your current support signal path</span>
        </div>

        <div class="meta-grid">
            @foreach ($alertReadiness as $readinessItem)
                <div class="meta-item">
                    <span class="meta-label">{{ $readinessItem['label'] }}</span>
                    <span class="meta-value">
                        <span class="readiness-status" data-status="{{ $readinessItem['tone'] }}">{{ $readinessItem['status'] }}</span>
                    </span>
                    <p class="field-help">{{ $readinessItem['detail'] }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="section" aria-labelledby="alert-preferences-heading">
        <div class="section-header">
            <h2 id="alert-preferences-heading">Alert preferences</h2>
            <span class="lede">Keep support signals useful</span>
        </div>

        <div class="notice-copy notice-copy-bordered" aria-labelledby="alert-preference-guidance-heading">
            <p><strong id="alert-preference-guidance-heading">How alerts behave</strong></p>
            <p>Dashboard alerts are the source of truth for support work that needs attention.</p>
            <p>Email alerts are optional delivery, not a separate queue.</p>
            <p>Quiet mode pauses new alerts without changing assignments, site access, or support responsibility.</p>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.profile.alerts.update') }}">
            @csrf
            @method('PUT')

            <div class="field">
                <label for="alert_mode">Alert mode</label>
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
                <span>Email alerts</span>
            </label>

            <div class="field">
                <label for="alert_cadence">Email cadence</label>
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

            <p class="field-help">
                Digest delivery bundles eligible email alerts when the scheduler runs. Unattended only emails when a visitor message waits unseen. Dashboard alerts stay immediate.
            </p>

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
                        Last digest
                    </span>
                    {{ $digestDeliveryStatus['label'] }}.
                    {{ $digestDeliveryStatus['message'] }}
                    @if ($digestDeliveryStatus['last_attempted_at'])
                        {{ $digestDeliveryStatus['last_attempted_at']->diffForHumans() }}.
                    @endif
                </p>
            @endif

            <p class="field-help">
                Email alerts send the same calm support signals to your inbox
                when mail is configured. Quiet mode still suppresses new alerts.
            </p>
            <p class="field-help">
                <span class="readiness-status" data-status="{{ $mailReadiness['status'] }}">
                    {{ $mailReadiness['status'] === 'ready' ? 'Email delivery ready' : 'Email delivery needs attention' }}
                </span>
                {{ $mailReadiness['summary'] }} {{ $mailReadiness['action'] }}
            </p>

            <button class="button" type="submit">Save alert preferences</button>
        </form>
    </section>

    <section class="section" aria-labelledby="password-update-heading">
        <div class="section-header">
            <h2 id="password-update-heading">Change password</h2>
            <span class="lede">Use this after receiving a temporary password</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('dashboard.profile.password.update') }}">
            @csrf
            @method('PUT')

            <input type="text" name="username" value="{{ $agent->email }}" autocomplete="username" hidden readonly>

            <div class="field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                @error('current_password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" required>
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
            </div>

            <button class="button" type="submit">Update password</button>
        </form>
    </section>
</x-layouts.app>
