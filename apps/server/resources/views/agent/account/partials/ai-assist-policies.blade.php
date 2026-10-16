@php
    $policy = $assist['policy'];
    $accountPolicy = $policy['account'];
    $oldPolicyForm = old('_policy_form');
    $accountPolicyConflict = $oldPolicyForm === 'account' && $errors->has('policy_token');
    $sitePolicyConflict = $oldPolicyForm === 'sites' && ($errors->has('policy_token') || $errors->has('account_policy_token') || $errors->has('site_tokens') || $errors->has('site_tokens.*') || $errors->has('site_scope'));
    $oldSiteIds = $oldPolicyForm === 'sites' ? old('site_ids', []) : [];
    $selectedSiteIds = array_map('strval', is_array($oldSiteIds) ? $oldSiteIds : []);
    $siteTokens = $oldPolicyForm === 'sites' ? old('site_tokens', []) : array_column($policy['sites'], 'token', 'site_id');
    $siteTokens = is_array($siteTokens) ? $siteTokens : [];
    $siteContextCeiling = min($policy['installation_context_limit'], $accountPolicy['max_context_characters'] ?? $policy['installation_context_limit']);
    $siteArticlesPermitted = $accountPolicy['relevant_articles'] && $accountPolicy['article_context_consent'];
    $bulkSettings = [...$accountPolicy, 'mode' => 'prepare', 'relevant_articles' => false, 'article_context_consent' => false, 'max_context_characters' => null];
@endphp

<section class="section" aria-labelledby="ai-policy-heading" data-ai-policy-section>
    <div class="section-header">
        <h2 id="ai-policy-heading">{{ __('ai_assist.policy.heading') }}</h2>
        <span class="lede">{{ __('ai_assist.policy.account_scope') }}</span>
    </div>
    <div class="notice-copy ai-assist-policy-intro">
        <p>{{ __('ai_assist.policy.prepare_notice') }}</p>
        <p class="field-help">{{ __('ai_assist.policy.data_help') }}</p>
        @if ($assist['active']['provider'])
            <p class="field-help">{{ __('ai_assist.policy.processor') }} <strong lang="">{{ $providerLabels[$assist['active']['provider']] ?? $assist['active']['provider'] }} / {{ $assist['active']['model'] }}</strong></p>
        @endif
        @unless ($policy['explicit'])<p class="field-help">{{ __('ai_assist.policy.legacy_help') }}</p>@endunless
        @unless ($policy['connection_ready'])<p class="field-help"><a href="#ai-connection-heading">{{ __('ai_assist.policy.connection_required') }}</a></p>@endunless
        @unless ($policy['can_update_account'])<p class="field-help"><strong>{{ __('ai_assist.policy.account_restricted') }}</strong></p>@endunless
    </div>
    <form class="section-form ai-assist-policy-form" method="POST" action="{{ route('dashboard.account.ai-assist.policy.account') }}" data-ai-policy-form>
        @csrf
        <input name="policy_token" type="hidden" value="{{ $oldPolicyForm === 'account' ? old('policy_token', '') : $policy['account_token'] }}">
        <fieldset class="ai-assist-policy-controls" @disabled(! $policy['can_update_account'])>
            <legend class="sr-only">{{ __('ai_assist.policy.account_scope') }}</legend>
            @include('agent.account.partials.ai-assist-policy-fields', ['prefix' => 'ai-policy-account', 'settings' => $accountPolicy, 'restorePolicyInput' => $oldPolicyForm === 'account', 'contextCeiling' => $policy['installation_context_limit'], 'articlesPermitted' => true])
            <button class="button" type="submit" @disabled($accountPolicyConflict)>{{ __('ai_assist.policy.save_account') }}</button>
        </fieldset>
        <p class="field-help">{{ __('ai_assist.policy.save_help') }}</p>
        @if ($accountPolicyConflict)
            <p class="field-error">{{ __('ai_assist.validation.policy_conflict') }} <a href="{{ route('dashboard.account.ai-assist.show') }}">{{ __('ai_assist.actions.reload') }}</a></p>
        @endif
    </form>
