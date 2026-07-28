<?php

namespace App\Http\Controllers;

use App\Jobs\RunBackupJob;
use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Support\Backup\BackupService;
use App\Support\Settings\OperatorSettings;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Operator backup configuration (ADR 0011 slice 3). Configure the optional
 * offsite mirror disk, age-based retention, and per-install prefix; supply the
 * offsite S3 connection; test that connection; and queue a "run a backup now"
 * (a backup can be slow, so it runs off the request via RunBackupJob). All
 * DB-backed overrides, live with no restart. Access keys are write-only.
 *
 * The local write PATH stays env-only — a host filesystem path, like the
 * boot-critical config that is deliberately not moved to the database.
 */
class OperatorBackupSettingsController extends Controller
{
    private const OFFSITE_DISK = 'backups';

    /** The private object ACLs the form offers and accepts (see storage slice). */
    private const SAFE_ACLS = ['bucket-owner-full-control', 'private', 'bucket-owner-read'];

    public function edit(Request $request, OperatorSettings $settings): View
    {
        $disk = trim((string) $settings->effective('backup.disk'));
        $from = $this->returnContext($request);

        return view('operator.settings.backups', [
            'operator' => $request->user(),
            'disk' => $disk,
            // A custom offsite disk configured in env is preserved so saving
            // another field can't silently switch it off.
            'externalDisk' => ($disk !== '' && $disk !== self::OFFSITE_DISK) ? $disk : null,
            'retentionDays' => (int) $settings->effective('backup.retention_days'),
            'prefix' => (string) $settings->effective('backup.prefix'),
            'bucket' => (string) $settings->effective('backup.s3_bucket'),
            'region' => (string) $settings->effective('backup.s3_region'),
            'endpoint' => (string) $settings->effective('backup.s3_endpoint'),
            'acl' => (string) $settings->effective('backup.s3_acl'),
            'root' => (string) $settings->effective('backup.s3_root'),
            'usePathStyle' => filter_var($settings->effective('backup.s3_use_path_style'), FILTER_VALIDATE_BOOL),
            'keyIsSet' => $settings->effectiveSecretStatus('backup.s3_key') === 'set',
            'keyUnreadable' => $settings->secretStatus('backup.s3_key') === 'unreadable',
            'secretIsSet' => $settings->effectiveSecretStatus('backup.s3_secret') === 'set',
            'secretUnreadable' => $settings->secretStatus('backup.s3_secret') === 'unreadable',
            'latestRun' => BackupRun::query()->latest('started_at')->first(),
            'backUrl' => $from === 'onboarding' ? route('operator.onboarding') : route('operator.dashboard'),
            'backLabel' => $from === 'onboarding' ? 'Back to setup checklist' : 'Back to operator console',
            'returnTo' => $from,
        ]);
    }

