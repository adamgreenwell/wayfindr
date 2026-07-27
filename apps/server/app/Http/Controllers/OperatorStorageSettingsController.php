<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Support\Attachments\AttachmentStorage;
use App\Support\Settings\OperatorSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
     * The private object ACLs the form offers and accepts — the same allowlist
     * AttachmentStorage::assertSafeDisk enforces. A public ACL is never an
     * option: attachments are only served by streaming through Wayfindr.
     *
     * @var list<string>
     */
    private const SAFE_ACLS = ['bucket-owner-full-control', 'private', 'bucket-owner-read', ''];

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

        $validated = $request->validate([
            'disk' => ['required', Rule::in($allowedDisks)],
            'bucket' => ['nullable', 'required_if:disk,'.self::S3_DISK, 'string', 'max:255'],
            'region' => ['nullable', 'required_if:disk,'.self::S3_DISK, 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:255', 'url'],
            // Only private ACLs are accepted — a public ACL can never be set here.
            'acl' => ['nullable', Rule::in(self::SAFE_ACLS)],
            // s3_access_key / s3_secret_key are registered in the exception
            // handler's dontFlash list, so a validation failure never flashes
            // these credentials into the session as plaintext old input.
            's3_access_key' => ['nullable', 'string', 'max:255'],
            's3_secret_key' => ['nullable', 'string', 'max:1024'],
            'use_path_style' => ['nullable', 'boolean'],
        ]);

        $disk = $validated['disk'];
        $keyProvided = ($validated['s3_access_key'] ?? '') !== '';
        $secretProvided = ($validated['s3_secret_key'] ?? '') !== '';

        // Choosing S3 needs credentials — supplied now or already stored — or
        // uploads will fail. Enforce beyond required_if, which cannot see the
        // write-only stored secret.
        if ($disk === self::S3_DISK) {
            $keyReady = $keyProvided || $settings->effectiveSecretStatus('storage.s3_key') === 'set';
            $secretReady = $secretProvided || $settings->effectiveSecretStatus('storage.s3_secret') === 'set';

            if (! $keyReady || ! $secretReady) {
                return redirect()
                    ->route('operator.settings.storage.edit', $this->returnParams($request))
                    ->withErrors(['s3_access_key' => 'An access key and secret are required to store attachments on S3.'])
                    // Never flash the credentials themselves back into the session.
                    ->withInput($request->except(['s3_access_key', 's3_secret_key']));
            }
        }

        $agent = $request->user();

        DB::transaction(function () use ($settings, $validated, $disk, $keyProvided, $secretProvided, $request, $agent): void {
            $settings->set('storage.disk', $disk);

            // Only touch the S3 connection when S3 is the chosen disk, so
            // switching to local never blanks env-provided S3 credentials.
            if ($disk === self::S3_DISK) {
                $settings->set('storage.s3_bucket', $this->explicit($validated['bucket'] ?? null));
                $settings->set('storage.s3_region', $this->explicit($validated['region'] ?? null));
                $settings->set('storage.s3_endpoint', $this->explicit($validated['endpoint'] ?? null));
                $settings->set('storage.s3_acl', $this->explicit($validated['acl'] ?? null));
                $settings->set('storage.s3_use_path_style', $request->boolean('use_path_style') ? '1' : '0');

                // Keys are write-only: set when supplied, otherwise leave the
                // stored value untouched.
                if ($keyProvided) {
                    $settings->set('storage.s3_key', $validated['s3_access_key']);
                }
                if ($secretProvided) {
                    $settings->set('storage.s3_secret', $validated['s3_secret_key']);
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
                    'key_changed' => $keyProvided ? 'updated' : 'unchanged',
                    'secret_changed' => $secretProvided ? 'updated' : 'unchanged',
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
        $disk = Storage::disk($diskName);
        $needsCleanup = false;

        try {
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
            if ($needsCleanup) {
                try {
                    $disk->delete($probeKey);
                } catch (Throwable) {
                    // best effort
                }
            }
        }
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
