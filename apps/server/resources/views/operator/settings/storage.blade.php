<x-layouts.operator :title="__('operator.storage.document_title')">

    <x-page-header
        :back-href="$backUrl ?? null"
        :back-label="$backLabel ?? __('operator.shell.back')"
        :title="__('operator.storage.title')"
        :subtitle="__('operator.storage.subtitle')" />

    @foreach (['status', 'error'] as $feedbackType)
        @if ($feedback = session($feedbackType))
            <p class="status-message"><x-operator-feedback :feedback="$feedback" /></p>
        @endif
    @endforeach

    <section class="section" aria-labelledby="storage-config-heading">
        <div class="section-header">
            <h2 id="storage-config-heading">{{ __('operator.storage.heading') }}</h2>
            <span class="lede">{{ __('operator.storage.lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.storage.update') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <div class="field">
                <label for="disk">{{ __('operator.storage.disk') }}</label>
                <select id="disk" name="disk">
                    @if ($externalDisk)
                        <option lang="" value="{{ $externalDisk }}" @selected(old('disk', $disk) === $externalDisk)>{{ $externalDisk }}</option>
                    @endif
                    <option value="attachments" @selected(old('disk', $disk) === 'attachments')>{{ __('operator.storage.local_disk') }}</option>
                    <option value="attachments-s3" @selected(old('disk', $disk) === 'attachments-s3')>{{ __('operator.storage.s3_disk') }}</option>
                </select>
                @error('disk')<p class="field-error">{{ $message }}</p>@enderror
                @if ($externalDisk)
                    <p class="field-help">{!! __('operator.storage.external_disk_help', ['disk' => '<code lang="">'.e($externalDisk).'</code>']) !!}</p>
                @endif
                <p class="field-help">{!! __('operator.storage.disk_help', [
                    'aws' => '<span lang="">AWS S3</span>',
                    'r2' => '<span lang="">Cloudflare R2</span>',
                    'spaces' => '<span lang="">DigitalOcean Spaces</span>',
                    'minio' => '<span lang="">MinIO</span>',
                ]) !!}</p>
            </div>

            <h3>{!! __('operator.storage.s3_heading', ['s3' => '<span lang="">S3</span>']) !!}</h3>
            <p class="field-help">{!! __('operator.storage.s3_help', ['s3' => '<span lang="">S3</span>']) !!}</p>

            <div class="field">
                <label for="bucket">{{ __('operator.storage.bucket') }}</label>
                <input id="bucket" name="bucket" lang="" value="{{ old('bucket', $bucket) }}" autocomplete="off" placeholder="my-wayfindr-attachments">
                @error('bucket')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field">
                <label for="region">{{ __('operator.storage.region') }}</label>
                <input id="region" name="region" lang="" value="{{ old('region', $region) }}" autocomplete="off" placeholder="us-east-1">
                @error('region')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.storage.region_help', [
                    'r2' => '<span lang="">Cloudflare R2</span>',
                    'auto' => '<code lang="">auto</code>',
                ]) !!}</p>
            </div>

            <div class="field">
                <label for="endpoint">{{ __('operator.storage.endpoint') }}</label>
                <input id="endpoint" name="endpoint" lang="" value="{{ old('endpoint', $endpoint) }}" autocomplete="off" placeholder="https://…">
                @error('endpoint')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.storage.endpoint_help', [
                    'aws' => '<span lang="">AWS S3</span>',
                    'r2' => '<span lang="">Cloudflare R2</span>',
                    'spaces' => '<span lang="">DigitalOcean Spaces</span>',
                    'minio' => '<span lang="">MinIO</span>',
                ]) !!}</p>
            </div>

            <div class="field">
                <label for="s3_access_key">{{ __('operator.storage.access_key') }}</label>
                <input id="s3_access_key" name="s3_access_key" type="password" autocomplete="off"
                    placeholder="{{ $keyUnreadable ? __('operator.storage.key_placeholder_unreadable') : ($keyIsSet ? __('operator.storage.key_placeholder_configured') : __('operator.storage.key_placeholder_none')) }}">
                @error('s3_access_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($keyUnreadable)
                    <p class="field-error">{{ __('operator.storage.key_unreadable') }}</p>
                @endif
            </div>

            <div class="field">
                <label for="s3_secret_key">{{ __('operator.storage.secret_key') }}</label>
                <input id="s3_secret_key" name="s3_secret_key" type="password" autocomplete="new-password"
                    placeholder="{{ $secretUnreadable ? __('operator.storage.secret_placeholder_unreadable') : ($secretIsSet ? __('operator.storage.secret_placeholder_configured') : __('operator.storage.secret_placeholder_none')) }}">
                @error('s3_secret_key')<p class="field-error">{{ $message }}</p>@enderror
                @if ($secretUnreadable)
                    <p class="field-error">{{ __('operator.storage.secret_unreadable') }}</p>
                @endif
                <p class="field-help">{{ __('operator.storage.credentials_help') }}</p>
                <label class="check-row" for="s3_no_keys">
                    <input id="s3_no_keys" type="checkbox" name="s3_no_keys" value="1" @checked(old('s3_no_keys'))>
                    <span>{{ __('operator.storage.no_keys') }}</span>
                </label>
            </div>

            <div class="field">
                <label for="acl">{{ __('operator.storage.acl') }}</label>
                <select id="acl" name="acl">
                    <option value="bucket-owner-full-control" @selected(old('acl', $acl ?: 'bucket-owner-full-control') === 'bucket-owner-full-control')>{{ __('operator.storage.acl_owner_full') }}</option>
                    <option value="private" @selected(old('acl', $acl) === 'private')>{{ __('operator.storage.acl_private') }}</option>
                    <option value="bucket-owner-read" @selected(old('acl', $acl) === 'bucket-owner-read')>{{ __('operator.storage.acl_owner_read') }}</option>
                </select>
                @error('acl')<p class="field-error">{{ $message }}</p>@enderror
                <p class="field-help">{!! __('operator.storage.acl_help', [
                    'aws' => '<span lang="">AWS S3</span>',
                    'r2' => '<span lang="">Cloudflare R2</span>',
                ]) !!}</p>
            </div>

            <div class="field">
                <label class="check-row" for="s3_confirm_migrated">
                    <input id="s3_confirm_migrated" type="checkbox" name="s3_confirm_migrated" value="1" @checked(old('s3_confirm_migrated'))>
                    <span>{{ __('operator.storage.confirm_migrated') }}</span>
                </label>
            </div>

            <div class="field">
                {{-- Always submit a value so unchecking the box survives a
                     validation error — otherwise an absent key falls back to the
                     saved value and silently re-checks it. --}}
                <input type="hidden" name="use_path_style" value="0">
                <label class="check-row" for="use_path_style">
                    <input id="use_path_style" type="checkbox" name="use_path_style" value="1" @checked(old('use_path_style', $usePathStyle))>
                    <span>{!! __('operator.storage.path_style', [
                        'minio' => '<span lang="">MinIO</span>',
                        'aws' => '<span lang="">AWS S3</span>',
                    ]) !!}</span>
                </label>
            </div>

            <button class="button" type="submit">{{ __('operator.storage.save') }}</button>
        </form>
    </section>

    <section class="section" aria-labelledby="storage-test-heading">
        <div class="section-header">
            <h2 id="storage-test-heading">{{ __('operator.storage.test_heading') }}</h2>
            <span class="lede">{{ __('operator.storage.test_lede') }}</span>
        </div>

        <form class="section-form" method="POST" action="{{ route('operator.settings.storage.test') }}">
            @csrf
            @if ($returnTo)
                <input type="hidden" name="from" value="{{ $returnTo }}">
            @endif

            <button class="button secondary" type="submit">{{ __('operator.storage.test') }}</button>
        </form>
    </section>
</x-layouts.operator>