</section>

<section class="section" aria-labelledby="ai-policy-sites-heading">
    <div class="section-header"><h2 id="ai-policy-sites-heading">{{ __('ai_assist.policy.sites_heading') }}</h2></div>
    <div class="notice-copy">
        <p>{{ __('ai_assist.policy.sites_help') }}</p>
        <p class="field-help">{{ __('ai_assist.policy.site_defaults') }}</p>
    </div>
    @if ($policy['sites'] === [])
        <p class="empty">{{ __('ai_assist.policy.no_sites') }}</p>
    @else
        <x-details-disclosure class="ai-assist-policy-site-disclosure" :open="$oldPolicyForm === 'sites'" :summary="__('ai_assist.policy.manage_sites', ['count' => \App\Support\ReaderNumber::count(count($policy['sites']))])">
            @unless ($policy['explicit'])<p class="field-help">{{ __('ai_assist.policy.account_first') }}</p>@endunless
            <form class="ai-assist-policy-form" method="POST" action="{{ route('dashboard.account.ai-assist.policy.sites') }}" data-ai-policy-form>
                @csrf
                <input type="hidden" name="account_policy_token" value="{{ $oldPolicyForm === 'sites' ? old('account_policy_token', '') : $policy['account_token'] }}">
                <fieldset class="ai-assist-policy-controls" @disabled(! $policy['explicit'])>
                    <legend class="sr-only">{{ __('ai_assist.policy.sites_heading') }}</legend>
                    <fieldset class="field ai-assist-policy-site-picker">
                        <legend>{{ __('ai_assist.policy.select_sites') }}</legend>
                        <div class="ai-assist-policy-select-actions" hidden data-ai-policy-select-actions>
                            <button type="button" class="button secondary" data-ai-policy-select-all>{{ __('ai_assist.policy.select_all') }}</button>
                            <button type="button" class="button secondary" data-ai-policy-select-none>{{ __('ai_assist.policy.select_none') }}</button>
                            <span class="lede" role="status" aria-live="polite" data-ai-policy-selected-count data-count-label="{{ __('ai_assist.policy.selected_count', ['count' => '%count%']) }}"></span>
                        </div>
                        @foreach ($policy['sites'] as $sitePolicy)
                            <div class="ai-assist-policy-site-row">
                                <input type="hidden" name="site_tokens[{{ $sitePolicy['site_id'] }}]" value="{{ $siteTokens[$sitePolicy['site_id']] ?? '' }}">
                                <label class="check-row" for="ai-policy-site-{{ $sitePolicy['site_id'] }}">
                                    <input id="ai-policy-site-{{ $sitePolicy['site_id'] }}" name="site_ids[]" type="checkbox" value="{{ $sitePolicy['site_id'] }}" @checked(in_array((string) $sitePolicy['site_id'], $selectedSiteIds, true)) data-ai-policy-site aria-describedby="ai-policy-site-{{ $sitePolicy['site_id'] }}-state">
                                    <span lang="">{{ $sitePolicy['name'] }}</span>
                                </label>
                                <p class="field-help" id="ai-policy-site-{{ $sitePolicy['site_id'] }}-state">
                                    {{ __('ai_assist.policy.site_state', ['stored' => __('ai_assist.policy.modes.'.$sitePolicy['mode']), 'effective' => __('ai_assist.policy.modes.'.$sitePolicy['effective_mode'])]) }}
                                    · {{ $sitePolicy['article_context_allowed'] ? __('ai_assist.policy.site_articles_allowed') : __('ai_assist.policy.site_articles_off') }}
                                </p>
                            </div>
                        @endforeach
                        @if ($oldPolicyForm === 'sites') @error('site_ids')<p class="field-error">{{ $message }}</p>@enderror @endif
                    </fieldset>
                    @include('agent.account.partials.ai-assist-policy-fields', ['prefix' => 'ai-policy-sites', 'settings' => $bulkSettings, 'restorePolicyInput' => $oldPolicyForm === 'sites', 'contextCeiling' => $siteContextCeiling, 'articlesPermitted' => $siteArticlesPermitted])
                    <button class="button secondary" type="submit" @disabled($sitePolicyConflict)>{{ __('ai_assist.policy.save_sites') }}</button>
                </fieldset>
                <p class="field-help">{{ __('ai_assist.policy.bulk_help') }}</p>
                @if ($sitePolicyConflict)
                    <p class="field-error">{{ __('ai_assist.validation.policy_conflict') }} <a href="{{ route('dashboard.account.ai-assist.show') }}">{{ __('ai_assist.actions.reload') }}</a></p>
                @endif
            </form>
        </x-details-disclosure>
    @endif
