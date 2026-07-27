<x-layouts.app title="Storage settings">
    <p><a class="text-link" href="{{ $backUrl }}">{{ $backLabel }}</a></p>

    <x-page-header
        title="Attachment storage"
        subtitle="Choose where uploaded files are stored — the local disk or an S3-compatible bucket. Changes apply immediately, no restart." />

    @if (session('status'))
        <p class="status-message">{{ session('status') }}</p>
    @endif

    @if (session('error'))
        <p class="status-message">{{ session('error') }}</p>
    @endif

    <section class="section" aria-labelledby="storage-config-heading">
        <div class="section-header">
            <h2 id="storage-config-heading">Where new uploads land</h2>
            <span class="lede">Existing attachments keep serving from where they were saved — switching only affects new uploads.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.storage.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="disk">Storage disk</label>
                <select id="disk" name="disk">
                    @if ($externalDisk)
                        <option value="{{ $externalDisk }}" @selected(old('disk', $disk) === $externalDisk)>{{ $externalDisk }} (current, configured in env)</option>
                    @endif
                    <option value="attachments" @selected(old('disk', $disk) === 'attachments')>Local disk (this server)</option>
                    <option value="attachments-s3" @selected(old('disk', $disk) === 'attachments-s3')>S3-compatible bucket</option>
                </select>
                @error('disk')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Local storage works out of the box. Use an S3-compatible bucket (AWS S3, Cloudflare R2, DigitalOcean Spaces, MinIO) for durability or multi-server installs.</p>
            </div>

            <h3>S3-compatible bucket</h3>
            <p class="field-help">Used when the S3 disk is selected. The bucket must stay private — files are only served through Wayfindr, never a bucket URL.</p>

            <div class="field">
                <label for="bucket">Bucket</label>
                <input id="bucket" name="bucket" value="{{ old('bucket', $bucket) }}" autocomplete="off" placeholder="my-wayfindr-attachments">
                @error('bucket')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="region">Region</label>
                <input id="region" name="region" value="{{ old('region', $region) }}" autocomplete="off" placeholder="us-east-1">
                @error('region')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">For Cloudflare R2 use <code>auto</code>.</p>
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
                @if ($keyUnreadable)
                    <p class="field-error">The saved access key could not be decrypted (this can happen after an APP_KEY change). Re-enter it below.</p>
                @endif
            </div>

            <div class="field">
                <label for="s3_secret_key">Secret access key</label>
                <input id="s3_secret_key" name="s3_secret_key" type="password" autocomplete="new-password"
                    placeholder="{{ $secretUnreadable ? 'Could not read the saved secret — re-enter it' : ($secretIsSet ? '•••••••• (a secret is configured)' : 'No secret configured') }}">
                @error('s3_secret_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($secretUnreadable)
                    <p class="field-error">The saved secret access key could not be decrypted (this can happen after an APP_KEY change). Re-enter it below.</p>
                @endif
                <p class="field-help">Access keys are stored encrypted and never shown. Leave blank to keep the saved values.</p>
            </div>

            <div class="field">
                <label for="acl">Object ACL</label>
                <select id="acl" name="acl">
                    <option value="bucket-owner-full-control" @selected(old('acl', $acl ?: 'bucket-owner-full-control') === 'bucket-owner-full-control')>Bucket owner full control (AWS default)</option>
                    <option value="private" @selected(old('acl', $acl) === 'private')>Private (Cloudflare R2 and compatible stores)</option>
                    <option value="bucket-owner-read" @selected(old('acl', $acl) === 'bucket-owner-read')>Bucket owner read</option>
                </select>
                @error('acl')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">Keep the AWS default unless your store rejects it. Cloudflare R2 needs <code>Private</code>. Only private ACLs are allowed — attachments are never public.</p>
            </div>

            <div class="field">
                {{-- Always submit a value so unchecking the box survives a
                     validation error — otherwise an absent key falls back to the
                     saved value and silently re-checks it. --}}
                <input type="hidden" name="use_path_style" value="0">
                <label class="check-row" for="use_path_style">
                    <input id="use_path_style" type="checkbox" name="use_path_style" value="1" @checked(old('use_path_style', $usePathStyle))>
                    <span>Use path-style addressing (required for MinIO and most non-AWS stores)</span>
                </label>
            </div>

            <button class="button" type="submit">Save storage settings</button>
        </form>
    </section>

    <section class="section" aria-labelledby="storage-test-heading">
        <div class="section-header">
            <h2 id="storage-test-heading">Test the connection</h2>
            <span class="lede">Verify the active disk can write, read, list, and delete — the round-trip uploads and cleanup need.</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.storage.test') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <button class="button secondary" type="submit">Run storage test</button>
        </form>
    </section>
</x-layouts.app>
