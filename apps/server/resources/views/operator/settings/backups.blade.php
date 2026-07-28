<x-layouts.app title="Backup settings">
    <p><a class="text-link" href="{{ $backUrl }}">{{ $backLabel }}</a></p>

    <x-page-header
        title="Backups"
        subtitle="Configure where backups are mirrored, how long they are kept, and run one on demand. Changes apply immediately, no restart." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status-message">{{ session('error') }}</p>
    @endif

    @include('operator.settings.partials.restore-status', ['status' => $restoreStatus])

    <section class="section" aria-labelledby="backup-run-heading">
        <div class="section-header">
            <div>
                <h2 id="backup-run-heading">Run a backup now</h2>
                <p class="lede">Queues a backup (database dump + local attachment binaries, plus the offsite copy if configured). It runs in the background. <a class="text-link" href="{{ route('operator.settings.backups.history') }}">View backup history</a> · <a class="text-link" href="{{ route('operator.settings.backups.restore') }}">Restore from backup</a>.</p>
            </div>
            <form method="POST" action="{{ route('operator.settings.backups.run') }}">
                @csrf
                @if ($returnTo)<input type="hidden" name="from" value="{{ $returnTo }}">@endif
                <button class="button" type="submit">Run a backup now</button>
            </form>
        </div>

        @if ($latestRun)
            <div class="meta-grid readiness-summary-grid">
                <div class="meta-item">
                    <span class="meta-label">Latest run</span>
                    <span class="meta-value">
                        @switch($latestRun->status)
                            @case('succeeded') Succeeded @break
                            @case('failed') Failed @break
                            @default Running @break
                        @endswitch
                    </span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Started</span>
                    <span class="meta-value">{{ $latestRun->started_at?->diffForHumans() }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Size</span>
                    <span class="meta-value">{{ $latestRun->size_bytes ? number_format($latestRun->size_bytes / 1048576, 1).' MB' : '—' }}</span>
                </div>
                <div class="meta-item">
                    <span class="meta-label">Offsite</span>
                    <span class="meta-value">{{ $latestRun->offsite_key ? 'Uploaded to ['.$latestRun->offsite_disk.']' : 'Local only' }}</span>
                </div>
            </div>
            @if ($latestRun->message)
                <div class="notice-copy notice-copy-bordered">
                    <p>{{ $latestRun->message }}</p>
                </div>
            @endif
        @else
            <p class="empty">No backup runs recorded yet. The scheduled backup and this button both record here.</p>
        @endif
    </section>

    <section class="section" aria-labelledby="backup-config-heading">
        <div class="section-header">
            <h2 id="backup-config-heading">Backup destination &amp; retention</h2>
            <span class="lede">Backups are always written to the local path. Optionally mirror them to an S3-compatible bucket for offsite durability.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.backups.update') }}">
            @csrf
            @if ($returnTo)<input type="hidden" name="from" value="{{ $returnTo }}">@endif

            <div class="field">
                <label for="disk">Offsite mirror</label>
                <select id="disk" name="disk">
                    @if ($externalDisk)
                        <option value="{{ $externalDisk }}" @selected(old('disk', $disk) === $externalDisk)>{{ $externalDisk }} (current, configured in env)</option>
                    @endif
                    <option value="" @selected(old('disk', $disk) === '')>Local only (no offsite copy)</option>
                    <option value="backups" @selected(old('disk', $disk) === 'backups')>S3-compatible bucket (offsite)</option>
                </select>
                @error('disk')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">A separate bucket from attachments. The local archive is always kept even when an offsite copy is uploaded.</p>
            </div>

            <div class="field">
                <label for="retention_days">Retention (days)</label>
                <input id="retention_days" name="retention_days" value="{{ old('retention_days', $retentionDays) }}" inputmode="numeric" placeholder="0">
                @error('retention_days')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">After a successful backup, prune archives older than this on both local and offsite. <code>0</code> keeps everything (you prune).</p>
            </div>

            <div class="field">
                <label for="prefix">Offsite prefix</label>
                <input id="prefix" name="prefix" value="{{ old('prefix', $prefix) }}" autocomplete="off" placeholder="wayfindr-backups/…">
                @error('prefix')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Per-install namespace inside the bucket, so two installs can share one bucket without pruning each other. Blank = derived from APP_KEY.</p>
            </div>

            <h3>S3-compatible bucket (offsite)</h3>
            <p class="field-help">Used when the offsite mirror is enabled. Keep the bucket private.</p>

            <div class="field">
                <label for="bucket">Bucket</label>
                <input id="bucket" name="bucket" value="{{ old('bucket', $bucket) }}" autocomplete="off" placeholder="my-wayfindr-backups">
                @error('bucket')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="region">Region</label>
                <input id="region" name="region" value="{{ old('region', $region) }}" autocomplete="off" placeholder="us-east-1">
                @error('region')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="endpoint">Endpoint</label>
                <input id="endpoint" name="endpoint" value="{{ old('endpoint', $endpoint) }}" autocomplete="off" placeholder="https://…">
                @error('endpoint')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Leave blank for AWS S3. Required for R2, Spaces, MinIO, and other S3-compatible stores.</p>
            </div>

            <div class="field">
                <label for="s3_access_key">Access key ID</label>
                <input id="s3_access_key" name="s3_access_key" type="password" autocomplete="off"
                    placeholder="{{ $keyUnreadable ? 'Could not read the saved key — re-enter it' : ($keyIsSet ? '•••••••• (a key is configured)' : 'No key configured') }}">
                @error('s3_access_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($keyUnreadable)<p class="field-error">The saved access key could not be decrypted (e.g. after an APP_KEY change). Re-enter it.</p>@endif
            </div>

            <div class="field">
                <label for="s3_secret_key">Secret access key</label>
                <input id="s3_secret_key" name="s3_secret_key" type="password" autocomplete="new-password"
                    placeholder="{{ $secretUnreadable ? 'Could not read the saved secret — re-enter it' : ($secretIsSet ? '•••••••• (a secret is configured)' : 'No secret configured') }}">
                @error('s3_secret_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($secretUnreadable)<p class="field-error">The saved secret could not be decrypted (e.g. after an APP_KEY change). Re-enter it.</p>@endif
                <p class="field-help">Access keys are stored encrypted and never shown. Leave blank to keep the saved values.</p>
                <label class="check-row" for="s3_no_keys">
                    <input id="s3_no_keys" type="checkbox" name="s3_no_keys" value="1" @checked(old('s3_no_keys'))>
                    <span>This bucket authenticates with an instance role or default credential provider — clear any stored access keys</span>
                </label>
            </div>

            <div class="field">
                <label for="acl">Object ACL</label>
                <select id="acl" name="acl">
                    <option value="bucket-owner-full-control" @selected(old('acl', $acl ?: 'bucket-owner-full-control') === 'bucket-owner-full-control')>Bucket owner full control (AWS default)</option>
                    <option value="private" @selected(old('acl', $acl) === 'private')>Private (Cloudflare R2 and compatible stores)</option>
                    <option value="bucket-owner-read" @selected(old('acl', $acl) === 'bucket-owner-read')>Bucket owner read</option>
                </select>
                @error('acl')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Keep the AWS default unless your store rejects it. Cloudflare R2 needs <code>Private</code>.</p>
            </div>

            <div class="field">
                <label for="root">Key prefix in bucket (optional)</label>
                <input id="root" name="root" value="{{ old('root', $root) }}" autocomplete="off" placeholder="(bucket root)">
                @error('root')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <input type="hidden" name="use_path_style" value="0">
                <label class="check-row" for="use_path_style">
                    <input id="use_path_style" type="checkbox" name="use_path_style" value="1" @checked(old('use_path_style', $usePathStyle))>
                    <span>Use path-style addressing (required for MinIO and most non-AWS stores)</span>
                </label>
            </div>

            <button class="button" type="submit">Save backup settings</button>
        </form>
    </section>

    <section class="section" aria-labelledby="backup-test-heading">
        <div class="section-header">
            <h2 id="backup-test-heading">Test the offsite connection</h2>
            <span class="lede">Verify the offsite disk can write, read, list, and delete — the round-trip backup uploads and retention need.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.backups.test') }}">
            @csrf
            @if ($returnTo)<input type="hidden" name="from" value="{{ $returnTo }}">@endif
            <button class="button secondary" type="submit">Run offsite test</button>
        </form>
    </section>
</x-layouts.app>
