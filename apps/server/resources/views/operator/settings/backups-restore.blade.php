<x-layouts.operator :title="__('operator.backups.restore.document_title')">
    <p><a class="text-link" href="{{ route('operator.settings.backups.edit') }}">{{ __('operator.backups.restore.back') }}</a></p>

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.backups.restore.title')"
        :subtitle="__('operator.backups.restore.subtitle')" />

    @if (session('error'))
        <p class="status-message"><x-operator-feedback :feedback="session('error')" /></p>
    @endif

    @include('operator.settings.partials.restore-status', ['status' => $status])

    @unless ($durable)
        <section class="section" aria-labelledby="restore-cli-heading">
            <div class="section-header">
                <h2 id="restore-cli-heading">{{ __('operator.backups.restore.unavailable_heading') }}</h2>
            </div>
            <div class="notice-copy notice-copy-bordered">
                <p>{{ __('operator.backups.restore.unavailable_lede') }}</p>
                <ul>
                    @foreach ($durabilityIssues as $issue)
                        <li><x-operator-feedback :feedback="$issue" /></li>
                    @endforeach
                </ul>
                <p>{{ __('operator.backups.restore.unavailable_fix') }}</p>
            </div>
            <pre class="code-block"><code lang="">php artisan wayfindr:restore &lt;path-to-archive&gt; --force</code></pre>
        </section>
    @else
        <section class="section" aria-labelledby="restore-choose-heading">
            <div class="section-header">
                <h2 id="restore-choose-heading">{{ __('operator.backups.restore.choose_heading') }}</h2>
                <span class="lede">{{ __('operator.backups.restore.choose_lede') }}</span>
            </div>

            @if ($preflightError)
                <p class="field-error"><x-operator-feedback :feedback="$preflightError" /></p>
            @endif

            @if (empty($archives))
                <p class="empty">{!! __('operator.backups.restore.no_archives', [
                    'command' => '<code lang="">php artisan wayfindr:restore</code>',
                ]) !!}</p>
            @else
                <form method="GET" action="{{ route('operator.settings.backups.restore') }}" class="section-form">
                    <div class="field">
                        <label for="archive">{{ __('operator.backups.restore.archive') }}</label>
                        <select id="archive" name="archive">
                            <option value="">{{ __('operator.backups.restore.select_archive') }}</option>
                            @foreach ($archives as $archive)
                                <option value="{{ $archive['filename'] }}" @selected($selected === $archive['filename'])>
                                    {{ \App\Support\ReaderClock::dateTime($archive['taken_at']) }} · {{ \App\Support\ReaderNumber::decimal($archive['size'] / 1048576, 1) }} MB · {{ $archive['filename'] }}
                                </option>
                            @endforeach
                        </select>
                        @error('archive')<p class="field-error">{{ $message }}</p>@enderror
                        @if ($detail = session('restore_error_detail'))<p class="field-error" lang="">{{ $detail }}</p>@endif
                    </div>
                    <button class="button secondary" type="submit">{{ __('operator.backups.restore.preview') }}</button>
                </form>
            @endif
        </section>

        @if ($selected && $preflight)
            <section class="section" aria-labelledby="restore-confirm-heading">
                <div class="section-header">
                    <h2 id="restore-confirm-heading">{{ __('operator.backups.restore.confirm_heading') }}</h2>
                </div>

                <div class="meta-grid readiness-summary-grid">
                    <div class="meta-item">
                        <span class="meta-label">{{ __('operator.backups.restore.archive_label') }}</span>
                        <span class="meta-value" lang="">{{ $selected }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('operator.backups.restore.backup_version') }}</span>
                        <span class="meta-value" lang="">{{ $preflight['archive_version'] }}</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">{{ __('operator.backups.restore.this_install') }}</span>
                        <span class="meta-value" lang="">{{ $preflight['running_version'] }}</span>
                    </div>
                </div>

                @if ($preflight['version_indeterminate'] ?? false)
                    <div class="notice-copy notice-copy-bordered">
                        <p>{!! __('operator.backups.restore.versions_unverified', [
                            'archive' => '<strong lang="">'.e($preflight['archive_version']).'</strong>',
                            'running' => '<strong lang="">'.e($preflight['running_version']).'</strong>',
                        ]) !!}</p>
                        @if (! ($preflight['archive_version_known'] ?? false) && ($preflight['running_version_known'] ?? false))
                            <p>{!! __('operator.backups.restore.archive_unknown', [
                                'up' => '<code lang="">php artisan up</code>',
                            ]) !!}</p>
                        @elseif (($preflight['archive_version_known'] ?? false) && ! ($preflight['running_version_known'] ?? false))
                            <p>{!! __('operator.backups.restore.install_unknown', [
                                'version' => '<strong lang="">'.e($preflight['archive_version']).'</strong>',
                                'up' => '<code lang="">php artisan up</code>',
                                'setting' => '<code lang="">WAYFINDR_VERSION</code>',
                            ]) !!}</p>
                        @else
                            <p>{!! __('operator.backups.restore.both_unknown', [
                                'up' => '<code lang="">php artisan up</code>',
                                'setting' => '<code lang="">WAYFINDR_VERSION</code>',
                            ]) !!}</p>
                        @endif
                    </div>
                @elseif ($preflight['version_skew'])
                    <div class="notice-copy notice-copy-bordered">
                        <p>{!! __('operator.backups.restore.version_skew', [
                            'archive' => '<strong lang="">'.e($preflight['archive_version']).'</strong>',
                            'running' => '<strong lang="">'.e($preflight['running_version']).'</strong>',
                            'migrate' => '<code lang="">php artisan migrate --force</code>',
                            'up' => '<code lang="">php artisan up</code>',
                        ]) !!}</p>
                    </div>
                @endif

                <div class="notice-copy notice-copy-bordered">
                    <p><strong>{{ __('operator.backups.restore.danger_heading') }}</strong> {{ __('operator.backups.restore.danger_body') }}</p>
                    <p>{{ __('operator.backups.restore.workers_warning') }}</p>
                </div>
                <pre class="code-block"><code><span lang="">docker compose stop queue scheduler</span>   # {{ __('operator.backups.restore.workers_stop_comment') }}
