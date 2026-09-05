<x-layouts.operator :title="__('operator.webpush.document_title')">
    <x-page-header
        :back-href="route('operator.dashboard')"
        :back-label="__('operator.shell.back_to_console')"
        :title="__('operator.webpush.title')"
        :subtitle="__('operator.webpush.subtitle')" />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="webpush-config-heading">
        <div class="section-header">
            <h2 id="webpush-config-heading">{{ __('operator.webpush.heading') }}</h2>
            <span class="readiness-status" data-status="{{ $assessment['status'] === 'ready' ? 'ready' : ($assessment['status'] === 'unset' ? 'manual' : 'attention') }}">
                {{ __('operator.webpush.status.'.$assessment['status']) }}
            </span>
        </div>

        <p class="lede">{{ __('operator.webpush.lede') }}</p>

        <form class="section-form" method="POST" action="{{ route('operator.settings.webpush.update') }}">
            @csrf

            <div class="field">
                <label for="subject">{{ __('operator.webpush.subject') }}</label>
                <input id="subject" name="subject" lang="" value="{{ old('subject', $subject) }}" autocomplete="off" placeholder="mailto:admin@example.com">
                @error('subject')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{{ __('operator.webpush.subject_help') }}</p>
            </div>

            <div class="field">
                <label for="public_key">{{ __('operator.webpush.public_key') }}</label>
                <textarea id="public_key" name="public_key" lang="" rows="3" autocomplete="off">{{ old('public_key', $publicKey) }}</textarea>
                @error('public_key')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="private_key">{{ __('operator.webpush.private_key') }}</label>
                <input id="private_key" name="private_key" type="password" autocomplete="new-password"
                    placeholder="{{ $privateKeyUnreadable ? __('operator.webpush.private_placeholder_unreadable') : ($privateKeyIsSet ? __('operator.webpush.private_placeholder_configured') : __('operator.webpush.private_placeholder_none')) }}">
                @error('private_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($privateKeyUnreadable)
                    <p class="field-error">{{ __('operator.webpush.private_unreadable') }}</p>
                @endif
                <p class="field-help">{{ __('operator.webpush.private_help') }}</p>
            </div>

            <label class="check-row" for="clear_keys">
                <input id="clear_keys" type="checkbox" name="clear_keys" value="1" @checked(old('clear_keys'))>
                <span>{{ __('operator.webpush.clear_keys') }}</span>
            </label>
            @error('clear_keys')<p class="field-error">{{ $message }}</p>@enderror

            <p class="field-help">{{ __('operator.webpush.generate_help') }}</p>

            <button class="button" type="submit">{{ __('operator.webpush.save') }}</button>
        </form>
    </section>
</x-layouts.operator>
