<x-layouts.operator :title="__('operator.mail.document_title')">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.mail.title')"
        :subtitle="__('operator.mail.subtitle')" />

    @foreach (['status', 'error'] as $feedbackType)
        @if ($feedback = session($feedbackType))
            <p class="status-message"><x-operator-feedback :feedback="$feedback" /></p>
        @endif
    @endforeach

    <section class="section" aria-labelledby="mail-config-heading">
        <div class="section-header">
            <h2 id="mail-config-heading">{{ __('operator.mail.heading') }}</h2>
            <span class="lede">{{ __('operator.mail.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.mail.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="mailer">{{ __('operator.mail.transport') }}</label>
                <select id="mailer" name="mailer">
                    @if ($externalMailer)
                        <option lang="" value="{{ $externalMailer }}" @selected(old('mailer', $mailer) === $externalMailer)>{{ $externalMailer }}</option>
                    @endif
                    <option value="log" @selected(old('mailer', $mailer) === 'log')>{{ __('operator.mail.log_only') }}</option>
                    <option lang="" value="smtp" @selected(old('mailer', $mailer) === 'smtp')>SMTP</option>
                </select>
                @error('mailer')<p class="field-error">{{ $message }}</p>@enderror
                @if ($externalMailer)
                    <p class="field-help">{!! __('operator.mail.external_transport_help', ['transport' => '<code lang="">'.e($externalMailer).'</code>']) !!}</p>
                @endif
                <p class="field-help">{{ __('operator.mail.transport_help') }}</p>
            </div>

            <div class="field">
                <label for="host">{{ __('operator.mail.host') }}</label>
                <input id="host" name="host" lang="" value="{{ old('host', $host) }}" autocomplete="off" placeholder="smtp.provider.com">
                @error('host')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="port">{{ __('operator.mail.port') }}</label>
                <input id="port" name="port" lang="" value="{{ old('port', $port) }}" inputmode="numeric" placeholder="587">
                @error('port')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="encryption">{{ __('operator.mail.encryption') }}</label>
                <select id="encryption" name="encryption">
                    <option value="" @selected(old('encryption', $encryption) === '')>{{ __('operator.mail.encryption_auto') }}</option>
                    <option lang="" value="smtp" @selected(old('encryption', $encryption) === 'smtp')>STARTTLS</option>
                    <option lang="" value="smtps" @selected(old('encryption', $encryption) === 'smtps')>SSL/TLS</option>
                </select>
                @error('encryption')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="username">{{ __('operator.mail.username') }}</label>
                <input id="username" name="username" lang="" value="{{ old('username', $username) }}" autocomplete="off">
                @error('username')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="password">{{ __('operator.mail.password') }}</label>
                <input id="password" name="password" type="password" autocomplete="new-password"
                    placeholder="{{ $passwordUnreadable ? __('operator.mail.password_placeholder_unreadable') : ($passwordIsSet ? __('operator.mail.password_placeholder_configured') : __('operator.mail.password_placeholder_none')) }}">
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                @if ($passwordUnreadable)
                    <p class="field-error">{{ __('operator.mail.password_unreadable') }}</p>
                @endif
                <p class="field-help">{{ __('operator.mail.password_help') }}</p>
                <label class="check-row" for="no_password">
                    <input id="no_password" type="checkbox" name="no_password" value="1" @checked(old('no_password'))>
                    <span>{{ __('operator.mail.no_password') }}</span>
                </label>
            </div>

            <div class="field">
                <label for="from_address">{{ __('operator.mail.from_address') }}</label>
                <input id="from_address" name="from_address" type="email" lang="" value="{{ old('from_address', $fromAddress) }}" placeholder="support@yourdomain.com" required>
                @error('from_address')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="from_name">{{ __('operator.mail.from_name') }}</label>
                <input id="from_name" name="from_name" lang="" value="{{ old('from_name', $fromName) }}" placeholder="Acme Support">
                @error('from_name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="button" type="submit">{{ __('operator.mail.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="mail-test-heading">
        <div class="section-header">
            <h2 id="mail-test-heading">{{ __('operator.mail.test_heading') }}</h2>
            <span class="lede">{{ __('operator.mail.test_lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.mail.test') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="to">{{ __('operator.mail.recipient') }}</label>
                <input id="to" name="to" type="email" lang="" value="{{ old('to', $operator->email) }}" placeholder="you@yourdomain.com" required>
                @error('to')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <button class="button secondary" type="submit">{{ __('operator.mail.send_test') }}</button>
        </form>
    </section>
</x-layouts.operator>
