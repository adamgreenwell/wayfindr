<x-layouts.operator :title="__('operator_updates.title')">
    <x-page-header :title="__('operator_updates.title')" :subtitle="__('operator_updates.subtitle')" />

    {{-- Intent: an instance operator reviews one exact release, then follows a
         confirmed journey through interruption. Paper/ink and Plex reuse ADR
         0014; flat rules preserve hierarchy, semantic colour marks outcomes.
         The six-stage journey replaces invented percentages. Spacing uses the
         existing 4px token scale; release identifiers use Plex Mono. --}}
    <div data-operator-updates>
        <section class="section update-review-section" aria-labelledby="update-review-heading">
            <div class="section-header">
                <div>
                    <h2 id="update-review-heading">{{ __('operator_updates.review.title') }}</h2>
                    <p class="lede">{{ __('operator_updates.review.subtitle') }}</p>
                </div>
                <button class="button secondary" type="button" data-update-check>{{ __('operator_updates.actions.check') }}</button>
            </div>
            <div class="meta-grid update-release-pair">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator_updates.labels.current_release') }}</span>
                    <strong class="meta-value" @if ($updateBootstrap['current']['version'] !== null) lang="" @endif data-update-current>{{ $updateBootstrap['current']['version'] ?? __('operator_updates.labels.unknown') }}</strong>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator_updates.labels.target_release') }}</span>
                    <strong class="meta-value" data-update-target>{{ __('operator_updates.labels.unknown') }}</strong>
                </div>
            </div>
            <p data-update-message role="status" aria-live="polite">{{ __('operator_updates.review.loading') }}</p>
            <p data-update-ownership class="lede"></p>
            <div data-update-review></div>
            <noscript><p>{{ __('operator_updates.notices.manual_only') }}</p></noscript>
            <div data-update-controls hidden>
                <form data-update-auth class="update-auth" autocomplete="off">
                    <h3>{{ __('operator_updates.reauth.title') }}</h3>
                    <p class="lede">{{ __('operator_updates.reauth.body') }}</p>
                    <div class="form-grid">
                        <div class="field">
                            <label for="update-password">{{ __('operator_updates.reauth.password') }}</label>
                            <input id="update-password" type="password" name="current_password" autocomplete="current-password" required maxlength="1024">
                        </div>
                        @if ($updateBootstrap['two_factor_required'])
                            <div class="field">
                                <label for="update-code">{{ __('operator_updates.reauth.one_time_code') }}</label>
                                <input id="update-code" type="text" name="one_time_code" autocomplete="one-time-code" required maxlength="32" aria-describedby="update-code-hint">
                                <p class="field-help" id="update-code-hint">{{ __('operator_updates.reauth.code_hint') }}</p>
                            </div>
                        @endif
                    </div>
                    <button class="button secondary" type="submit">{{ __('operator_updates.actions.confirm') }}</button>
                    <p data-update-auth-message role="status"></p>
                </form>
                <p>{{ __('operator_updates.review.prepare_explanation') }}</p>
                <div class="update-actions">
                    <button class="button" type="button" data-update-prepare disabled>{{ __('operator_updates.actions.prepare') }}</button>
                    <button class="button secondary" type="button" data-update-recheck disabled>{{ __('operator_updates.actions.recheck') }}</button>
                </div>
                <label class="update-confirmation"><input type="checkbox" data-update-confirm disabled> {{ __('operator_updates.review.confirmation') }}</label>
                <div class="update-actions">
                    <button class="button" type="button" data-update-start disabled>{{ __('operator_updates.actions.start') }}</button>
                    <button class="button secondary" type="button" data-update-retry hidden disabled>{{ __('operator_updates.actions.retry') }}</button>
                </div>
            </div>
            <button class="button secondary" type="button" data-update-open hidden>{{ __('operator_updates.actions.open_progress') }}</button>
        </section>

        <section class="section" aria-labelledby="update-history-heading">
            <div class="section-header">
                <div>
                    <h2 id="update-history-heading">{{ __('operator_updates.history.title') }}</h2>
                    <p class="lede">{{ __('operator_updates.history.subtitle') }}</p>
                </div>
            </div>
            <p data-update-history-message role="status">{{ __('operator_updates.history.loading') }}</p>
            <div data-update-history class="management-list"></div>
            <button class="button secondary" type="button" data-update-history-more hidden>{{ __('operator_updates.actions.load_more') }}</button>
        </section>

        <dialog class="wf-command-dialog update-dialog" data-update-dialog aria-labelledby="update-progress-title" aria-describedby="update-background-notice">
            <div class="wf-command-header">
                <div>
                    <h2 id="update-progress-title">{{ __('operator_updates.actions.open_progress') }}</h2>
                    <p data-update-identity class="update-identity" lang=""></p>
                </div>
                <button class="button secondary" type="button" data-update-close autofocus>{{ __('operator_updates.actions.close') }}</button>
            </div>
            <div class="update-dialog-body">
                <ol class="update-stages" aria-label="{{ __('operator_updates.actions.open_progress') }}">
                    @foreach (['preparing' => 'prepare', 'downloading' => 'download', 'protecting' => 'protect', 'applying' => 'apply', 'restarting' => 'restart', 'verifying' => 'verify'] as $phase => $stage)
                        <li data-update-stage="{{ $phase }}"><span class="update-stage-number" aria-hidden="true">{{ $loop->iteration }}</span><span>{{ __('operator_updates.stages.'.$stage) }}</span></li>
                    @endforeach
                </ol>
                <p data-update-outcome role="status" aria-live="polite" aria-atomic="true"></p>
                <p data-update-error></p>
                <p data-update-connection role="status" aria-live="polite"></p>
                <dl class="update-clock">
                    <div><dt>{{ __('operator_updates.labels.elapsed') }}</dt><dd data-update-elapsed>—</dd></div>
                    <div><dt>{{ __('operator_updates.labels.last_confirmed') }}</dt><dd data-update-last-seen>—</dd></div>
                </dl>
                <p id="update-background-notice" class="lede">{{ __('operator_updates.connection.background_notice') }}</p>
                <p data-update-boundary class="lede"></p>
                <button class="button secondary" type="button" data-update-cancel disabled>{{ __('operator_updates.actions.cancel') }}</button>
                <button class="button secondary" type="button" data-update-auth-open hidden>{{ __('operator_updates.reauth.title') }}</button>
                <a class="button secondary" data-update-signin hidden href="{{ route('operator.updates.index') }}">{{ __('operator_updates.actions.sign_in') }}</a>
                <x-details-disclosure :summary="__('operator_updates.actions.show_details')">
                    <p class="lede">{{ __('operator_updates.notices.details_redacted') }}</p>
                    <dl class="update-facts" data-update-details></dl>
                    <h3>{{ __('operator_updates.history.events_title') }}</h3>
                    <ol data-update-events class="update-events"></ol>
                </x-details-disclosure>
            </div>
        </dialog>
    </div>
    <script type="application/json" id="operator-update-bootstrap">@json($updateBootstrap)</script>
    <script type="application/json" id="operator-update-copy">@json(trans('operator_updates'))</script>
    <x-operator-update-style />
    <x-operator-update-script />
</x-layouts.operator>
