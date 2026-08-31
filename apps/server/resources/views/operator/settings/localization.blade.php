<x-layouts.operator title="Language and region">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? 'Back'"
        title="Language and region"
        subtitle="What the dashboard reads in for anyone who has not chosen for themselves. Changes apply immediately, no restart." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    <section class="section" aria-labelledby="localization-config-heading">
        <div class="section-header">
            <h2 id="localization-config-heading">Install defaults</h2>
            <span class="lede">These are defaults, not rules. An agent who picks their own language or timezone on their profile keeps it — this answers for everyone else, which on a new install is everyone.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.localization.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="language">Language</label>
                <select id="language" name="language">
                    @foreach ($languageChoices as $code => $label)
                        <option value="{{ $code }}" @selected(old('language', $language) === $code)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('language')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Applies to the agent dashboard. What a visitor sees in the widget is chosen from their own browser and is not affected by this.</p>
            </div>

            <div class="field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone">
                    @foreach ($timezoneChoices as $region => $identifiers)
                        <optgroup label="{{ $region }}">
                            @foreach ($identifiers as $identifier)
                                <option value="{{ $identifier }}" @selected(old('timezone', $timezone) === $identifier)>{{ $identifier }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('timezone')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Times and report days are shown on this clock. Records are always stored in UTC, so changing this re-reads existing history rather than rewriting it.</p>
            </div>

            <button class="button" type="submit">Save language and region</button>
        </form>
    </section>

</x-layouts.operator>
