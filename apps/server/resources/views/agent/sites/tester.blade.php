<x-layouts.app :title="__('sites.tester.document_title')" :agent="$agent" :account="$account">
            <x-page-header :subtitle="__('sites.tester.subtitle')" :back-href="route('dashboard.sites.show', $site)" :back-label="__('sites.tester.back')">
                {{-- The heading mixes product copy with the account-authored site
                     name, so only the authored fragment has unknown language. --}}
                <x-slot:title-content>
                    {!! __('sites.tester.title', ['site' => '<span lang="">'.e($site->name).'</span>']) !!}
                </x-slot:title-content>
            </x-page-header>

            <section class="section" aria-labelledby="tester-context-heading">
                <div class="section-header">
                    <h2 id="tester-context-heading">{{ __('sites.tester.context.heading') }}</h2>
                    <span class="lede">{{ __('sites.tester.context.lede') }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.context.site') }}</span>
                        <span class="meta-value" lang="">{{ $site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.context.domain') }}</span>
                        @if ($site->domain)
                            <span class="meta-value" lang="">{{ $site->domain }}</span>
                        @else
                            <span class="meta-value">{{ __('sites.tester.context.not_set') }}</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.context.public_key') }}</span>
                        <span class="meta-value" lang="">{{ $site->public_key }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.context.visitor') }}</span>
                        <span class="meta-value" lang="">{{ $testerAnonymousId }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.context.inbox') }}</span>
                        <a class="text-link" href="{{ route('dashboard.conversations.index') }}">{{ __('sites.tester.context.open_conversations') }}</a>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="tester-run-heading">
                <div class="section-header">
                    <h2 id="tester-run-heading">{{ __('sites.tester.run.heading') }}</h2>
                    <span class="lede">{{ __('sites.tester.run.lede') }}</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.run.widget') }}</span>
                        <span class="meta-value">{{ __('sites.tester.run.launcher') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.run.agent_view') }}</span>
                        <a class="text-link" href="{{ route('dashboard.conversations.index') }}">{{ __('sites.tester.run.conversations') }}</a>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.run.cobrowse') }}</span>
                        <span class="meta-value">{{ __('sites.tester.run.masked_fields') }}</span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="tester-page-heading">
                <div class="section-header">
                    <h2 id="tester-page-heading">{{ __('sites.tester.sample.heading') }}</h2>
                    <span class="lede">{{ __('sites.tester.sample.lede') }}</span>
                </div>

                <div class="notice-copy">
                    <p>{{ __('sites.tester.sample.detail') }}</p>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.sample.current_task') }}</span>
                        <span class="meta-value">{{ __('sites.tester.sample.install_verification') }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.sample.example_route') }}</span>
                        <span class="meta-value" lang="">{{ route('dashboard.sites.tester', $site, false) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('sites.tester.sample.safe_context') }}</span>
                        {{-- Deliberately stable fake host-page data, like the
                             editable visitor values below rather than dashboard copy. --}}
                        <span class="meta-value" lang="">Plan: Team, region: Demo</span>
                    </div>
                </div>

                <form class="section-form" aria-label="{{ __('sites.tester.sample.form_aria') }}">
                    <div class="field">
                        <label for="tester-email">{{ __('sites.tester.sample.email') }}</label>
                        <input id="tester-email" name="email" type="email" value="visitor@example.test" autocomplete="email" lang="">
                    </div>
                    <div class="field">
                        <label for="tester-password">{{ __('sites.tester.sample.password') }}</label>
                        <input id="tester-password" name="password" type="password" value="not-a-real-password" autocomplete="current-password" lang="">
                    </div>
                    <div class="field">
                        <label for="tester-card">{{ __('sites.tester.sample.card_number') }}</label>
                        <input id="tester-card" name="card_number" type="text" value="4111 1111 1111 1111" data-wayfindr-mask autocomplete="off" lang="">
                    </div>
                    <div class="field">
                        <label for="tester-note">{{ __('sites.tester.sample.support_note') }}</label>
                        {{-- Editable visitor-authored content. The initial fixture
                             is English, but the value's language becomes unknown
                             as soon as the tester types, so the field cannot claim
                             the surrounding dashboard locale. --}}
                        <textarea id="tester-note" name="support_note" lang="">I am testing chat, replies, and cobrowse masking from the built-in Wayfindr tester.</textarea>
                    </div>
                </form>
            </section>

            {{-- widget.js carries the realtime library itself (issue #714). --}}
            <script src="{{ $widgetBaseUrl }}/widget.js"></script>
            <script>
                (function () {
                    var reverb = @json($widgetReverbConfig);
                    var options = {
                        apiBaseUrl: @json($widgetBaseUrl),
                        sitePublicKey: @json($site->public_key),
                        anonymousId: @json($testerAnonymousId),
                        // No custom launcher/title: this page exists to verify
                        // the widget's real browser/site-default language path,
                        // and an English host override used to bypass both.
                        visitorContext: {
                            wayfindr_source: 'tester',
                            site_name: @json($site->name),
                            // Stored host context, not dashboard chrome. Keep it
                            // stable across whichever agent runs this tester.
                            test_surface: 'Dashboard tester',
                        },
                    };

                    if (reverb) {
                        options.reverb = {
                            appKey: reverb.app_key,
                            host: reverb.host,
                            port: Number(reverb.port),
                            scheme: reverb.scheme,
                        };
                    }

                    window.Wayfindr.init(options);
                })();
            </script>
</x-layouts.app>
