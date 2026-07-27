<x-layouts.app title="Mail settings">
    <p><a class="text-link" href="{{ route('operator.dashboard') }}">Back to operator console</a></p>

    <x-page-header
        title="Mail settings"
        subtitle="Configure outbound email here — no .env editing or restart. Changes apply immediately." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status-message">{{ session('error') }}</p>
    @endif

    <section class="section" aria-labelledby="mail-config-heading">
        <div class="section-header">
            <h2 id="mail-config-heading">Outbound mail</h2>
            <span class="lede">Alert emails and password resets go nowhere until a real transport is set.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.mail.update') }}">
            @csrf

            <div class="field">
                <label for="mailer">Transport</label>
                <select id="mailer" name="mailer">
                    @if ($externalMailer)
                        <option value="{{ $externalMailer }}" @selected(old('mailer', $mailer) === $externalMailer)>{{ $externalMailer }} (current, configured in env)</option>
                    @endif
                    <option value="log" @selected(old('mailer', $mailer) === 'log')>Log only (no delivery)</option>
                    <option value="smtp" @selected(old('mailer', $mailer) === 'smtp')>SMTP</option>
                </select>
                @error('mailer')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Choose SMTP and fill in the details below to send real email.</p>
            </div>

            <div class="field">
                <label for="host">SMTP host</label>
                <input id="host" name="host" value="{{ old('host', $host) }}" autocomplete="off" placeholder="smtp.provider.com">
                @error('host')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="port">SMTP port</label>
                <input id="port" name="port" value="{{ old('port', $port) }}" inputmode="numeric" placeholder="587">
                @error('port')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="encryption">Encryption</label>
                <select id="encryption" name="encryption">
                    <option value="" @selected(old('encryption', $encryption) === '')>Automatic (from port)</option>
                    <option value="smtp" @selected(old('encryption', $encryption) === 'smtp')>STARTTLS</option>
                    <option value="smtps" @selected(old('encryption', $encryption) === 'smtps')>SSL/TLS</option>
                </select>
                @error('encryption')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="username">SMTP username</label>
                <input id="username" name="username" value="{{ old('username', $username) }}" autocomplete="off">
                @error('username')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="password">SMTP password</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                    placeholder="{{ $passwordUnreadable ? 'Could not read the saved password — re-enter it' : ($passwordIsSet ? '•••••••• (a password is saved)' : 'No password saved') }}">
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                @if ($passwordUnreadable)
                    <p class="field-error">The saved password could not be decrypted (this can happen after an APP_KEY change). Re-enter it below, or check &ldquo;no password&rdquo; if the server needs none.</p>
                @endif
                <p class="field-help">Leave blank to keep the saved password. It is stored encrypted and never shown.</p>
                <label class="check-row" for="no_password">
                    <input id="no_password" type="checkbox" name="no_password" value="1" @checked(old('no_password'))>
                    <span>This server requires no password (unauthenticated relay)</span>
                </label>
            </div>

            <div class="field">
                <label for="from_address">From address</label>
                <input id="from_address" name="from_address" type="email" value="{{ old('from_address', $fromAddress) }}" placeholder="support@yourdomain.com" required>
                @error('from_address')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="from_name">From name</label>
                <input id="from_name" name="from_name" value="{{ old('from_name', $fromName) }}" placeholder="Acme Support">
                @error('from_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="button" type="submit">Save mail settings</button>
        </form>
    </section>

    <section class="section" aria-labelledby="mail-test-heading">
        <div class="section-header">
            <h2 id="mail-test-heading">Send a test email</h2>
            <span class="lede">Verify delivery with the saved settings — no terminal needed.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.mail.test') }}">
            @csrf

            <div class="field">
                <label for="to">Recipient</label>
                <input id="to" name="to" type="email" value="{{ old('to', $operator->email) }}" placeholder="you@yourdomain.com" required>
                @error('to')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="button secondary" type="submit">Send test email</button>
        </form>
    </section>
</x-layouts.app>
