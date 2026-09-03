<x-layouts.operator :title="__('operator.localization.document_title')">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.localization.title')"
        :subtitle="__('operator.localization.subtitle')" />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="localization-config-heading">
        <div class="section-header">
            <h2 id="localization-config-heading">{{ __('operator.localization.heading') }}</h2>
            <span class="lede">{{ __('operator.localization.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.localization.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="language">{{ __('operator.localization.language') }}</label>
                <select id="language" name="language">
                    @foreach ($languageChoices as $code => $label)
                        <option lang="{{ $code }}" value="{{ $code }}" @selected(old('language', $language) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('language')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{{ __('operator.localization.language_help') }}</p>
            </div>

            <div class="field">
                <label for="timezone">{{ __('operator.localization.timezone') }}</label>
                <select id="timezone" name="timezone">
                    @foreach ($timezoneChoices as $region => $identifiers)
                        <optgroup lang="" label="{{ $region }}">
                            @foreach ($identifiers as $identifier)
                                <option lang="" value="{{ $identifier }}" @selected(old('timezone', $timezone) === $identifier)>{{ $identifier }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('timezone')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{{ __('operator.localization.timezone_help') }}</p>
            </div>

            <button class="button" type="submit">{{ __('operator.localization.save') }}</button>
        </form>
    </section>

</x-layouts.operator>
