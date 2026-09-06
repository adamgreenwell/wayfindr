@php($editing = $proactiveMessageRule !== null)

<x-layouts.app :title="$editing ? __('proactive_messages.edit.title') : __('proactive_messages.create.title')" :agent="$agent" :account="$account">
    <x-page-header
        :title="$editing ? __('proactive_messages.edit.title') : __('proactive_messages.create.title')"
        :subtitle="__('proactive_messages.form.subtitle')"
        :back-href="route('dashboard.sites.proactive-messages.index', $site)"
        :back-label="__('proactive_messages.form.back')"
    />

    @if (session('status'))
        <p class="status-message">{{ __(session('status')) }}</p>
    @endif

    <section class="section" aria-labelledby="proactive-rule-heading">
        <div class="section-header">
            <div>
                <h2 id="proactive-rule-heading">{{ __('proactive_messages.form.heading') }}</h2>
                <p class="lede">{{ __('proactive_messages.form.hint') }}</p>
            </div>
        </div>

        <form class="section-form" method="POST" action="{{ $editing
            ? route('dashboard.sites.proactive-messages.update', [$site, $proactiveMessageRule])
            : route('dashboard.sites.proactive-messages.store', $site) }}">
            @csrf
            @if ($editing)
                @method('PUT')
            @endif

            <div class="automation-rule-basics">
                <div class="field">
                    <label for="name">{{ __('proactive_messages.form.name') }}</label>
                    <input id="name" name="name" maxlength="80" value="{{ old('name', $proactiveMessageRule?->name) }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror lang="" required>
                    @error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror
                </div>

                <div class="field">
                    <label for="position">{{ __('proactive_messages.form.position') }}</label>
                    <input id="position" name="position" type="number" min="0" max="10000" step="1" value="{{ old('position', $defaultPosition) }}" required>
                    <p class="field-help">{{ __('proactive_messages.form.position_help') }}</p>
                    @error('position')<p class="field-error">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="field">
                <label for="message">{{ __('proactive_messages.form.message') }}</label>
                <textarea id="message" name="message" rows="4" maxlength="500" @error('message') aria-invalid="true" aria-describedby="message-error" @enderror lang="" required>{{ old('message', $proactiveMessageRule?->message) }}</textarea>
                <p class="field-help">{{ __('proactive_messages.form.message_help') }}</p>
                @error('message')<p id="message-error" class="field-error">{{ $message }}</p>@enderror
            </div>

            <fieldset class="automation-builder">
                <legend>{{ __('proactive_messages.form.conditions') }}</legend>
                <p class="field-help">{{ __('proactive_messages.form.conditions_help') }}</p>

                <div class="meta-grid">
                    <div class="field">
                        <label for="url_contains">{{ __('proactive_messages.form.url_contains') }}</label>
                        <input id="url_contains" name="url_contains" maxlength="255" value="{{ old('url_contains', $proactiveMessageRule?->url_contains) }}" placeholder="/pricing" lang="">
                        <p class="field-help">{{ __('proactive_messages.form.url_help') }}</p>
                        @error('url_contains')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="referrer_contains">{{ __('proactive_messages.form.referrer_contains') }}</label>
                        <input id="referrer_contains" name="referrer_contains" maxlength="255" value="{{ old('referrer_contains', $proactiveMessageRule?->referrer_contains) }}" placeholder="example.com" lang="">
                        <p class="field-help">{{ __('proactive_messages.form.referrer_help') }}</p>
                        @error('referrer_contains')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="delay_seconds">{{ __('proactive_messages.form.delay_seconds') }}</label>
                        <input id="delay_seconds" name="delay_seconds" type="number" min="0" max="300" step="1" value="{{ old('delay_seconds', $proactiveMessageRule?->delay_seconds ?? 30) }}" required>
                        @error('delay_seconds')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="minimum_visit_count">{{ __('proactive_messages.form.minimum_visit_count') }}</label>
                        <input id="minimum_visit_count" name="minimum_visit_count" type="number" min="1" max="50" step="1" value="{{ old('minimum_visit_count', $proactiveMessageRule?->minimum_visit_count ?? 1) }}" required>
                        @error('minimum_visit_count')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>

                <label class="automation-enabled" for="requires_available_agent">
                    <input type="hidden" name="requires_available_agent" value="0">
                    <input id="requires_available_agent" name="requires_available_agent" type="checkbox" value="1" @checked(old('requires_available_agent', $proactiveMessageRule?->requires_available_agent ?? true))>
                    <span>
                        <strong>{{ __('proactive_messages.form.requires_agent') }}</strong>
                        <small>{{ __('proactive_messages.form.requires_agent_help') }}</small>
                    </span>
                </label>
            </fieldset>

            <fieldset class="automation-builder">
                <legend>{{ __('proactive_messages.form.caps') }}</legend>
                <p class="field-help">{{ __('proactive_messages.form.caps_help') }}</p>

                <div class="meta-grid">
                    <div class="field">
                        <label for="frequency_cap_hours">{{ __('proactive_messages.form.frequency_cap_hours') }}</label>
                        <input id="frequency_cap_hours" name="frequency_cap_hours" type="number" min="1" max="720" step="1" value="{{ old('frequency_cap_hours', $proactiveMessageRule ? intdiv($proactiveMessageRule->frequency_cap_minutes, 60) : 168) }}" required>
                        @error('frequency_cap_hours')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label for="dismissal_snooze_days">{{ __('proactive_messages.form.dismissal_snooze_days') }}</label>
                        <input id="dismissal_snooze_days" name="dismissal_snooze_days" type="number" min="1" max="90" step="1" value="{{ old('dismissal_snooze_days', $proactiveMessageRule ? intdiv($proactiveMessageRule->dismissal_snooze_minutes, 1440) : 30) }}" required>
                        @error('dismissal_snooze_days')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </fieldset>

            <label class="automation-enabled" for="is_enabled">
                <input type="hidden" name="is_enabled" value="0">
                <input id="is_enabled" name="is_enabled" type="checkbox" value="1" @checked(old('is_enabled', $proactiveMessageRule?->is_enabled ?? false))>
                <span>
                    <strong>{{ __('proactive_messages.form.enabled') }}</strong>
                    <small>{{ __('proactive_messages.form.enabled_help') }}</small>
                </span>
            </label>

            <div class="section-actions">
                <button class="button" type="submit">{{ $editing ? __('proactive_messages.form.save') : __('proactive_messages.form.create') }}</button>
                <a class="button secondary" href="{{ route('dashboard.sites.proactive-messages.index', $site) }}">{{ __('proactive_messages.form.cancel') }}</a>
            </div>
        </form>
    </section>

    @if ($editing)
        <section class="section danger-zone" aria-labelledby="delete-proactive-rule-heading">
            <div class="section-header">
                <h2 id="delete-proactive-rule-heading">{{ __('proactive_messages.delete.heading') }}</h2>
            </div>
            <p class="lede">{{ __('proactive_messages.delete.body') }}</p>
            <form method="POST" action="{{ route('dashboard.sites.proactive-messages.destroy', [$site, $proactiveMessageRule]) }}">
                @csrf
                @method('DELETE')
                <button class="button danger" type="submit">{{ __('proactive_messages.delete.action') }}</button>
            </form>
        </section>
    @endif
</x-layouts.app>
