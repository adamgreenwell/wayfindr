<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\ConversationMessageAttachment;
use App\Models\OperatorSetting;
use App\Support\Attachments\AttachmentStorage;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operator attachment-storage configuration (ADR 0011 slice 2a). Choose where
 * NEW uploads land — the local private disk or an S3-compatible bucket (AWS S3,
 * Cloudflare R2, DigitalOcean Spaces, MinIO) — and supply that bucket's
 * connection, all as DB-backed overrides live with no restart. A "test
 * connection" button probes the active disk (write / read / list / delete).
 * Secrets are write-only (never echoed).
 *
 * Existing attachments keep serving from the disk recorded on each row, so a
 * switch only affects new uploads — an existing file is never stranded.
 */
class OperatorStorageSettingsController extends Controller
{
    private const LOCAL_DISK = 'attachments';

    private const S3_DISK = 'attachments-s3';

    /**
     * The private object ACLs the form offers and accepts — a subset of the
     * allowlist AttachmentStorage::assertSafeDisk enforces, all non-empty. A
     * public ACL is never an option (attachments only stream through Wayfindr);
     * the "send no ACL header" case (env's array_filter of a blank value) stays
     * env-only, since the override mechanism can't unset a config key.
     *
     * @var list<string>
     */
    private const SAFE_ACLS = ['bucket-owner-full-control', 'private', 'bucket-owner-read'];

    public function edit(Request $request, OperatorSettings $settings): View
    {
        $disk = (string) $settings->effective('storage.disk');
        $from = $this->returnContext($request);

        return view('operator.settings.storage', [
            'operator' => $request->user(),
            'disk' => $disk === '' ? self::LOCAL_DISK : $disk,
            // A custom attachments-* disk configured in env is offered as a
            // preserved option so saving never silently switches it.
            'externalDisk' => in_array($disk, ['', self::LOCAL_DISK, self::S3_DISK], true) ? null : $disk,
            'bucket' => (string) $settings->effective('storage.s3_bucket'),
            'region' => (string) $settings->effective('storage.s3_region'),
            'endpoint' => (string) $settings->effective('storage.s3_endpoint'),
            'acl' => (string) $settings->effective('storage.s3_acl'),
            'usePathStyle' => filter_var($settings->effective('storage.s3_use_path_style'), FILTER_VALIDATE_BOOL),
            // Secrets show only whether one is effectively set (env or override),
            // never their value — and never 500 on an undecryptable override.
            'keyIsSet' => $settings->effectiveSecretStatus('storage.s3_key') === 'set',
            'keyUnreadable' => $settings->secretStatus('storage.s3_key') === 'unreadable',
            'secretIsSet' => $settings->effectiveSecretStatus('storage.s3_secret') === 'set',
            'secretUnreadable' => $settings->secretStatus('storage.s3_secret') === 'unreadable',
            'backUrl' => $from === 'onboarding' ? route('operator.onboarding') : route('operator.dashboard'),
            'backLabel' => $from === 'onboarding' ? 'Back to setup checklist' : 'Back to operator console',
            'returnTo' => $from,
        ]);
    }