</section>

<style>
    .ai-assist-policy-intro { border-bottom: 1px solid var(--border); }
    .ai-assist-policy-controls { min-width: 0; margin: 0; padding: 0; border: 0; }
    .ai-assist-policy-controls > .field:first-of-type { margin-top: 0; }
    .ai-assist-policy-controls > .button { margin-top: var(--wf-space-4); }
    .ai-assist-policy-modes .check-row { display: inline-flex; margin: var(--wf-space-1) var(--wf-space-4) 0 0; }
    .ai-assist-policy-task-group { margin-top: var(--wf-space-4); }
    .ai-assist-policy-tasks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--wf-space-2) var(--wf-space-4); }
    .ai-assist-policy-tasks .check-row { margin-top: 0; }
    .ai-assist-policy-task-help { margin-left: var(--wf-space-5); }
    .ai-assist-policy-article-context { margin-top: var(--wf-space-3); padding: var(--wf-space-3); border-left: var(--wf-rail) solid var(--wf-rule-firm); background: var(--surface-muted); }
    .ai-assist-policy-article-context > .field-help:first-child { margin-top: 0; }
    .ai-assist-policy-context { max-width: 420px; }
    .ai-assist-policy-site-disclosure { padding: 0 20px 20px; }
    .ai-assist-policy-site-disclosure > summary { margin-bottom: var(--wf-space-3); }
    .ai-assist-policy-site-row { padding: var(--wf-space-2) 0; border-bottom: 1px solid var(--border); }
    .ai-assist-policy-site-row .check-row { margin: 0; }
    .ai-assist-policy-site-row .field-help { margin-left: var(--wf-space-5); }
    .ai-assist-policy-select-actions { display: flex; flex-wrap: wrap; align-items: center; gap: var(--wf-space-2); margin-bottom: var(--wf-space-2); }
    .ai-assist-policy-form .ai-assist-policy-select-actions .button { margin-top: 0; }
    @media (max-width: 640px) { .ai-assist-policy-tasks { grid-template-columns: 1fr; } }
</style>
<script>
    (function () {
        document.querySelectorAll('[data-ai-policy-form]').forEach(function (form) {
            var articles = form.querySelector('[data-ai-policy-articles]');
            var consent = form.querySelector('[data-ai-policy-consent]');
            function requireConsent() {
                consent.required = ! articles.disabled && articles.checked;
            }
            articles.addEventListener('change', requireConsent);
            requireConsent();
            var controls = form.querySelector('[data-ai-policy-select-actions]');
            if (! controls) return;
            var sites = Array.from(form.querySelectorAll('[data-ai-policy-site]'));
            var selected = form.querySelector('[data-ai-policy-selected-count]');
            function countSelected() {
                var count = sites.filter(function (site) { return site.checked; }).length;
                var formatted = new Intl.NumberFormat(document.documentElement.lang || 'en').format(count);
                selected.textContent = selected.dataset.countLabel.replace('%count%', formatted);
            }
            controls.hidden = false;
            controls.querySelector('[data-ai-policy-select-all]').addEventListener('click', function () {
                sites.forEach(function (site) { if (! site.matches(':disabled')) site.checked = true; });
                countSelected();
            });
            controls.querySelector('[data-ai-policy-select-none]').addEventListener('click', function () {
                sites.forEach(function (site) { if (! site.matches(':disabled')) site.checked = false; });
                countSelected();
            });
            sites.forEach(function (site) { site.addEventListener('change', countSelected); });
            countSelected();
        });
    })();
</script>
