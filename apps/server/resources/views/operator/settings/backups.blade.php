<x-layouts.operator :title="__('operator.backups.document_title')">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.backups.title')"
        :subtitle="__('operator.backups.subtitle')" />

    @foreach (['status', 'error'] as $feedbackType)
        @if ($feedback = session($feedbackType))
            <p class="status-message"><x-operator-feedback :feedback="$feedback" /></p>
        @endif
    @endforeach

    @include('operator.settings.partials.restore-status', ['status' => $restoreStatus])

    <section class="section" aria-labelledby="backup-run-heading">
        <div class="section-header">
            <div>
                <h2 id="backup-run-heading">{{ __('operator.backups.run_heading') }}</h2>
                <p class="lede">{!! __('operator.backups.run_lede', [
                    'history' => '<a class="text-link" href="'.e(route('operator.settings.backups.history')).'">'.e(__('operator.backups.history_link')).'</a>',
                    'restore_link' => '<a class="text-link" href="'.e(route('operator.settings.backups.restore')).'">'.e(__('operator.backups.restore_link')).'</a>',
                ]) !!}</p>
            </div>
            <form method="POST" action="{{ route('operator.settings.backups.run') }}">
                @csrf
                @if ($returnTo)<input type="hidden" name="from" value="{{ $returnTo }}">@endif
                <button class="button" type="submit">{{ __('operator.backups.run') }}</button>
            </form>
        </div>

        @if ($worker['state'] === \App\Support\Queue\QueueConsumerHeartbeat::NONE || $worker['stale'])
            {{-- Shown only when the absence is real. If the cache cannot carry a
                 signal between processes, or could not be read at all, a worker
                 could be running perfectly and still be invisible here — so the
                 state is checked rather than the timestamp, and saying "none"
                 stays a fact rather than a guess.

                 A stale sighting counts as absent: a worker that ran once and
                 stopped leaves its record readable, and treating ever-seen as
                 healthy would stay silent while "Run a backup now" queued jobs
                 nothing would pick up. --}}
            <div class="notice-copy notice-copy-bordered">
                @if ($worker['stale'])
                    <p><strong>{{ __('operator.backups.worker_stale', ['time' => $worker['at']?->diffForHumans()]) }}</strong> {{ __('operator.backups.worker_stopped') }}</p>
                @else
                    <p><strong>{{ __('operator.backups.worker_none') }}</strong></p>
                @endif
                <p>{!! __('operator.backups.worker_queued', [
                    'status' => '<em>'.e(__('operator.backups.running')).'</em>',
                ]) !!}</p>
                <p>{!! __('operator.backups.worker_compose', [
                    'compose' => '<span lang="">Compose</span>',
                    'forge' => '<span lang="">Forge</span>',
                ]) !!}</p>
                <pre><code lang="">php artisan queue:work backups --queue={{ $worker['queue'] }} --sleep=5 --tries=1 --timeout={{ $worker['timeout'] }}</code></pre>
            </div>
        @endif

        @if ($latestRun)
            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.backups.queue_worker') }}</span>
                    <span class="meta-value">
                        @switch($worker['state'])
                            @case(\App\Support\Queue\QueueConsumerHeartbeat::SEEN)
                                {{ $worker['stale'] ? __('operator.backups.last_seen') : __('operator.backups.seen') }} {{ $worker['at']?->diffForHumans() }}
                                @break
                            @case(\App\Support\Queue\QueueConsumerHeartbeat::NONE)
                                {{ __('operator.backups.none_seen') }}
                                @break
                            @default
                                {{ __('operator.backups.cannot_tell') }}
                        @endswitch
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.backups.latest_run') }}</span>
                    <span class="meta-value">
                        @switch($latestRun->status)
                            @case('succeeded') {{ __('operator.backups.succeeded') }} @break
                            @case('failed') {{ __('operator.backups.failed') }} @break
                            @default {{ __('operator.backups.running') }} @break
                        @endswitch
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.backups.started') }}</span>
                    <span class="meta-value">{{ $latestRun->started_at?->diffForHumans() }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.backups.size') }}</span>
                    <span class="meta-value" lang="">{{ $latestRun->size_bytes ? \App\Support\ReaderNumber::decimal($latestRun->size_bytes / 1048576, 1).' MB' : '—' }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">{{ __('operator.backups.offsite') }}</span>
                    <span class="meta-value">{!! $latestRun->offsite_key
                        ? __('operator.backups.uploaded_to', ['disk' => '<span lang="">'.e($latestRun->offsite_disk).'</span>'])
                        : e(__('operator.backups.local_only')) !!}</span>
                </div>
            </div>
            @if ($latestRun->message)
                <div class="notice-copy notice-copy-bordered">
                    <p lang="">{{ $latestRun->message }}</p>
                </div>
            @endif
        @else
            <p class="empty">{{ __('operator.backups.no_runs') }}</p>
        @endif
    </section>

    <section class="section" aria-labelledby="backup-config-heading">
        <div class="section-header">
            <h2 id="backup-config-heading">{{ __('operator.backups.config_heading') }}</h2>
            <span class="lede">{{ __('operator.backups.config_lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.backups.update') }}">
            @csrf
            @if ($returnTo)<input type="hidden" name="from" value="{{ $returnTo }}">@endif

            <div class="field">
                <label for="disk">{{ __('operator.backups.disk') }}</label>
                <select id="disk" name="disk">
                    @if ($externalDisk)
                        <option lang="" value="{{ $externalDisk }}" @selected(old('disk', $disk) === $externalDisk)>{{ $externalDisk }}</option>
                    @endif
                    <option value="" @selected(old('disk', $disk) === '')>{{ __('operator.backups.disk_local') }}</option>
                    <option value="backups" @selected(old('disk', $disk) === 'backups')>{{ __('operator.backups.disk_s3') }}</option>
                </select>
                @error('disk')<p class="field-error">{{ $message }}</p>@enderror
                @if ($externalDisk)
                    <p class="field-help">{!! __('operator.backups.external_disk_help', ['disk' => '<code lang="">'.e($externalDisk).'</code>']) !!}</p>
                @endif
                <p class="field-help">{{ __('operator.backups.disk_help') }}</p>
            </div>

            <div class="field">
                <label for="retention_days">{{ __('operator.backups.retention') }}</label>
                <input id="retention_days" name="retention_days" lang="" value="{{ old('retention_days', $retentionDays) }}" inputmode="numeric" placeholder="0">
                @error('retention_days')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.backups.retention_help', ['zero' => '<code lang="">0</code>']) !!}</p>
            </div>

            <div class="field">
                <label for="prefix">{{ __('operator.backups.prefix') }}</label>
                <input id="prefix" name="prefix" lang="" value="{{ old('prefix', $prefix) }}" autocomplete="off" placeholder="wayfindr-backups/…">
                @error('prefix')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.backups.prefix_help', ['app_key' => '<code lang="">APP_KEY</code>']) !!}</p>
            </div>

            <h3>{!! __('operator.backups.s3_heading', ['s3' => '<span lang="">S3</span>']) !!}</h3>
            <p class="field-help">{{ __('operator.backups.s3_help') }}</p>

            <div class="field">
                <label for="bucket">{{ __('operator.backups.bucket') }}</label>
                <input id="bucket" name="bucket" lang="" value="{{ old('bucket', $bucket) }}" autocomplete="off" placeholder="my-wayfindr-backups">
                @error('bucket')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="region">{{ __('operator.backups.region') }}</label>
                <input id="region" name="region" lang="" value="{{ old('region', $region) }}" autocomplete="off" placeholder="us-east-1">
                @error('region')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="endpoint">{{ __('operator.backups.endpoint') }}</label>
                <input id="endpoint" name="endpoint" lang="" value="{{ old('endpoint', $endpoint) }}" autocomplete="off" placeholder="https://…">
                @error('endpoint')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.backups.endpoint_help', [
                    'aws' => '<span lang="">AWS S3</span>',
                    'r2' => '<span lang="">Cloudflare R2</span>',
                    'spaces' => '<span lang="">DigitalOcean Spaces</span>',
                    'minio' => '<span lang="">MinIO</span>',
                ]) !!}</p>
            </div>

            <div class="field">
                <label for="s3_access_key">{{ __('operator.backups.access_key') }}</label>
                <input id="s3_access_key" name="s3_access_key" type="password" autocomplete="off"
                    placeholder="{{ $keyUnreadable ? __('operator.backups.key_placeholder_unreadable') : ($keyIsSet ? __('operator.backups.key_placeholder_configured') : __('operator.backups.key_placeholder_none')) }}">
                @error('s3_access_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($keyUnreadable)<p class="field-error">{{ __('operator.backups.key_unreadable') }}</p>@endif
            </div>

            <div class="field">
                <label for="s3_secret_key">{{ __('operator.backups.secret_key') }}</label>
                <input id="s3_secret_key" name="s3_secret_key" type="password" autocomplete="new-password"
                    placeholder="{{ $secretUnreadable ? __('operator.backups.secret_placeholder_unreadable') : ($secretIsSet ? __('operator.backups.secret_placeholder_configured') : __('operator.backups.secret_placeholder_none')) }}">
                @error('s3_secret_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($secretUnreadable)<p class="field-error">{{ __('operator.backups.secret_unreadable') }}</p>@endif
                <p class="field-help">{{ __('operator.backups.credentials_help') }}</p>
                <label class="check-row" for="s3_no_keys">
                    <input id="s3_no_keys" type="checkbox" name="s3_no_keys" value="1" @checked(old('s3_no_keys'))>
                    <span>{{ __('operator.backups.no_keys') }}</span>
                </label>
            </div>

            <div class="field">
                <label for="acl">{{ __('operator.backups.acl') }}</label>
                <select id="acl" name="acl">
                    <option value="bucket-owner-full-control" @selected(old('acl', $acl ?: 'bucket-owner-full-control') === 'bucket-owner-full-control')>{{ __('operator.backups.acl_owner_full') }}</option>
                    <option value="private" @selected(old('acl', $acl) === 'private')>{{ __('operator.backups.acl_private') }}</option>
                    <option value="bucket-owner-read" @selected(old('acl', $acl) === 'bucket-owner-read')>{{ __('operator.backups.acl_owner_read') }}</option>
                </select>
                @error('acl')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.backups.acl_help', [
                    'aws' => '<span lang="">AWS S3</span>',
                    'r2' => '<span lang="">Cloudflare R2</span>',
                ]) !!}</p>
            </div>

            <div class="field">
                <label for="root">{{ __('operator.backups.root') }}</label>
                <input id="root" name="root" lang="" value="{{ old('root', $root) }}" autocomplete="off">
                @error('root')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{{ __('operator.backups.root_help') }}</p>
            </div>

            <div class="field">
                <input type="hidden" name="use_path_style" value="0">
                <label class="check-row" for="use_path_style">
                    <input id="use_path_style" type="checkbox" name="use_path_style" value="1" @checked(old('use_path_style', $usePathStyle))>
                    <span>{!! __('operator.backups.path_style', [
                        'minio' => '<span lang="">MinIO</span>',
                        'aws' => '<span lang="">AWS S3</span>',
                    ]) !!}</span>
                </label>
            </div>

            <button class="button" type="submit">{{ __('operator.backups.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="backup-test-heading">
        <div class="section-header">
            <h2 id="backup-test-heading">{{ __('operator.backups.test_heading') }}</h2>
            <span class="lede">{{ __('operator.backups.test_lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.backups.test') }}">
            @csrf
            @if ($returnTo)<input type="hidden" name="from" value="{{ $returnTo }}">@endif
            <button class="button secondary" type="submit">{{ __('operator.backups.test') }}</button>
        </form>
    </section>
</x-layouts.operator>
