<x-layouts.account :title="__('ai_assist.title')">
    @php
        $form = $assist['form'];
        // Provider brands are interface data, not translated catalogue copy.
        $providerLabels = ['anthropic' => 'Anthropic', 'gemini' => 'Gemini', 'ollama' => 'Ollama', 'openai' => 'OpenAI', 'openai-compatible' => 'OpenAI-compatible', 'openrouter' => 'OpenRouter'];
        $source = old('source', $form['source']);
        $oldInput = session()->getOldInput();
        $revision = old('expected_revision', $form['expected_revision']);
        $editorToken = old('editor_token', $form['editor_token']);
        // Names are cosmetic. Compare processing values using the lifecycle's
        // normalization, while keeping reviewed null revisions and tokens exact.
        $processingChanged = $source !== $form['source']
            || $revision !== $form['expected_revision'] || $editorToken !== $form['editor_token'];
        $normalizeProcessing = [
            'provider' => static fn ($value): string => strtolower(trim((string) $value)),
            'model' => static fn ($value): string => trim((string) $value),
            'endpoint' => static fn ($value): string => rtrim(trim((string) $value), '/'),
            'openrouter_provider' => static fn ($value): string => strtolower(trim((string) $value)),
        ];
        if ($source === 'account') {
            foreach ($normalizeProcessing as $field => $normalize) {
                if (array_key_exists($field, $oldInput) && $normalize($oldInput[$field]) !== $normalize($form[$field])) {
                    $processingChanged = true;
                }
            }
            // The credential itself is never flashed back into this page.
            $processingChanged = $processingChanged || ($oldInput['_ai_assist_credential_edit'] ?? false) === true
                || ($oldInput['_ai_assist_redacted_endpoint_edit'] ?? false) === true;
        }
        $selectedProvider = $normalizeProcessing['provider'](old('provider', $form['provider']));
        $state = $processingChanged ? 'stale' : $assist['state'];
        $canSave = $assist['test']['can_save'] && ! $processingChanged;
        $failure = $assist['test']['failure_code'] ?? 'provider_unavailable';
        $failureKey = 'ai_assist.failures.'.$failure;
        $failureCopy = \Illuminate\Support\Facades\Lang::has($failureKey) ? __($failureKey) : __('ai_assist.failures.provider_unavailable');
        $count = static fn (int $value): string => \App\Support\ReaderNumber::count($value);
    @endphp

    <x-page-header :title="__('ai_assist.title')" :subtitle="__('ai_assist.subtitle')" />

    @if (session('status'))
        <p class="status-message" role="status">{{ __(session('status')) }}</p>
    @endif
    @if ($errors->any())
        <div class="ai-assist-errors" role="alert" tabindex="-1" data-ai-errors>
            <strong>{{ in_array(old('_policy_form'), ['account', 'sites'], true) ? __('ai_assist.policy.error_heading') : __('ai_assist.validation.heading') }}</strong>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <p class="field-help"><a href="{{ route('dashboard.account.ai-assist.show') }}">{{ __('ai_assist.actions.reload') }}</a> · {{ __('ai_assist.validation.reload_help') }}</p>
        </div>
    @endif

    <div class="ai-assist-page" data-ai-assist>
        <section class="section" aria-labelledby="ai-connection-heading">
            <div class="section-header">
                <h2 id="ai-connection-heading">{{ __('ai_assist.connection.heading') }}</h2>
                <span class="lede">{{ __('ai_assist.connection.account_scope') }}</span>
            </div>

            <div class="ai-assist-active">
                <div>
                    <strong>{{ __('ai_assist.active.heading') }}</strong>
                    <p class="field-help">
                        @if ($assist['active']['configured'])
                            {{ match ($assist['active']['source']) { 'account' => __('ai_assist.source.own'), 'installation' => __('ai_assist.source.installation'), default => __('ai_assist.source.unknown') } }}
                            @if ($assist['active']['provider']) · <span lang="">{{ $providerLabels[$assist['active']['provider']] ?? $assist['active']['provider'] }} / {{ $assist['active']['model'] }}</span>@endif
                        @else
                            {{ __('ai_assist.active.none') }}
                        @endif
                    </p>
                </div>
                <span class="readiness-status" data-status="{{ ! $assist['active']['configured'] ? 'unset' : (! $assist['active']['enabled'] ? 'disabled' : ($assist['active']['available'] ? 'ready' : 'attention')) }}">
                    {{ ! $assist['active']['configured'] ? __('ai_assist.active.not_configured') : (! $assist['active']['enabled'] ? __('ai_assist.active.disabled') : ($assist['active']['available'] ? __('ai_assist.active.enabled') : __('ai_assist.active.unavailable'))) }}
                </span>
            </div>

            @if ($assist['active']['legacy'] && $assist['active']['available'])
                <div class="notice-copy" data-ai-legacy-notice><p>{{ __('ai_assist.connection.legacy_help') }}</p></div>
            @endif

            <form class="section-form ai-assist-form" method="POST" action="{{ route('dashboard.account.ai-assist.propose') }}" autocomplete="off" data-ai-connection-form>
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $revision }}">
                <input type="hidden" name="editor_token" value="{{ $editorToken }}">
                <fieldset class="field ai-assist-source">
                    <legend>{{ __('ai_assist.source.heading') }}</legend>
                    <label class="check-row" for="ai-source-installation">
                        <input id="ai-source-installation" name="source" type="radio" value="installation" @checked($source === 'installation') @disabled(! $assist['installation']['available'])>
                        <span>{{ __('ai_assist.source.installation') }}</span>
                    </label>
                    <label class="check-row" for="ai-source-account">
                        <input id="ai-source-account" name="source" type="radio" value="account" @checked($source === 'account')>
                        <span>{{ __('ai_assist.source.own') }}</span>
                    </label>
                    <p class="field-help">
                        @if ($assist['installation']['available'])
                            {{ __('ai_assist.source.installation_available') }} <span lang="">{{ $providerLabels[$assist['installation']['provider']] ?? $assist['installation']['provider'] }} / {{ $assist['installation']['model'] }}</span>
                        @else
                            {{ __('ai_assist.source.installation_unavailable') }}
                        @endif
                    </p>
                    @error('source')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="ai-assist-connection-fields">
                <div class="field">
                    <label for="ai-name">{{ __('ai_assist.fields.name') }}</label>
                    <input id="ai-name" name="name" type="text" maxlength="100" value="{{ old('name', $form['name']) }}" lang="">
                    @error('name')<p class="field-error">{{ $message }}</p>@enderror
                </div>

                <fieldset class="ai-assist-own" data-ai-own-fields>
                    <legend class="sr-only">{{ __('ai_assist.source.own') }}</legend>
                    <div class="ai-assist-grid">
                        <div class="field">
                            <label for="ai-provider">{{ __('ai_assist.fields.provider') }}</label>
                            <select id="ai-provider" name="provider" data-ai-provider>
                                <option value="">{{ __('ai_assist.fields.choose_provider') }}</option>
                                @foreach ($assist['providers'] as $provider)
                                    <option value="{{ $provider }}" @selected($selectedProvider === $provider) lang="">{{ $providerLabels[$provider] }}</option>
                                @endforeach
                            </select>
                            @error('provider')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="ai-model">{{ __('ai_assist.fields.model') }}</label>
                            <input id="ai-model" name="model" type="text" maxlength="255" value="{{ old('model', $form['model']) }}" list="ai-models" lang="" aria-describedby="ai-model-help">
                            <datalist id="ai-models">@foreach ($assist['models'] as $model)<option value="{{ $model }}"></option>@endforeach</datalist>
                            <p class="field-help" id="ai-model-help">{{ __('ai_assist.fields.model_help') }}</p>
                            @error('model')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="ai-endpoint">{{ __('ai_assist.fields.endpoint') }}</label>
                            <input id="ai-endpoint" name="endpoint" type="url" maxlength="2048" value="{{ old('endpoint', $form['endpoint']) }}" placeholder="https://api.example.com/v1" lang="" aria-describedby="ai-endpoint-help">
                            <p class="field-help" id="ai-endpoint-help">{{ __('ai_assist.fields.endpoint_help') }}</p>
                            @error('endpoint')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field ai-assist-wide" data-ai-router-fields>
                            <label for="ai-router-provider">{{ __('ai_assist.fields.openrouter_provider') }}</label>
                            <input id="ai-router-provider" name="openrouter_provider" type="text" maxlength="255" value="{{ old('openrouter_provider', $form['openrouter_provider']) }}" lang="" aria-describedby="ai-router-help">
                            <p class="field-help" id="ai-router-help">{{ __('ai_assist.fields.router_help') }}</p>
                            @error('openrouter_provider')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field ai-assist-wide">
                            <div class="ai-assist-key-heading">
                                <label for="ai-api-key">{{ __('ai_assist.fields.api_key') }}</label>
                                <span class="lede" data-ai-credential-status>{{ __('ai_assist.credentials.'.$form['credential_status']) }}</span>
                            </div>
                            {{-- Write only: never restore a submitted key or print a saved value. --}}
                            <input id="ai-api-key" name="api_key" type="password" maxlength="4096" value="" autocomplete="new-password" spellcheck="false" aria-describedby="ai-key-help">
                            <p class="field-help" id="ai-key-help">{{ __('ai_assist.fields.key_help') }}</p>
                            <label class="check-row" for="ai-clear-key">
                                <input id="ai-clear-key" name="clear_api_key" type="checkbox" value="1" @checked(old('clear_api_key')) data-ai-clear-key>
                                <span>{{ __('ai_assist.fields.clear_key') }}</span>
                            </label>
                            @error('api_key')<p class="field-error">{{ $message }}</p>@enderror
                            @error('clear_api_key')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </fieldset>
                </div>

                <div class="ai-assist-test" role="status" aria-live="polite" aria-atomic="true" data-ai-test-status data-state="{{ $state }}"
                    data-stale-label="{{ __('ai_assist.states.settings_changed') }}" data-stale-help="{{ __('ai_assist.states.stale_help') }}"
                    data-preparing-label="{{ __('ai_assist.states.preparing') }}" data-preparing-help="{{ __('ai_assist.states.preparing_help') }}">
                    <span class="ai-assist-state-dot" aria-hidden="true"></span>
                    <div>
                        <strong data-ai-state-label>{{ __('ai_assist.states.'.($state === 'stale' ? 'settings_changed' : $state)) }}</strong>
                        <p class="field-help" data-ai-state-help>{{ $state === 'unavailable' ? $failureCopy : __('ai_assist.states.'.$state.'_help') }}</p>
                        @if ($assist['test']['tested_at'])<p class="field-help">{{ __('ai_assist.states.last_tested', ['time' => $assist['test']['tested_at']]) }}</p>@endif
                    </div>
                </div>
                <p class="field-help">{{ __('ai_assist.connection.sample_help') }}</p>
                <div class="ai-assist-actions">
                    <button class="button secondary" type="submit" data-ai-action="propose">{{ __('ai_assist.actions.keep_draft') }}</button>
                    <button class="button secondary" type="submit" formaction="{{ route('dashboard.account.ai-assist.discover') }}" data-ai-action="discover">{{ __('ai_assist.actions.discover') }}</button>
                    <button class="button secondary" type="submit" formaction="{{ route('dashboard.account.ai-assist.test') }}" data-ai-action="test" @disabled($state === 'preparing')>{{ __('ai_assist.actions.test') }}</button>
                    <button class="button" type="submit" formaction="{{ route('dashboard.account.ai-assist.activate') }}" data-ai-action="activate" @disabled(! $canSave)>{{ __('ai_assist.actions.save') }}</button>
                </div>
                <p class="field-help">{{ __('ai_assist.connection.save_help') }}</p>
            </form>

            <div class="ai-assist-management">
                @if ($form['expected_revision'])
                    <form method="POST" action="{{ route('dashboard.account.ai-assist.discard') }}" data-ai-management-form>
                        @csrf
                        <input type="hidden" name="expected_revision" value="{{ $revision }}">
                        <input type="hidden" name="editor_token" value="{{ $editorToken }}">
                        <button class="button secondary" type="submit">{{ __('ai_assist.actions.discard') }}</button>
                    </form>
                @endif
                @if ($assist['active']['configured'])
                    <form method="POST" action="{{ route('dashboard.account.ai-assist.enabled') }}" data-ai-management-form>
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $assist['active']['enabled'] ? '0' : '1' }}">
                        <input type="hidden" name="editor_token" value="{{ $editorToken }}">
                        <button class="button secondary" type="submit">{{ $assist['active']['enabled'] ? __('ai_assist.actions.disable') : __('ai_assist.actions.enable') }}</button>
                    </form>
                    <form method="POST" action="{{ route('dashboard.account.ai-assist.disconnect') }}" data-ai-management-form>
                        @csrf
                        <input type="hidden" name="expected_revision" value="{{ $revision }}">
                        <input type="hidden" name="editor_token" value="{{ $editorToken }}">
                        <button class="button danger" type="submit">{{ __('ai_assist.actions.disconnect') }}</button>
                    </form>
                @endif
                <p class="field-help">{{ __('ai_assist.connection.stop_help') }}</p>
            </div>
        </section>

        @include('agent.account.partials.ai-assist-policies')

        <section class="section" aria-labelledby="ai-usage-heading">
            <div class="section-header">
                <h2 id="ai-usage-heading">{{ __('ai_assist.usage.heading') }}</h2>
                <span class="lede">{{ __('ai_assist.usage.utc') }}</span>
            </div>
            @if ($assist['usage_unavailable'])
                <p class="empty">{{ __('ai_assist.usage.unavailable') }}</p>
            @else
                @php
                    $usage = $assist['usage']['account'];
                @endphp
                <dl class="ai-assist-usage">
                    <div><dt>{{ __('ai_assist.usage.requests') }}</dt><dd>{{ $count($usage['charged_requests']) }} <span class="lede">{{ __('ai_assist.usage.reserved', ['count' => $count($usage['held_requests'])]) }}</span></dd></div>
                    <div><dt>{{ __('ai_assist.usage.tokens') }}</dt><dd>{{ $count($usage['charged_tokens']) }} <span class="lede">{{ __('ai_assist.usage.reserved', ['count' => $count($usage['held_tokens'])]) }}</span></dd></div>
                    <div><dt>{{ __('ai_assist.usage.reported_tokens') }}</dt><dd>{{ $count($assist['usage']['observed_tokens']) }}</dd></div>
                    <div><dt>{{ __('ai_assist.usage.concurrent') }}</dt><dd>{{ $count($usage['concurrency']) }}</dd></div>
                </dl>
                <p class="ai-assist-usage-note field-help">{{ __('ai_assist.usage.accounting_help', ['day' => $assist['usage']['period_day']]) }}</p>
            @endif
            @if ($assist['limits']['unavailable'])
                <p class="empty">{{ __('ai_assist.limits.unavailable') }}</p>
            @else
                <form class="section-form ai-assist-limits" method="POST" action="{{ route('dashboard.account.ai-assist.limits') }}" data-ai-management-form>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="limits_token" value="{{ old('limits_token', $assist['limits']['limits_token']) }}">
                    @error('limits_token')<p class="field-error">{{ $message }} <a href="{{ route('dashboard.account.ai-assist.show') }}">{{ __('ai_assist.actions.reload') }}</a></p>@enderror
                    <p class="field-help">{{ __('ai_assist.limits.help') }}</p>
                    <div class="ai-assist-grid">
                        @foreach (['daily_requests', 'daily_tokens', 'concurrency', 'max_output_tokens'] as $field)
                            <div class="field">
                                <label for="ai-limit-{{ $field }}">{{ __('ai_assist.limits.'.$field) }}</label>
                                <input id="ai-limit-{{ $field }}" name="{{ $field }}" type="number" min="0" max="{{ $assist['limits']['installation'][$field] }}" step="1" inputmode="numeric" value="{{ old($field, $assist['limits']['overrides'][$field]) }}" placeholder="{{ $assist['limits']['installation'][$field] }}" aria-describedby="ai-limit-{{ $field }}-help">
                                <p class="field-help" id="ai-limit-{{ $field }}-help">{{ __('ai_assist.limits.ceiling', ['ceiling' => $count($assist['limits']['installation'][$field]), 'effective' => $count($assist['limits']['effective'][$field])]) }}</p>
                                @error($field)<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    <button class="button secondary" type="submit">{{ __('ai_assist.actions.save_limits') }}</button>
                </form>
            @endif
        </section>

    </div>

    <style>
        .ai-assist-page { max-width: 920px; }
        .ai-assist-page [hidden] { display: none !important; }
        .ai-assist-active, .ai-assist-management { padding: var(--wf-space-4) 20px; border-bottom: 1px solid var(--border); }
        .ai-assist-active { display: flex; align-items: center; justify-content: space-between; gap: var(--wf-space-4); }
        .ai-assist-active strong, .ai-assist-test strong { font-size: var(--wf-text-ui); }
        .ai-assist-source { padding-bottom: var(--wf-space-4) !important; border-bottom: 1px solid var(--border) !important; }
        .ai-assist-source .check-row { display: inline-flex; margin: var(--wf-space-1) var(--wf-space-5) 0 0; }
        .ai-assist-own { border: 0; padding: 0; margin: 0; min-width: 0; display: contents; }
        .ai-assist-own > .ai-assist-grid { display: contents; }
        .ai-assist-connection-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--wf-space-4); margin-top: var(--wf-space-4); }
        .ai-assist-connection-fields:has(.ai-assist-own[hidden]) > .field { grid-column: 1 / -1; }
        .ai-assist-connection-fields .field { margin: 0; min-width: 0; }
        .ai-assist-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--wf-space-4); }
        .ai-assist-grid .field { margin: 0; min-width: 0; }
        .ai-assist-wide { grid-column: 1 / -1; }
        .ai-assist-page .field input:not([type="checkbox"], [type="radio"]), .ai-assist-page .field select { padding: var(--wf-space-2) var(--wf-space-3); border-radius: var(--wf-radius); background: var(--surface-muted); }
        .ai-assist-key-heading, .ai-assist-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--wf-space-2) var(--wf-space-4); }
        .ai-assist-key-heading { justify-content: space-between; }
        .ai-assist-key-heading label { margin-bottom: 0; }
        .ai-assist-key-heading + input { margin-top: var(--wf-space-2); }
        .ai-assist-test { display: flex; gap: var(--wf-space-2); margin-top: var(--wf-space-5); padding-top: var(--wf-space-4); border-top: 1px solid var(--border); }
        .ai-assist-state-dot { flex: 0 0 7px; width: 7px; height: 7px; margin-top: 7px; border-radius: 50%; background: var(--wf-signal-rest); }
        .ai-assist-test[data-state="ready"] .ai-assist-state-dot { background: var(--wf-signal-go); }
        .ai-assist-test[data-state="stale"] .ai-assist-state-dot { background: var(--wf-signal-hold); }
        .ai-assist-test[data-state="preparing"] .ai-assist-state-dot { background: var(--wf-site-blue); }
        .ai-assist-test[data-state="unavailable"] .ai-assist-state-dot { background: var(--wf-signal-stop); }
        .ai-assist-actions { gap: var(--wf-space-2); margin-top: var(--wf-space-4); }
        .ai-assist-form .ai-assist-actions .button { margin-top: 0; }
        .ai-assist-management { display: flex; flex-wrap: wrap; gap: var(--wf-space-2); border-top: 1px solid var(--border); border-bottom: 0; }
        .ai-assist-management .field-help { flex-basis: 100%; }
        .ai-assist-usage { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 0; padding: var(--wf-space-4) 20px 0; gap: var(--wf-space-4); }
        .ai-assist-usage dt { color: var(--muted); font-size: var(--wf-text-ui); }
        .ai-assist-usage dd { margin: var(--wf-space-1) 0 0; font-variant-numeric: tabular-nums; }
        .ai-assist-usage dd .lede { display: block; }
        .ai-assist-usage-note { padding: 0 20px; }
        .ai-assist-limits > .field-help { margin-top: 0; }
        .ai-assist-limits .ai-assist-grid { margin-top: var(--wf-space-4); }
        .ai-assist-errors { margin-bottom: var(--wf-space-4); border-left: var(--wf-rail) solid var(--danger); padding: var(--wf-space-3) var(--wf-space-4); }
        .ai-assist-errors ul { margin: var(--wf-space-2) 0 0; padding-left: var(--wf-space-4); }
        @media (max-width: 640px) {
            .ai-assist-grid, .ai-assist-connection-fields, .ai-assist-usage { grid-template-columns: 1fr; }
            .ai-assist-active { align-items: flex-start; }
            .ai-assist-actions .button { flex: 1 1 auto; }
        }
    </style>
    <script>
        (function () {
            var page = document.querySelector('[data-ai-assist]');
            if (! page) return;
            var form = page.querySelector('[data-ai-connection-form]');
            var ownFields = form.querySelector('[data-ai-own-fields]');
            var routerFields = form.querySelector('[data-ai-router-fields]');
            var provider = form.querySelector('[data-ai-provider]');
            var status = form.querySelector('[data-ai-test-status]');
            var save = form.querySelector('[data-ai-action="activate"]');
            var test = form.querySelector('[data-ai-action="test"]');
            var key = form.elements.api_key;
            var clear = form.querySelector('[data-ai-clear-key]');
            var busy = false;

            function processingSnapshot() {
                var source = form.querySelector('[name="source"]:checked')?.value || '';
                var own = source === 'account';
                return JSON.stringify([source, own ? provider.value : '',
                    own ? form.elements.model.value.trim() : '',
                    own ? form.elements.endpoint.value.trim().replace(/\/+$/, '') : '',
                    own && provider.value === 'openrouter' ? form.elements.openrouter_provider.value.trim().toLowerCase() : '',
                    own && key.value.trim() !== '', own && clear.checked]);
            }
            var checkingSnapshot = status.dataset.state === 'preparing' ? processingSnapshot() : null;

            function sourceFields() {
                var own = form.querySelector('[name="source"]:checked')?.value === 'account';
                ownFields.hidden = ! own;
                ownFields.disabled = ! own;
                routerFields.hidden = provider.value !== 'openrouter';
                routerFields.querySelector('input').disabled = ! own || provider.value !== 'openrouter';
                form.querySelector('[data-ai-action="discover"]').hidden = ! own;
            }
            function state(value) {
                status.dataset.state = value;
                status.querySelector('[data-ai-state-label]').textContent = status.dataset[value + 'Label'];
                status.querySelector('[data-ai-state-help]').textContent = status.dataset[value + 'Help'];
            }
            function edited(event) {
                if (busy || ['name', 'expected_revision', 'editor_token', '_token'].includes(event.target.name)) return;
                // A key is never copied, revealed, stored, or added to a URL.
                // Selecting Clear removes only this browser's replacement.
                if (event.target === clear && clear.checked) key.value = '';
                if (event.target === key && key.value !== '') clear.checked = false;
                save.disabled = true;
                // A reloaded running test still owns the unchanged proposal.
                // Cosmetic names do not create a new processing revision.
                test.disabled = checkingSnapshot !== null && processingSnapshot() === checkingSnapshot;
                state(test.disabled ? 'preparing' : 'stale');
                sourceFields();
            }
            form.addEventListener('input', edited);
            form.addEventListener('change', edited);
            page.querySelectorAll('form').forEach(function (submittedForm) {
                submittedForm.addEventListener('submit', function (event) {
                    if (busy || event.submitter?.disabled) { event.preventDefault(); return; }
                    busy = true;
                    submittedForm.setAttribute('aria-busy', 'true');
                    if (event.submitter?.dataset.aiAction === 'test') state('preparing');
                    // Keep successful controls enabled until the browser has
                    // captured the native POST, including the submitter route.
                    window.setTimeout(function () {
                        page.querySelectorAll('button[type="submit"]').forEach(function (button) { button.disabled = true; });
                    }, 0);
                });
            });
            window.addEventListener('pageshow', function (event) {
                // A restored page can contain a typed credential or a stale
                // tested state; refresh its server snapshot without replaying POST.
                if (event.persisted) { key.value = ''; window.location.reload(); }
            });
            sourceFields();
            document.querySelector('[data-ai-errors]')?.focus();
        })();
    </script>
</x-layouts.account>
