<x-layouts.operator title="Restore from backup">
    <p><a class="text-link" href="{{ route('operator.settings.backups.edit') }}">Back to backup settings</a></p>

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? 'Back'"
        title="Restore from backup"
        subtitle="Replace this install's data with a backup archive. This cannot be undone." />

    @if (session('error'))
        <p class="status-message"><x-operator-feedback :feedback="session('error')" /></p>
    @endif

    @include('operator.settings.partials.restore-status', ['status' => $status])

    @unless ($durable)
        <section class="section" aria-labelledby="restore-cli-heading">
            <div class="section-header">
                <h2 id="restore-cli-heading">In-GUI restore is unavailable here</h2>
            </div>
            <div class="notice-copy notice-copy-bordered">
                <p>A restore rebuilds the database, so it can only run safely in the browser when the queue, cache, and maintenance-mode marker all survive the rebuild and are shared with the worker. This install does not meet:</p>
                <ul>
                    @foreach ($durabilityIssues as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
                <p>Fix the above to enable the in-GUI restore, or restore from the server instead (the archives live under the backup path, in this install's prefix):</p>
            </div>
            <pre class="code-block"><code>php artisan wayfindr:restore &lt;path-to-archive&gt; --force</code></pre>
        </section>
    @else
        <section class="section" aria-labelledby="restore-choose-heading">
            <div class="section-header">
                <h2 id="restore-choose-heading">Choose an archive</h2>
                <span class="lede">Local archives only, newest first. Restore an offsite-only archive from the server with the CLI.</span>
            </div>

            @if ($preflightError)
                <p class="field-error">{{ $preflightError }}</p>
            @endif

            @if (empty($archives))
                <p class="empty">No local backup archives found. Run a backup first, or restore an offsite archive from the server with <code>php artisan wayfindr:restore</code>.</p>
            @else
                <form method="GET" action="{{ route('operator.settings.backups.restore') }}" class="section-form">
                    <div class="field">
                        <label for="archive">Backup archive</label>
                        <select id="archive" name="archive">
                            <option value="">Select an archive…</option>
                            @foreach ($archives as $archive)
                                <option value="{{ $archive['filename'] }}" @selected($selected === $archive['filename'])>
                                    {{ \App\Support\ReaderClock::dateTime($archive['taken_at']) }} · {{ \App\Support\ReaderNumber::decimal($archive['size'] / 1048576, 1) }} MB · {{ $archive['filename'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('archive')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <button class="button secondary" type="submit">Preview this archive</button>
                </form>
            @endif
        </section>

        @if ($selected && $preflight)
            <section class="section" aria-labelledby="restore-confirm-heading">
                <div class="section-header">
                    <h2 id="restore-confirm-heading">Confirm the restore</h2>
                </div>

                <div class="meta-grid readiness-summary-grid">
                    <div class="meta-item">
                        <span class="meta-label">Archive</span>
                        <span class="meta-value">{{ $selected }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Backup version</span>
                        <span class="meta-value">{{ $preflight['archive_version'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">This install</span>
                        <span class="meta-value">{{ $preflight['running_version'] }}</span>
                    </div>
                </div>

                @if ($preflight['version_indeterminate'] ?? false)
                    <div class="notice-copy notice-copy-bordered">
                        <p>The versions <strong>could not be verified</strong> (archive: <strong>{{ $preflight['archive_version'] }}</strong>, this install: <strong>{{ $preflight['running_version'] }}</strong>). The site will be <strong>kept in maintenance mode</strong> after the restore.</p>
                        @if (! ($preflight['archive_version_known'] ?? false) && ($preflight['running_version_known'] ?? false))
                            <p>The <strong>archive</strong> carries no release identity, so it cannot be checked against this install. If it came from a <strong>newer</strong> release, this install has no migrations that would bring the schema forward — migrating would do nothing and leave an incompatible schema live. Identify which release the archive came from (or deploy a release matching it) before running <code>php artisan up</code>.</p>
                        @elseif (($preflight['archive_version_known'] ?? false) && ! ($preflight['running_version_known'] ?? false))
                            <p><strong>This install</strong> carries no release identity. On the server, confirm it runs code compatible with <strong>{{ $preflight['archive_version'] }}</strong> and that the schema is current, then <code>php artisan up</code>. Set <code>WAYFINDR_VERSION</code> so future restores can verify this automatically.</p>
                        @else
                            <p>Neither side carries a release identity, so a schema mismatch cannot be ruled out. On the server, confirm the schema is current, then <code>php artisan up</code>. Set <code>WAYFINDR_VERSION</code> so future restores can verify this automatically.</p>
                        @endif
                    </div>
                @elseif ($preflight['version_skew'])
                    <div class="notice-copy notice-copy-bordered">
                        <p>This backup was taken on a different version (<strong>{{ $preflight['archive_version'] }}</strong>) than this install runs (<strong>{{ $preflight['running_version'] }}</strong>). Because the schema and code may not match, the site will be <strong>kept in maintenance mode</strong> after the restore. On the server, make them compatible — if this backup is <strong>older</strong>, run <code>php artisan migrate --force</code>; if it is <strong>newer</strong>, deploy a matching or newer release — then <code>php artisan up</code>.</p>
                    </div>
                @endif

                <div class="notice-copy notice-copy-bordered">
                    <p><strong>Restoring replaces ALL current data</strong> — the database and local attachment files — and cannot be undone. The whole site goes into <strong>maintenance mode</strong> while it runs (visitors and agents see a 503), so nothing new writes into the database mid-restore, and you are <strong>logged out</strong>. Wait a minute, then log back in (with the credentials stored in this backup) and check the restore status on the backup settings page.</p>
                    <p>Maintenance mode stops <em>new</em> work, but it cannot drain a background job that is already running. Before you restore, stop the background workers so nothing writes into the database as it is rebuilt, and start them again once the restore reports complete:</p>
                </div>
                <pre class="code-block"><code>docker compose stop queue scheduler   # before — leave backup-queue running
docker compose start queue scheduler  # after the restore completes</code></pre>

                <form class="section-form" method="POST" action="{{ route('operator.settings.backups.restore.run') }}">
                    @csrf
                    <input type="hidden" name="archive" value="{{ $selected }}">

                    <div class="field">
                        <label for="confirm_name">Type the instance name to confirm</label>
                        <input id="confirm_name" name="confirm_name" autocomplete="off" placeholder="{{ $instanceName }}">
                        @error('confirm_name')<p class="field-error">{{ $message }}</p>@enderror
                        <p class="field-help">This install is named <strong>{{ $instanceName }}</strong>.</p>
                    </div>

                    <div class="field">
                        <label class="check-row" for="acknowledge">
                            <input id="acknowledge" type="checkbox" name="acknowledge" value="1">
                            <span>I understand this ERASES all current data and cannot be undone.</span>
                        </label>
                        @error('acknowledge')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label class="check-row" for="workers_stopped">
                            <input id="workers_stopped" type="checkbox" name="workers_stopped" value="1">
                            <span>I have quiesced writes: stopped the background queue and scheduler workers (<code>docker compose stop queue scheduler</code>, leaving backup-queue), and there are no long-running uploads or requests in progress (maintenance mode blocks new ones, but cannot cut off a request already mid-write).</span>
                        </label>
                        @error('workers_stopped')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <button class="button danger" type="submit">Restore now</button>
                </form>
            </section>
        @endif
    @endunless
</x-layouts.operator>
