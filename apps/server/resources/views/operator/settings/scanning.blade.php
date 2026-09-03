<x-layouts.operator :title="__('operator.scanning.document_title')">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.scanning.title')"
        :subtitle="__('operator.scanning.subtitle')" />

    @foreach (['status', 'error'] as $feedbackType)
        @if ($feedback = session($feedbackType))
            @php
                $feedbackKey = is_array($feedback) ? ($feedback['key'] ?? '') : $feedback;
                $feedbackParameters = collect(is_array($feedback) ? ($feedback['parameters'] ?? []) : [])
                    ->mapWithKeys(fn ($value, $key) => [$key => '<span lang="">'.e((string) $value).'</span>'])
                    ->all();
            @endphp

            <p class="status-message">{!! __($feedbackKey, $feedbackParameters) !!}</p>
        @endif
    @endforeach

    <section class="section" aria-labelledby="scanning-config-heading">
        <div class="section-header">
            <h2 id="scanning-config-heading">{{ __('operator.scanning.heading') }}</h2>
            <span class="lede">{{ __('operator.scanning.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.scanning.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="driver">{{ __('operator.scanning.driver') }}</label>
                <select id="driver" name="driver">
                    @if ($externalDriver)
                        <option lang="" value="{{ $externalDriver }}" @selected(old('driver', $driver) === $externalDriver)>{{ $externalDriver }}</option>
                    @endif
                    <option value="" @selected(old('driver', $driver) === '')>{{ __('operator.scanning.none') }}</option>
                    <option lang="" value="clamav" @selected(old('driver', $driver) === 'clamav')>ClamAV</option>
                </select>
                @error('driver')<p class="field-error">{{ $message }}</p>@enderror
                @if ($externalDriver)
                    <p class="field-help">{!! __('operator.scanning.external_driver_help', ['driver' => '<code lang="">'.e($externalDriver).'</code>']) !!}</p>
                @endif
                <p class="field-help">{{ __('operator.scanning.driver_help') }}</p>
            </div>

            <div class="field">
                <label for="socket">{{ __('operator.scanning.socket') }}</label>
                <input id="socket" name="socket" lang="" value="{{ old('socket', $socket) }}" autocomplete="off" placeholder="tcp://127.0.0.1:3310">
                @error('socket')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.scanning.socket_help', [
                    'tcp' => '<code lang="">tcp://host:port</code>',
                    'unix' => '<code lang="">unix:///var/run/clamav/clamd.ctl</code>',
                ]) !!}</p>
            </div>

            <div class="field">
                {{-- Always submit a value so unchecking survives a validation error. --}}
                <input type="hidden" name="fail_closed" value="0">
                <label class="check-row" for="fail_closed">
                    <input id="fail_closed" type="checkbox" name="fail_closed" value="1" @checked(old('fail_closed', $failClosed))>
                    <span>{{ __('operator.scanning.fail_closed') }}</span>
                </label>
            </div>

            <button class="button" type="submit">{{ __('operator.scanning.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="scanning-test-heading">
        <div class="section-header">
            <h2 id="scanning-test-heading">{{ __('operator.scanning.test_heading') }}</h2>
            <span class="lede">{{ __('operator.scanning.test_lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.scanning.test') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <button class="button secondary" type="submit">{{ __('operator.scanning.test') }}</button>
        </form>
    </section>
</x-layouts.operator>