    public function update(Request $request, OperatorSettings $settings): RedirectResponse
    {
        $currentDisk = trim((string) $settings->effective('backup.disk'));
        $allowedDisks = array_values(array_unique(array_filter(['', self::OFFSITE_DISK, $currentDisk])));

        // S3 connection fields only matter when an offsite disk is chosen —
        // exclude them otherwise so a stale/blank field can't block turning
        // offsite off or editing retention.
        $offsite = 'exclude_unless:disk,'.self::OFFSITE_DISK;

        $validated = $request->validate([
            // "Local only" submits '' which ConvertEmptyStringsToNull turns to
            // null; nullable accepts it (treated as local-only below), while a
            // non-empty value must be an allowed disk.
            'disk' => ['nullable', Rule::in($allowedDisks)],
            'retention_days' => ['nullable', 'integer', 'between:0,3650'],
            // Mirror BackupService::backupPrefix(): a prefix is a namespace UNDER
            // the destination, never an escape. Reject `..` segments here so an
            // unusable prefix can't be saved (and pass the probe) only to fail
            // every backup at runtime.
            'prefix' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (preg_match('#(^|/)\.\.(/|$)#', trim((string) $value, '/')) === 1) {
                    $fail('The prefix must not contain ".." path segments.');
                }
            }],
            'bucket' => [$offsite, 'required', 'string', 'max:255'],
            'region' => [$offsite, 'required', 'string', 'max:255'],
            'endpoint' => [$offsite, 'nullable', 'string', 'max:255', 'url'],
            'acl' => [$offsite, 'required', Rule::in(self::SAFE_ACLS)],
            'root' => [$offsite, 'nullable', 'string', 'max:255'],
            's3_access_key' => [$offsite, 'nullable', 'string', 'max:255'],
            's3_secret_key' => [$offsite, 'nullable', 'string', 'max:1024'],
            's3_no_keys' => [$offsite, 'nullable', 'boolean'],
            'use_path_style' => [$offsite, 'nullable', 'boolean'],
        ]);

        $disk = (string) ($validated['disk'] ?? ''); // '' = local only
        $keyProvided = ($validated['s3_access_key'] ?? '') !== '';
        $secretProvided = ($validated['s3_secret_key'] ?? '') !== '';
        $clearCreds = $disk === self::OFFSITE_DISK && (bool) ($validated['s3_no_keys'] ?? false);

        if ($disk === self::OFFSITE_DISK) {
            if ($clearCreds && ($keyProvided || $secretProvided)) {
                return $this->credentialError($request, 'Either clear the stored keys to use a role, or enter new static keys — not both.');
            }

            // Matched pair: both together, or neither. Both blank is valid (keeps
            // the saved pair, or uses the SDK default provider chain).
            if (! $clearCreds && $keyProvided !== $secretProvided) {
                return $this->credentialError($request, 'Enter both the access key and secret together, or leave both blank to keep the saved pair.');
            }
        }

        $agent = $request->user();

        DB::transaction(function () use ($settings, $validated, $disk, $keyProvided, $secretProvided, $clearCreds, $request, $agent): void {
            $settings->set('backup.disk', $disk);
            $settings->set('backup.retention_days', (string) (int) ($validated['retention_days'] ?? 0));
            $settings->set('backup.prefix', trim((string) ($validated['prefix'] ?? '')));

            // Only touch the S3 connection when the offsite disk is chosen, so
            // switching offsite off never blanks env-provided credentials.
            if ($disk === self::OFFSITE_DISK) {
                $settings->set('backup.s3_bucket', trim((string) ($validated['bucket'] ?? '')));
                $settings->set('backup.s3_region', trim((string) ($validated['region'] ?? '')));
                $settings->set('backup.s3_endpoint', trim((string) ($validated['endpoint'] ?? '')));
                $settings->set('backup.s3_acl', $validated['acl']);
                $settings->set('backup.s3_root', trim((string) ($validated['root'] ?? '')));
                $settings->set('backup.s3_use_path_style', $request->boolean('use_path_style') ? '1' : '0');

                if ($clearCreds) {
                    $settings->set('backup.s3_key', '');
                    $settings->set('backup.s3_secret', '');
                } else {
                    if ($keyProvided) {
                        $settings->set('backup.s3_key', $validated['s3_access_key']);
                    }
                    if ($secretProvided) {
                        $settings->set('backup.s3_secret', $validated['s3_secret_key']);
                    }
                }
            }

            AuditEvent::query()->create([
                'account_id' => null, // instance-wide, not a tenant event
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agent->id,
                'action' => 'operator_settings.backup.updated',
                'metadata' => [
                    'offsite_disk' => $disk === '' ? 'local-only' : $disk,
                    'retention_days' => (int) ($validated['retention_days'] ?? 0),
                    'key_changed' => $clearCreds ? 'cleared' : ($keyProvided ? 'updated' : 'unchanged'),
                    'secret_changed' => $clearCreds ? 'cleared' : ($secretProvided ? 'updated' : 'unchanged'),
                ],
                'occurred_at' => now(),
            ]);
        });

        return redirect()
            ->route('operator.settings.backups.edit', $this->returnParams($request))
            ->with('status', 'Backup settings saved.'.($disk === self::OFFSITE_DISK ? ' Run a connection test to confirm offsite uploads can be stored.' : ''));
    }

    public function test(Request $request, BackupService $backups): RedirectResponse
    {
        $returnParams = $this->returnParams($request);
        $diskName = trim((string) config('wayfindr.backup.disk'));

        if ($diskName === '') {
            return redirect()
                ->route('operator.settings.backups.edit', $returnParams)
                ->with('error', 'No offsite disk is configured — backups are written to the local path only. Enable offsite backups and save to test a connection.');
        }

        if (config("filesystems.disks.{$diskName}") === null) {
            return redirect()
                ->route('operator.settings.backups.edit', $returnParams)
                ->with('error', 'The backup disk ['.$diskName.'] is not configured.');
        }

        // Probe INSIDE the configured prefix, where real uploads and retention
        // operate — credentials scoped to that prefix would fail a top-level
        // probe even though backups work.
        try {
            $prefix = $backups->backupPrefix();
        } catch (Throwable $exception) {
            return redirect()
                ->route('operator.settings.backups.edit', $returnParams)
                ->with('error', 'The backup prefix is invalid: '.$exception->getMessage());
        }

        $failure = $this->probeDisk($diskName, $prefix);

        if ($failure !== null) {
            return redirect()
                ->route('operator.settings.backups.edit', $returnParams)
                ->with('error', 'Offsite backup test failed on the ['.$diskName.'] disk: '.$failure);
        }

        return redirect()
            ->route('operator.settings.backups.edit', $returnParams)
            ->with('status', 'Offsite backup test passed: the ['.$diskName.'] disk accepted a write, read, list, and delete.');
    }

    public function run(Request $request): RedirectResponse
    {
        $agent = $request->user();

        // Create the run row BEFORE dispatch so its id rides in the job payload —
        // the job's failed() callback (e.g. on a worker timeout) runs on a fresh
        // instance from that payload and needs the id to mark the run failed.
        $run = BackupRun::query()->create([
            'status' => BackupRun::STATUS_RUNNING,
            'triggered_by_id' => $agent->id,
            'started_at' => now(),
        ]);

        RunBackupJob::dispatch($run->id);

        AuditEvent::query()->create([
            'account_id' => null,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'action' => 'operator_settings.backup.triggered',
            'metadata' => [
                'offsite_disk' => trim((string) config('wayfindr.backup.disk')) ?: 'local-only',
            ],
            'occurred_at' => now(),
        ]);

        return redirect()
            ->route('operator.settings.backups.edit', $this->returnParams($request))
            ->with('status', 'Backup queued. It runs in the background — the latest run appears below once a worker picks it up. Confirm the queue worker is running if it stays queued.');
    }

    /**
     * The write / read / list / delete round-trip a backup upload needs, run
     * inside the given prefix so prefix-scoped credentials are exercised.
     */
    private function probeDisk(string $diskName, string $prefix): ?string
    {
        $dir = trim($prefix, '/').'/.wayfindr-backup-test-'.Str::random(12);
        $probeKey = $dir.'/.probe';
        $disk = null;
        $needsCleanup = false;

        try {
            $disk = Storage::disk($diskName);
            $wrote = $disk->put($probeKey, 'ok') !== false;
            $needsCleanup = $wrote;

            if (! $wrote || $disk->get($probeKey) !== 'ok') {
                return 'a write/read round-trip failed.';
            }

            if (! in_array($probeKey, $disk->files($dir), true)) {
                return 'writes work but a listing probe did not return the object — retention needs list access.';
            }

            if ($disk->delete($probeKey) === false || $disk->exists($probeKey)) {
                return 'writes work but the probe could not be deleted — retention needs delete access.';
            }

            $needsCleanup = false;

            return null;
        } catch (Throwable $exception) {
            return $exception->getMessage();
        } finally {
            if ($needsCleanup && $disk !== null) {
                try {
                    $disk->delete($probeKey);
                } catch (Throwable) {
                    // best effort
                }
            }
        }
    }

    private function credentialError(Request $request, string $message): RedirectResponse
    {
        return redirect()
            ->route('operator.settings.backups.edit', $this->returnParams($request))
            ->withErrors(['s3_access_key' => $message])
            ->withInput($request->except(['s3_access_key', 's3_secret_key']));
    }

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
}
