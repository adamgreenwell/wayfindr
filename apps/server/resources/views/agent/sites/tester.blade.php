<x-layouts.app title="Site Tester" :agent="$agent" :account="$account">
            <a class="text-link" href="{{ route('dashboard.sites.show', $site) }}">Back to site settings</a>
            <h1>{{ $site->name }} tester</h1>
            <p class="lede">Hosted sample page for this site's widget, chat loop, and cobrowse masking.</p>

            <section class="section" aria-labelledby="tester-context-heading">
                <div class="section-header">
                    <h2 id="tester-context-heading">Test surface</h2>
                    <span class="lede">Site-scoped widget install</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Site</span>
                        <span class="meta-value">{{ $site->name }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Domain</span>
                        <span class="meta-value">{{ $site->domain ?? 'Not set' }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Public key</span>
                        <span class="meta-value">{{ $site->public_key }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Tester visitor</span>
                        <span class="meta-value">{{ $testerAnonymousId }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Inbox</span>
                        <a class="text-link" href="{{ route('dashboard') }}#conversations">Open conversations</a>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="tester-run-heading">
                <div class="section-header">
                    <h2 id="tester-run-heading">Verification run</h2>
                    <span class="lede">Widget to agent loop</span>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Widget</span>
                        <span class="meta-value">Lower-corner launcher</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Agent view</span>
                        <a class="text-link" href="{{ route('dashboard') }}#conversations">Conversations</a>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Cobrowse sample</span>
                        <span class="meta-value">Masked fake fields</span>
                    </div>
                </div>
            </section>

            <section class="section" aria-labelledby="tester-page-heading">
                <div class="section-header">
                    <h2 id="tester-page-heading">Sample page</h2>
                    <span class="lede">Safe fake data only</span>
                </div>

                <div class="notice-copy">
                    <p>Common support context with fake sensitive fields for the privacy path.</p>
                </div>

                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Current task</span>
                        <span class="meta-value">Install verification</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Example route</span>
                        <span class="meta-value">{{ route('dashboard.sites.tester', $site, false) }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Safe context</span>
                        <span class="meta-value">Plan: Team, region: Demo</span>
                    </div>
                </div>

                <form class="section-form" aria-label="Fake checkout form">
                    <div class="field">
                        <label for="tester-email">Email address</label>
                        <input id="tester-email" name="email" type="email" value="visitor@example.test" autocomplete="email">
                    </div>
                    <div class="field">
                        <label for="tester-password">Password</label>
                        <input id="tester-password" name="password" type="password" value="not-a-real-password" autocomplete="current-password">
                    </div>
                    <div class="field">
                        <label for="tester-card">Card number</label>
                        <input id="tester-card" name="card_number" type="text" value="4111 1111 1111 1111" data-wayfindr-mask autocomplete="off">
                    </div>
                    <div class="field">
                        <label for="tester-note">Support note</label>
                        <textarea id="tester-note" name="support_note">I am testing chat, replies, and cobrowse masking from the built-in Wayfindr tester.</textarea>
                    </div>
                </form>
            </section>

            @if ($widgetReverbConfig)
                <script src="https://js.pusher.com/8.3.0/pusher.min.js"></script>
            @endif
            <script src="{{ $widgetBaseUrl }}/widget.js"></script>
            <script>
                (function () {
                    var reverb = @json($widgetReverbConfig);
                    var options = {
                        apiBaseUrl: @json($widgetBaseUrl),
                        sitePublicKey: @json($site->public_key),
                        anonymousId: @json($testerAnonymousId),
                        launcherLabel: 'Open tester widget',
                        title: 'Wayfindr Tester',
                        visitorContext: {
                            wayfindr_source: 'tester',
                            site_name: @json($site->name),
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
