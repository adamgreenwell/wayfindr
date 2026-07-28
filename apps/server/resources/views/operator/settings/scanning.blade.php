<x-layouts.app title="Scanning settings">
    <p><a class="text-link" href="{{ $backUrl }}">{{ $backLabel }}</a></p>

    <x-page-header
        title="Attachment scanning"
        subtitle="Scan uploaded files for malware before they are stored. Changes apply immediately, no restart." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status-message">{{ session('error') }}</p>
    @endif

    <section class="section" aria-labelledby="scanning-config-heading">
        <div class="section-header">
            <h2 id="scanning-config-heading">Malware scanner</h2>
            <span class="lede">Without a scanner, uploads are still accepted with defense-in-depth: a byte-sniffed type allowlist, private storage, forced-download disposition, and nosniff — but not virus-scanned.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.scanning.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="driver">Scanner</label>
                <select id="driver" name="driver">
                    @if ($externalDriver)
                        <option value="{{ $externalDriver }}" @selected(old('driver', $driver) === $externalDriver)>{{ $externalDriver }} (current, configured in env)</option>
                    @endif
                    <option value="" @selected(old('driver', $driver) === '')>None (accept with defense-in-depth)</option>
                    <option value="clamav" @selected(old('driver', $driver) === 'clamav')>ClamAV</option>
                </select>
                @error('driver')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">ClamAV runs locally, so files never leave the server to be scanned. Choose it and set the clamd socket below.</p>
            </div>

            <div class="field">
                <label for="socket">ClamAV socket</label>
                <input id="socket" name="socket" value="{{ old('socket', $socket) }}" autocomplete="off" placeholder="tcp://127.0.0.1:3310">
                @error('socket')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">A TCP address (<code>tcp://host:port</code>) or a Unix socket (<code>unix:///var/run/clamav/clamd.ctl</code>) for the running clamd.</p>
            </div>

            <div class="field">
                {{-- Always submit a value so unchecking survives a validation error. --}}
                <input type="hidden" name="fail_closed" value="0">
                <label class="check-row" for="fail_closed">
                    <input id="fail_closed" type="checkbox" name="fail_closed" value="1" @checked(old('fail_closed', $failClosed))>
                    <span>Reject uploads when the scanner is unreachable (fail-closed — recommended). Unchecked, uploads are accepted unscanned if the scanner is down.</span>
                </label>
            </div>

            <button class="button" type="submit">Save scanning settings</button>
        </form>
    </section>

    <section class="section" aria-labelledby="scanning-test-heading">
        <div class="section-header">
            <h2 id="scanning-test-heading">Test reachability</h2>
            <span class="lede">Confirm the configured scanner is running and responds — no terminal needed.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.scanning.test') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <button class="button secondary" type="submit">Test scanner</button>
        </form>
    </section>
</x-layouts.app>
