<?php

namespace App\Http\Controllers;

use App\Jobs\RunBackupJob;
use App\Jobs\RunRestoreJob;
use App\Models\AuditEvent;
use App\Models\BackupRun;
use App\Support\Backup\BackupService;
use App\Support\Backup\RestoreService;
use App\Support\Queue\QueueConsumerHeartbeat;
use App\Support\Settings\OperatorSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    /**
     * How many recent runs the history view shows. backup_runs grows slowly (a
     * few a day), so a capped recent list — matching the audit log's approach,
     * no pagination component in this app — comfortably covers weeks of history.
     */
    private const HISTORY_LIMIT = 100;

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
            // started_at is second-precision, so order by id as a tiebreaker —
            // otherwise two runs triggered in the same second could show the
            // older one's status right after the operator queues a new backup.
            'latestRun' => BackupRun::query()->latest('started_at')->latest('id')->first(),
            // Whether anything is actually consuming the backups queue. Without
            // a worker the button below queues a job nothing will ever run, and
            // the only symptom is a run that says "Running" forever — so the
            // page says so up front rather than leaving the operator to infer it
            // from a backup that never finishes.
            'worker' => $this->workerObservation(),
            // The restore outcome lives in the cache (a restore wipes the DB), so
            // the operator sees it here after the restore logs them out and they
            // log back in.
            'restoreStatus' => $this->restoreStatus(),
            'backUrl' => $from === 'onboarding' ? route('operator.onboarding') : route('operator.dashboard'),
            'backLabel' => $from === 'onboarding' ? 'Back to setup checklist' : 'Back to operator console',
            'returnTo' => $from,
        ]);
    }

    /**
     * The full backup run history: the scheduled command and the operator
     * "run now" both record to backup_runs, so this is one place to confirm
     * backups are actually happening and succeeding.
     */
    public function history(Request $request): View
    {
        $runs = BackupRun::query()
            ->with('triggeredBy')
            ->latest('started_at')
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        return view('operator.settings.backups-history', [
            'operator' => $request->user(),
            'runs' => $runs,
            // Signals the capped list so a long-running install knows older runs
            // exist beyond what is shown.
            'atLimit' => $runs->count() === self::HISTORY_LIMIT,
            'limit' => self::HISTORY_LIMIT,
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

        // Backups can never live on an attachment disk (BackupService rejects it):
        // the orphaned-attachment sweep would delete archives written there. A
        // probe would otherwise pass right before every real backup fails.
        if (str_starts_with($diskName, 'attachments')) {
            return redirect()
                ->route('operator.settings.backups.edit', $returnParams)
                ->with('error', 'The backup disk ['.$diskName.'] is an attachment disk — the orphaned-attachment sweep would delete backups written there. Use a separate disk for backups.');
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

        // If the queue backend is unreachable, dispatch throws after the run row
        // is committed — finalize it so the GUI never shows a backup that was
        // never actually queued as permanently running.
        try {
            RunBackupJob::dispatch($run->id);
        } catch (Throwable $exception) {
            $run->update([
                'status' => BackupRun::STATUS_FAILED,
                'message' => 'Could not queue the backup: '.$exception->getMessage(),
                'finished_at' => now(),
            ]);

            return redirect()
                ->route('operator.settings.backups.edit', $this->returnParams($request))
                ->with('error', 'Could not queue the backup — confirm the queue backend is reachable: '.$exception->getMessage());
        }

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
     * The confirmed in-GUI restore (ADR 0011 slice 3b). Lists local archives and,
     * for a chosen one, shows a read-only preflight (version skew) before the
     * confirmation gates. A restore is the single most destructive action in the
     * app — it DROPs and reloads the whole database — so it is deliberately a
     * multi-step, typed-confirmation flow, and it is only offered when the queue
     * and cache are durable across the restore (see restoreIsDurable).
     */
    public function restore(Request $request, BackupService $backups, RestoreService $restores): View
    {
        $durabilityIssues = $this->restoreDurabilityIssues();
        $durable = $durabilityIssues === [];

        $selected = null;
        $preflight = null;
        $preflightError = null;
        $requested = trim((string) $request->query('archive', ''));

        // Preflight only a genuine local archive (resolveLocalArchivePath rejects
        // anything not in the real listing — the anti-traversal guard).
        if ($durable && $requested !== '') {
            $path = $backups->resolveLocalArchivePath($requested);

            if ($path === null) {
                $preflightError = 'That archive is no longer available on the local disk. Pick one from the list.';
            } else {
                try {
                    $preflight = $restores->preflight($path);
                    $selected = $requested;
                } catch (Throwable $exception) {
                    $preflightError = 'Could not read that archive: '.$exception->getMessage();
                }
            }
        }

        return view('operator.settings.backups-restore', [
            'operator' => $request->user(),
            'durable' => $durable,
            'durabilityIssues' => $durabilityIssues,
            'archives' => $backups->listLocalArchives(),
            'selected' => $selected,
            'preflight' => $preflight,
            'preflightError' => $preflightError,
            'instanceName' => trim((string) config('app.name')),
            'status' => $this->restoreStatus(),
        ]);
    }

    /**
     * Execute the confirmed restore: re-validate the archive and the typed
     * confirmation gates, then queue RunRestoreJob. The queued job (not this
     * request) does the destructive work off the request lifecycle.
     */
    public function restoreRun(Request $request, BackupService $backups, RestoreService $restores): RedirectResponse
    {
        // Re-check durability at submit time (config can change between the page
        // load and the submit).
        if (! $this->restoreIsDurable()) {
            return redirect()
                ->route('operator.settings.backups.restore')
                ->with('error', 'In-GUI restore is unavailable on this configuration. Restore from the server with php artisan wayfindr:restore.');
        }

        $archive = trim((string) $request->input('archive', ''));
        $path = $backups->resolveLocalArchivePath($archive);
        $expectedName = trim((string) config('app.name'));

        $errors = [];

        if ($path === null) {
            $errors['archive'] = 'Choose an archive from the list.';
        }

        // A typed instance name is the deliberate friction: it forces the
        // operator to name what they are about to overwrite.
        if ($expectedName === '' || trim((string) $request->input('confirm_name')) !== $expectedName) {
            $errors['confirm_name'] = 'Type the exact instance name to confirm: '.($expectedName !== '' ? $expectedName : '(APP_NAME is not set)');
        }

        if ($request->input('acknowledge') !== '1') {
            $errors['acknowledge'] = 'You must acknowledge that restoring erases all current data.';
        }

        // The app enters maintenance mode for the restore, but it cannot stop the
        // separate worker/scheduler OS processes, nor cut off an HTTP request or
        // upload already mid-write — maintenance mode only blocks NEW requests. So
        // the operator must attest the site is quiesced (workers stopped, no
        // long-running requests in flight), the part of the CLI quiescing
        // procedure the app cannot do itself.
        if ($request->input('workers_stopped') !== '1') {
            $errors['workers_stopped'] = 'Quiesce writes first — stop the background queue and scheduler workers and ensure no long-running uploads are in progress — then confirm. The restore cannot do this for you.';
        }

        // Re-run the read-only preflight at submit time (the archive may have been
        // submitted without previewing, or swapped since), so a malformed archive
        // is rejected HERE — before we queue a job that would enter maintenance
        // mode and then fail, leaving the site down.
        if ($path !== null && ! isset($errors['archive'])) {
            try {
                $restores->preflight($path);
            } catch (Throwable $exception) {
                $errors['archive'] = 'This archive could not be read: '.$exception->getMessage();
            }
        }

        if ($errors !== []) {
            return redirect()
                ->route('operator.settings.backups.restore', $path !== null ? ['archive' => $archive] : [])
                ->withErrors($errors);
        }

        // Claim the single pending-restore slot atomically BEFORE enqueueing, so a
        // double-submit (or two operators confirming) can't queue two destructive
        // restores that the dedicated worker would then run back to back. The
        // token ties this claim to the job that will release it.
        $pendingToken = RunRestoreJob::claimPending();

        if ($pendingToken === null) {
            return redirect()
                ->route('operator.settings.backups.restore', ['archive' => $archive])
                ->with('error', 'A restore is already queued or running. Wait for it to finish before starting another.');
        }

        $agent = $request->user();
        $agentId = $agent->id;
        $agentName = $agent->name;

        try {
            // Write an immediate durable status (cache, not DB — the restore wipes
            // the DB) so the operator sees "queued" even though the restore is
            // about to log them out.
            RunRestoreJob::putStatus('running', 'Restore queued…', $archive, $agentId, $agentName);

            // Audit now, as part of THIS request, so the write is committed before
            // the restore job could start. (This row is wiped when the restore
            // reloads the database, but it survives a failed/rolled-back restore,
            // and the durable outcome lives in the cache status above.)
            AuditEvent::query()->create([
                'account_id' => null,
                'actor_type' => $agent->getMorphClass(),
                'actor_id' => $agentId,
                'action' => 'operator_settings.backup.restore_triggered',
                'metadata' => ['archive' => $archive],
                'occurred_at' => now(),
            ]);

            // Enqueue only AFTER this request has finished ALL its database access
            // — its own writes, the audit above, and the database-backed session
            // save that runs in middleware terminate. An idle backups worker would
            // otherwise consume the job and DROP the schema while this request is
            // still writing (a failed response, or a write racing the restore).
            // terminating() runs after middleware terminate, so the session is
            // already saved by the time we enqueue.
            $enqueued = false;
            app()->terminating(function () use (&$enqueued, $archive, $agentId, $agentName, $pendingToken): void {
                // Terminating callbacks are not cleared after they run, so in a
                // long-lived app (tests, Octane) a later request's terminate would
                // re-fire this one; dispatch at most once.
                if ($enqueued) {
                    return;
                }
                $enqueued = true;

                try {
                    RunRestoreJob::dispatch($archive, $agentId, $agentName, $pendingToken);
                } catch (Throwable $exception) {
                    // The response is already sent, so surface the failure through
                    // the cache status the operator reads on the backups page, and
                    // free the pending slot so they can retry.
                    RunRestoreJob::putStatus('failed', 'Could not queue the restore: '.$exception->getMessage(), $archive, $agentId, $agentName);
                    RunRestoreJob::releasePending($pendingToken);
                }
            });
        } catch (Throwable $exception) {
            // The status write or audit failed (e.g. a transient cache/DB outage)
            // before anything was queued — free the pending slot so the operator
            // can retry once it clears, rather than being blocked until the TTL.
            RunRestoreJob::releasePending($pendingToken);
            report($exception);

            return redirect()
                ->route('operator.settings.backups.restore', ['archive' => $archive])
                ->with('error', 'Could not start the restore: '.$exception->getMessage());
        }

        return redirect()
            ->route('operator.settings.backups.edit')
            ->with('status', 'Restore queued from '.$archive.'. It replaces ALL current data and will log you out when it runs — wait a minute, log back in, and check the restore status here. If nothing changes, confirm the backup queue worker is running.');
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

    /**
     * Queue drivers safe for the restore: the reservation must survive the DB
     * reload and be worker-run. Only `redis` — the `backups` connection in
     * config/queue.php is redis/database-shaped, so sqs/beanstalkd could not even
     * be constructed from it (and there is no beanstalkd client), and `database`
     * is wiped by the restore.
     */
    private const RESTORE_SAFE_QUEUE_DRIVERS = ['redis'];

    /**
     * The in-GUI restore is only safe when the queue reservation AND the status
     * cache survive the database restore AND are shared between the web process
     * and the worker. Both are checked against an allowlist of network-shared
     * stores, NOT a `!== 'database'` test on the top-level driver — that would
     * wave through a `failover` chain that is database-backed underneath, and the
     * process-local `array`/`null` stores that the web request and the worker do
     * not share (the status would never sync and the lock would not exclude). The
     * shipped stack uses Redis for both; otherwise the operator is pointed at the
     * CLI.
     */
    private function restoreIsDurable(): bool
    {
        return $this->restoreDurabilityIssues() === [];
    }

    /**
     * The specific prerequisites the in-GUI restore fails, so the operator is told
     * exactly what to fix rather than a generic (and possibly wrong) message. The
     * queue reservation, the status cache, AND the maintenance-mode marker must
     * each survive the database reload and be shared between the web process and
     * the worker. Empty = safe to offer the in-GUI restore.
     *
     * @return list<string>
     */
    private function restoreDurabilityIssues(): array
    {
        $issues = [];

        $queueDriver = (string) config('queue.connections.backups.driver');
        if (! in_array($queueDriver, self::RESTORE_SAFE_QUEUE_DRIVERS, true)) {
            $issues[] = 'The backup queue must be Redis (set BACKUP_QUEUE_DRIVER=redis); it is currently the "'.$queueDriver.'" driver, whose jobs would not survive the database being rebuilt.';
        }

        $cacheStore = (string) config('cache.default');
        $cacheDriver = (string) config("cache.stores.{$cacheStore}.driver");
        if (! in_array($cacheDriver, $this->restoreSafeCacheDrivers(), true)) {
            $issues[] = 'The cache must be a shared, non-database store such as Redis (CACHE_STORE); it is currently the "'.$cacheDriver.'" driver, so the restore status and lock would not survive the rebuild or be shared with the worker.';
        }

        return array_merge($issues, $this->maintenanceDurabilityIssues());
    }

    /**
     * The maintenance-mode marker must survive the database reload and be visible
     * to every app process — otherwise `artisan down` would lift mid-restore and
     * let web traffic resume while attachments are still being rebuilt.
     *
     * @return list<string>
     */
    private function maintenanceDurabilityIssues(): array
    {
        $driver = (string) config('app.maintenance.driver', 'file');

        // cache: the marker lives in the named store, which must survive the
        // reload AND be shared across processes — provably cross-process, so it
        // needs no assertion, but NOT the framework-default `database` store.
        if ($driver === 'cache') {
            $store = (string) (config('app.maintenance.store') ?: config('cache.default'));
            $storeDriver = (string) config("cache.stores.{$store}.driver");

            if (! in_array($storeDriver, $this->restoreSafeCacheDrivers(), true)) {
                return ['The maintenance-mode cache store must be a shared, non-database store such as Redis (APP_MAINTENANCE_STORE); it is currently the "'.$storeDriver.'" driver.'];
            }

            return [];
        }

        // file: the marker survives the reload (on-disk), but the web process only
        // sees a marker the worker wrote when they share the storage volume —
        // which the app cannot detect. Accept it only when the operator asserts
        // shared storage (the shipped compose sets this).
        if ($driver === 'file') {
            if (! (bool) config('wayfindr.backup.restore_file_maintenance_shared', false)) {
                return ['Maintenance mode uses the file driver, whose marker is only visible across processes when the web and worker share storage. Set WAYFINDR_RESTORE_FILE_MAINTENANCE_SHARED=true only if they do (the shipped compose does), or switch to a Redis-backed maintenance store.'];
            }

            return [];
        }

        return ['The maintenance-mode driver "'.$driver.'" is not supported for the in-GUI restore; use the file driver on shared storage, or a Redis-backed cache store.'];
    }

    /** @return list<string> */
    private function restoreSafeCacheDrivers(): array
    {
        /** @var list<string> $drivers */
        $drivers = (array) config('wayfindr.backup.restore_safe_cache_drivers', ['redis', 'memcached', 'dynamodb']);

        return $drivers;
    }

    /**
     * The latest restore's status, kept in the cache because a restore wipes the
     * database (and thus any DB-tracked status).
     *
     * @return array<string, mixed>|null
     */
    /**
     * What can be said about a worker on the backups queue.
     *
     * Carries the last-seen time rather than a yes/no, because the guard's check
     * uses a window wide enough to span one legal backup job (an hour). That is
     * the right bound for "did the operator set the worker up" and far too loose
     * for a human reading a page — "seen 55 minutes ago" and "seen 3 seconds
     * ago" both satisfy the check, and only one means the worker is running now.
     *
     * The state comes through unflattened so the page can distinguish "nothing
     * is consuming this queue" from "the cache is down and nothing can be told".
     * Collapsing those would put a confident "no worker" in front of an operator
     * whose worker is fine.
     *
     * @return array{state: string, at: ?CarbonImmutable}
     */
    private function workerObservation(): array
    {
        /** @var mixed $queue */
        $queue = config('queue.connections.backups.queue');

        return app(QueueConsumerHeartbeat::class)->observe(
            'backups',
            is_string($queue) ? $queue : null,
        );
    }

    private function restoreStatus(): ?array
    {
        try {
            $status = Cache::get(RunRestoreJob::STATUS_KEY);
        } catch (Throwable $exception) {
            // A cache outage must not 500 the backup pages — sessions are
            // DB-backed, so the operator can still reach them to read the config
            // and durability guidance; just omit the status banner.
            report($exception);

            return null;
        }

        return is_array($status) ? $status : null;
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
