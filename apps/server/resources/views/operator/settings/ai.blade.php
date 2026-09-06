<x-layouts.operator :title="__('operator.ai.document_title')">
    <x-page-header
        :back-href="route('operator.dashboard')"
        :back-label="__('operator.shell.back_to_console')"
        :title="__('operator.ai.title')"
        :subtitle="__('operator.ai.subtitle')" />

    @foreach (['status', 'error'] as $feedbackType)
        @if ($feedback = session($feedbackType))
            <p class="status-message"><x-operator-feedback :feedback="$feedback" /></p>
        @endif
    @endforeach

    <section class="section" aria-labelledby="ai-config-heading">
        <div class="section-header">
            <h2 id="ai-config-heading">{{ __('operator.ai.heading') }}</h2>
            <span class="readiness-status" data-status="{{ $assessment['status'] === 'ready' ? 'ready' : ($assessment['status'] === 'unset' ? 'manual' : 'attention') }}">
                {{ __('operator.ai.status.'.$assessment['status']) }}
            </span>
        </div>

        <p class="lede">{{ __('operator.ai.lede') }}</p>

        <form class="section-form" method="POST" action="{{ route('operator.settings.ai.update') }}">
            @csrf

            <div class="field">
                <label for="provider">{{ __('operator.ai.provider') }}</label>
                <select id="provider" name="provider">
                    @if ($externalProvider)
                        <option lang="" value="{{ $externalProvider }}" @selected(old('provider', $provider) === $externalProvider)>{{ $externalProvider }}</option>
                    @endif
                    <option value="" @selected(old('provider', $provider) === '')>{{ __('operator.ai.none') }}</option>
                    @foreach ($providers as $providerOption)
                        <option lang="" value="{{ $providerOption }}" @selected(old('provider', $provider) === $providerOption)>{{ $providerOption }}</option>
                    @endforeach
                </select>
                @error('provider')<p class="field-error">{{ $message }}</p>@enderror
                @if ($externalProvider)
                    <p class="field-help">{!! __('operator.ai.external_provider_help', ['provider' => '<code lang="">'.e($externalProvider).'</code>']) !!}</p>
                @endif
                <p class="field-help">{{ __('operator.ai.provider_help') }}</p>
            </div>

            <div class="field">
                <label for="model">{{ __('operator.ai.model') }}</label>
                <input id="model" name="model" lang="" value="{{ old('model', $model) }}" autocomplete="off" placeholder="gpt-5-mini">
                @error('model')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{{ __('operator.ai.model_help') }}</p>
            </div>

            <div class="field">
                <label for="endpoint">{{ __('operator.ai.endpoint') }}</label>
                <input id="endpoint" name="endpoint" lang="" value="{{ old('endpoint', $endpoint) }}" autocomplete="off" placeholder="http://localhost:11434">
                @error('endpoint')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{{ __('operator.ai.endpoint_help') }}</p>
            </div>

            <div class="field">
                <label for="api_key">{{ __('operator.ai.api_key') }}</label>
                <input id="api_key" name="api_key" type="password" autocomplete="new-password"
                    placeholder="{{ $apiKeyUnreadable ? __('operator.ai.api_key_placeholder_unreadable') : ($apiKeyIsSet ? __('operator.ai.api_key_placeholder_configured') : __('operator.ai.api_key_placeholder_none')) }}">
                @error('api_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($apiKeyUnreadable)
                    <p class="field-error">{{ __('operator.ai.api_key_unreadable') }}</p>
                @endif
                <p class="field-help">{{ __('operator.ai.api_key_help') }}</p>
            </div>

            <label class="check-row" for="clear_api_key">
                <input id="clear_api_key" type="checkbox" name="clear_api_key" value="1" @checked(old('clear_api_key'))>
                <span>{{ __('operator.ai.clear_api_key') }}</span>
            </label>

            <button class="button" type="submit">{{ __('operator.ai.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="ai-privacy-heading">
        <div class="section-header">
            <h2 id="ai-privacy-heading">{{ __('operator.ai.privacy_heading') }}</h2>
            <span class="lede">{{ __('operator.ai.privacy_lede') }}</span>
        </div>
        <ul>
            <li>{{ __('operator.ai.privacy.minimum') }}</li>
            <li>{{ __('operator.ai.privacy.attachments') }}</li>
            <li>{{ __('operator.ai.privacy.provider') }}</li>
            <li>{{ __('operator.ai.privacy.human') }}</li>
        </ul>
    </section>

    <section class="section" aria-labelledby="ai-test-heading">
        <div class="section-header">
            <h2 id="ai-test-heading">{{ __('operator.ai.test_heading') }}</h2>
            <span class="lede">{{ __('operator.ai.test_lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.ai.test') }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('operator.ai.test') }}</button>
        </form>
    </section>
</x-layouts.operator>