<span lang="">docker compose start queue scheduler</span>  # {{ __('operator.backups.restore.workers_start_comment') }}</code></pre>

                <form class="section-form" method="POST" action="{{ route('operator.settings.backups.restore.run') }}">
                    @csrf
                    <input type="hidden" name="archive" value="{{ $selected }}">

                    <div class="field">
                        <label for="confirm_name">{{ __('operator.backups.restore.confirm_name') }}</label>
                        <input id="confirm_name" name="confirm_name" lang="" autocomplete="off" placeholder="{{ $instanceName }}">
                        @error('confirm_name')<p class="field-error">{{ $message }}</p>@enderror
                        <p class="field-help">{!! __('operator.backups.restore.instance_name', [
                            'name' => '<strong lang="">'.e($instanceName).'</strong>',
                        ]) !!}</p>
                    </div>

                    <div class="field">
                        <label class="check-row" for="acknowledge">
                            <input id="acknowledge" type="checkbox" name="acknowledge" value="1">
                            <span>{{ __('operator.backups.restore.acknowledge') }}</span>
                        </label>
                        @error('acknowledge')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="field">
                        <label class="check-row" for="workers_stopped">
                            <input id="workers_stopped" type="checkbox" name="workers_stopped" value="1">
                            <span>{!! __('operator.backups.restore.workers_stopped', [
                                'command' => '<code lang="">docker compose stop queue scheduler</code>',
                            ]) !!}</span>
                        </label>
                        @error('workers_stopped')<p class="field-error">{{ $message }}</p>@enderror
                    </div>

                    <button class="button danger" type="submit">{{ __('operator.backups.restore.restore_now') }}</button>
                </form>
            </section>
        @endif
    @endunless
</x-layouts.operator>