    public function update(Request $request, OperatorSettings $settings): RedirectResponse
    {
        // Allow keeping a custom attachments-* disk configured outside this form;
        // the form can otherwise only choose the local or S3 disk.
        $currentDisk = (string) $settings->effective('storage.disk');
        $allowedDisks = array_values(array_unique([
            self::LOCAL_DISK,
            self::S3_DISK,
            $currentDisk !== '' ? $currentDisk : self::LOCAL_DISK,
        ]));

        // The single form always submits the (possibly prefilled) S3 fields even
        // when the local disk is chosen. Exclude them from validation unless S3
        // is selected, so a malformed prefilled endpoint can't block the recovery
        // path of switching back to local.
        $s3Only = 'exclude_unless:disk,'.self::S3_DISK;

        $validated = $request->validate([
            'disk' => ['required', Rule::in($allowedDisks)],
            'bucket' => [$s3Only, 'required', 'string', 'max:255'],
            'region' => [$s3Only, 'required', 'string', 'max:255'],
            'endpoint' => [$s3Only, 'nullable', 'string', 'max:255', 'url'],
            // A private ACL is required for S3 (a public one can never be set); a
            // blank value is rejected so it can never be stored as an empty ACL.
            'acl' => [$s3Only, 'required', Rule::in(self::SAFE_ACLS)],
            // s3_access_key / s3_secret_key are registered in the exception
            // handler's dontFlash list, so a validation failure never flashes
            // these credentials into the session as plaintext old input.
            's3_access_key' => [$s3Only, 'nullable', 'string', 'max:255'],
            's3_secret_key' => [$s3Only, 'nullable', 'string', 'max:1024'],
            's3_no_keys' => [$s3Only, 'nullable', 'boolean'],
            'use_path_style' => [$s3Only, 'nullable', 'boolean'],
        ]);

        $disk = $validated['disk'];
        $keyProvided = ($validated['s3_access_key'] ?? '') !== '';
        $secretProvided = ($validated['s3_secret_key'] ?? '') !== '';
        // "Use a role / clear stored keys" — drops both static credentials so the
        // AWS SDK default provider chain (EC2/ECS/IRSA role, environment, shared
        // config) is used. Both are cleared together to keep the pair matched.
        $clearCreds = $disk === self::S3_DISK && (bool) ($validated['s3_no_keys'] ?? false);

        if ($disk === self::S3_DISK) {
            if ($clearCreds && ($keyProvided || $secretProvided)) {
                return redirect()
                    ->route('operator.settings.storage.edit', $this->returnParams($request))
                    ->withErrors(['s3_access_key' => 'Either clear the stored keys to use a role, or enter new static keys — not both. Uncheck the role option to set static keys.'])
                    ->withInput($request->except(['s3_access_key', 's3_secret_key']));
            }

            // The access key ID and secret are a matched pair. Replacing only one
            // half against a stored pair leaves mismatched credentials that fail
            // every upload, so require both together or neither. Both blank is
            // valid — it keeps the saved pair, or (with the role option) uses the
            // default provider chain; the connection test surfaces a real auth
            // failure.
            if (! $clearCreds && $keyProvided !== $secretProvided) {
                return redirect()
                    ->route('operator.settings.storage.edit', $this->returnParams($request))
                    ->withErrors(['s3_access_key' => 'Enter both the access key and secret together, or leave both blank to keep the saved pair.'])
                    ->withInput($request->except(['s3_access_key', 's3_secret_key']));
            }
        }

        $agent = $request->user();

        DB::transaction(function () use ($settings, $validated, $disk, $keyProvided, $secretProvided, $clearCreds, $request, $agent): void {
            // Serialize concurrent storage-config writes: lock the storage.disk
            // setting row (created first so the lock has a target), then refresh
            // config from the committed database state under the lock. The guard
            // then compares the active disk AND the S3 location against the
            // committed-latest values via config() — not the versioned-cache
            // effective() snapshot, which may pre-date a concurrent change and let
            // a stale request overwrite a location where an attachment now lives.
            OperatorSetting::query()->firstOrCreate(['key' => 'storage.disk']);
            OperatorSetting::query()->where('key', 'storage.disk')->lockForUpdate()->first();
            $settings->refreshFromDatabase();
            $liveCurrentDisk = (string) config('wayfindr.attachments.storage_disk', self::LOCAL_DISK);

            if ($disk === self::S3_DISK) {
                // Existing attachments recorded against this disk name resolve
                // through whatever config it currently holds
                // (ConversationMessageAttachment::disk()), so changing the bucket,
                // endpoint, or region would strand them. Block a location change
                // while the disk holds files OR is the active upload target (an
                // in-flight upload has already resolved the old location and would
                // land a row against the new one). The operator must switch storage
                // to the local disk first — draining new S3 uploads. Credential,
                // ACL, and path-style changes keep the same location and stay
                // allowed. Re-checked here under the lock for atomicity.
                $s3IsActive = $liveCurrentDisk === self::S3_DISK;
                $s3HasRows = ConversationMessageAttachment::query()->where('storage_disk', self::S3_DISK)->exists();

                if ($this->s3LocationChanged($validated) && ($s3IsActive || $s3HasRows)) {
                    throw ValidationException::withMessages([
                        'bucket' => 'Changing the S3 bucket, endpoint, or region is not allowed while S3 is the active storage disk or already holds attachments — existing or in-flight uploads would be stranded. Switch storage to the local disk first, then change the S3 connection. If attachments already live on S3, migrate the objects and update the environment directly.',
                    ]);
                }
            }

            $settings->set('storage.disk', $disk);

            // Only touch the S3 connection when S3 is the chosen disk, so
            // switching to local never blanks env-provided S3 credentials.
            if ($disk === self::S3_DISK) {
                $settings->set('storage.s3_bucket', $this->explicit($validated['bucket'] ?? null));
                $settings->set('storage.s3_region', $this->explicit($validated['region'] ?? null));
                $settings->set('storage.s3_endpoint', $this->explicit($validated['endpoint'] ?? null));
                // Validated to a non-empty private ACL for S3.
                $settings->set('storage.s3_acl', $validated['acl']);
                $settings->set('storage.s3_use_path_style', $request->boolean('use_path_style') ? '1' : '0');

                // Keys are write-only. Clear both together (role / provider chain),
                // set both when supplied, otherwise leave the stored pair alone.
                if ($clearCreds) {
                    $settings->set('storage.s3_key', '');
                    $settings->set('storage.s3_secret', '');
                } else {
                    if ($keyProvided) {
                        $settings->set('storage.s3_key', $validated['s3_access_key']);
                    }
                    if ($secretProvided) {
                        $settings->set('storage.s3_secret', $validated['s3_secret_key']);
                    }
                }
            }

            AuditEvent::query()->create([
                // Instance-wide config is not a tenant event (see slice 1b).
                'account_id' => null,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.storage.updated',
                'metadata' => [
                    'disk' => $disk,
                    'bucket' => $disk === self::S3_DISK ? ($validated['bucket'] ?? null) : null,
                    'region' => $disk === self::S3_DISK ? ($validated['region'] ?? null) : null,
                    'acl' => $disk === self::S3_DISK ? ($validated['acl'] ?? null) : null,
                    'key_changed' => $clearCreds ? 'cleared' : ($keyProvided ? 'updated' : 'unchanged'),
                    'secret_changed' => $clearCreds ? 'cleared' : ($secretProvided ? 'updated' : 'unchanged'),
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.storage.edit', $this->returnParams($request))
            ->with('status', 'Storage settings saved. Run a connection test to confirm uploads can be stored.');
    }

    public function test(Request $request): RedirectResponse
    {
        $returnParams = $this->returnParams($request);

        // diskName() enforces the safe/dedicated/private guarantees before we
        // touch the disk — the same check upload routing uses.
        try {
            $diskName = AttachmentStorage::diskName();
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.settings.storage.edit', $returnParams)
                ->with('error', 'Storage is misconfigured: '.$exception->getMessage());
        }

        $failure = $this->probeDisk($diskName);

        if ($failure !== null) {
            return redirect()
                ->route('operator.settings.storage.edit', $returnParams)
                ->with('error', 'Storage test failed on the ['.$diskName.'] disk: '.$failure);
        }

        return redirect()
            ->route('operator.settings.storage.edit', $returnParams)
            ->with('status', 'Storage test passed: the ['.$diskName.'] disk accepted a write, read, list, and delete.');
    }

    /**
     * The write / read / list / delete round-trip that uploads AND the retention
     * sweep rely on. A dotfile-prefixed probe keeps the orphan sweep from ever
     * racing it. Returns a failure message, or null on success.
     */
    private function probeDisk(string $diskName): ?string
    {
        $dir = '.wayfindr-storage-test-'.Str::random(12);
        $probeKey = $dir.'/.probe';
        $disk = null;
        $needsCleanup = false;

        try {
            // Building the disk can throw before any I/O — an unsupported custom
            // driver, or an S3 client that rejects its configuration. Keep it in
            // the guarded block so the test reports an actionable error, not 500.
            $disk = Storage::disk($diskName);
            $wrote = $disk->put($probeKey, 'ok') !== false;
            $needsCleanup = $wrote;

            if (! $wrote || $disk->get($probeKey) !== 'ok') {
                return 'a write/read round-trip failed.';
            }

            if (! in_array($probeKey, $disk->files($dir), true)) {
                return 'writes work but a listing probe did not return the object — the retention sweep needs list access.';
            }

            if ($disk->delete($probeKey) === false || $disk->exists($probeKey)) {
                return 'writes work but the probe could not be deleted — the retention sweep and upload cleanup need delete access.';
            }

            $needsCleanup = false; // deleted cleanly

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        } finally {
            // If an intermediate step (read/list) threw or returned early after a
            // successful write, best-effort remove the probe object — it is
            // dotfile-prefixed, so the orphan sweep would never reclaim it.
            if ($needsCleanup && $disk !== null) {
                try {
                    $disk->delete($probeKey);
                } catch (Throwable) {
                    // best effort
                }
            }
        }
    }

    /**
     * Whether the submitted S3 location (bucket / endpoint / region) differs from
     * the committed current one — the fields that determine where existing
     * attachments physically live. Credentials, ACL, and path-style are not
     * location, so they are excluded. Compares against config(), which the caller
     * refreshes from the database under the lock, so a concurrent location change
     * is seen (unlike the versioned-cache effective() snapshot).
     *
     * @param  array<string, mixed>  $validated
     */
    private function s3LocationChanged(array $validated): bool
    {
        return $this->explicit($validated['bucket'] ?? null) !== (string) config('filesystems.disks.attachments-s3.bucket')
            || $this->explicit($validated['endpoint'] ?? null) !== (string) config('filesystems.disks.attachments-s3.endpoint')
            || $this->explicit($validated['region'] ?? null) !== (string) config('filesystems.disks.attachments-s3.region');
    }

    /** The allow-listed onboarding return context, or null. */
    private function returnContext(Request $request): ?string
    {
        return $request->input('from') === 'onboarding' ? 'onboarding' : null;
    }

    /** @return array<string, string> */
    private function returnParams(Request $request): array
    {
        $from = $this->returnContext($request);

        return $from !== null ? ['from' => $from] : [];
    }

    /** The trimmed submitted value as an explicit override — '' for a blank field, never null. */
    private function explicit(?string $value): string
    {
        return trim((string) $value);
    }
}
