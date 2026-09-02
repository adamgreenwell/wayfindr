<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\Attachments\AttachmentRetentionRequestCounter;
use App\Support\ReaderNumber;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SQLite3;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Build and measure a disposable attachment-retention fixture (#864).
 *
 * This is intentionally stricter than an ordinary development command. The
 * database must be a temporary SQLite file, local storage must live under the
 * same temporary root, and S3 measurements must use loopback plus a dedicated
 * benchmark bucket and run prefix. Those checks happen before the first row or
 * object is written.
 */
final class MeasureAttachmentRetentionCommand extends Command
{
    protected $signature = 'wayfindr:measure-attachment-retention
        {--objects=10000 : Total synthetic objects to seed}
        {--bytes=1024 : Synthetic bytes per object}
        {--output= : Absolute path for the JSON report}
        {--confirm-disposable : Confirm the database and storage are throwaway}
        {--allow-dirty : Permit a development-only report from a dirty worktree}
        {--preflight-only : Validate the disposable targets without writing anything}
        {--skip-scheduler-probe : Testing only; do not execute the scheduled event}';

    protected $description = 'Measure attachment cleanup against an isolated large-object-count fixture.';

    private const MEASUREMENT_ACCOUNT_SLUG = 'wayfindr-measurement-desk';

    private const CHUNK = 500;

    private const S3_BUCKET = 'wayfindr-attachment-retention';

    private ?FilesystemAdapter $disk = null;

    private ?Account $controlAccount = null;

    private ?string $controlObject = null;

    private string $diskName = '';

    private string $requestPhase = 'setup';

    /** @var array<string, int> */
    private array $categoryCounts = [];

    private int $bytesPerObject = 0;

    private string $fixtureBytes = '';

    private string $fixtureChecksum = '';

    private bool $cleanupAttempted = false;

    private bool $ownsFixture = false;

    public function handle(): int
    {
        try {
            $objects = (int) $this->option('objects');
            $this->bytesPerObject = (int) $this->option('bytes');
            $output = (string) $this->option('output');
            $allowDirty = (bool) $this->option('allow-dirty');

            $this->guardDisposableTarget($objects, $this->bytesPerObject, $output, $allowDirty);

            if ($this->option('preflight-only')) {
                $this->components->info('Attachment-retention disposable-target preflight passed.');

                return self::SUCCESS;
            }

            $gitAtStart = $this->gitState();
            $this->diskName = (string) config('wayfindr.attachments.storage_disk');
            /** @var FilesystemAdapter $disk */
            $disk = Storage::disk($this->diskName);
            $this->disk = $disk;
            if ($this->diskName === 'attachments-s3') {
                AttachmentRetentionRequestCounter::start();
                AttachmentRetentionRequestCounter::attach($disk);
            }
            $this->fixtureBytes = $this->syntheticBytes($this->bytesPerObject);
            $this->fixtureChecksum = hash('sha256', $this->fixtureBytes);

            $desk = $this->measurementDesk();
            $this->assert(
                ConversationMessageAttachment::query()->doesntExist(),
                'The disposable database must not contain pre-existing attachment rows.',
            );
            $this->ownsFixture = true;
            $runId = 'wayfindr-attachment-retention-'.Str::lower((string) Str::ulid());
            $this->createControls($runId);

            $this->setRequestPhase('seed');
            memory_reset_peak_usage();
            $seedStartedAt = hrtime(true);
            $this->categoryCounts = $this->seedFixture($desk, $objects);
            $seed = [
                'wall_ms' => $this->elapsedMs($seedStartedAt),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'rows' => $this->rowCounts($desk['account']->id),
            ];

            $this->setRequestPhase('inventory_before');
            $before = $this->inventory();
            $seed['objects'] = $before;
            $this->assertFixtureBeforeSweep($seed, $objects);

            $rowsBeforeDryRun = $seed['rows'];
            $objectsBeforeDryRun = $before;
            $dryRun = $this->runSweep(true);

            $this->setRequestPhase('verify_dry_run');
            $this->assertSame(
                $rowsBeforeDryRun,
                $this->rowCounts($desk['account']->id),
                'Dry run changed attachment rows.',
            );
            $this->assertSame($objectsBeforeDryRun, $this->inventory(), 'Dry run changed stored objects.');

            $sweep = $this->runSweep(false);

            $this->setRequestPhase('verify_sweep');
            $after = [
                'rows' => $this->rowCounts($desk['account']->id),
                'objects' => $this->inventory(),
            ];
            $verification = $this->verifyRetentionResult($desk, $after);
            $verification['unrelated_account_survived_sweep'] = $this->controlAccountStillMatches();
            $verification['outside_prefix_object_survived_sweep'] = $this->controlObjectExists();
            $this->assertAll($verification);

            $scheduler = $this->option('skip-scheduler-probe')
                ? $this->skippedSchedulerProbe()
                : $this->runSchedulerProbe($desk);

            $cleanup = $this->cleanup();
            $s3RequestAttempts = AttachmentRetentionRequestCounter::counts();
            AttachmentRetentionRequestCounter::stop();
            $gitAtEnd = $this->gitState();

            $this->assert(
                $allowDirty || ($gitAtStart['clean'] && $gitAtEnd['clean']),
                'Refusing to publish: the Git worktree was dirty before or after the measurement.',
            );
            $this->assert(
                $gitAtStart['revision'] === $gitAtEnd['revision'],
                'Refusing to publish: HEAD changed during the measurement.',
            );

            $report = [
                'schema_version' => 1,
                'measured_at' => now()->toIso8601String(),
                'revision' => $gitAtStart['revision'],
                'working_tree_clean_at_start' => $gitAtStart['clean'],
                'working_tree_clean_at_end' => $gitAtEnd['clean'],
                'environment' => $this->environmentMetadata(),
                'settings' => [
                    'pending_expiry_hours' => (int) config('wayfindr.attachments.pending_expiry_hours'),
                    'orphan_grace_hours' => (int) config('wayfindr.attachments.orphan_grace_hours'),
                ],
                'workload' => [
                    'synthetic_non_sensitive_bytes' => true,
                    'total_objects' => $objects,
                    'bytes_per_object' => $this->bytesPerObject,
                    'total_bytes' => $objects * $this->bytesPerObject,
                    'categories' => $this->categoryCounts,
                ],
                'seed' => $seed,
                'dry_run' => $dryRun,
                'sweep' => $sweep,
                'after' => $after,
                'verification' => $verification,
                'scheduler_probe' => $scheduler,
                'cleanup' => $cleanup,
                's3_request_attempts' => $s3RequestAttempts ?: null,
                'contains_credentials_or_attachment_contents' => false,
            ];

            $this->writeReport($output, $report);
            $this->components->info(sprintf(
                'Measured %s with %s synthetic objects. Report: %s',
                $this->diskName,
                ReaderNumber::count($objects),
                $output,
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            AttachmentRetentionRequestCounter::stop();

            if ($this->ownsFixture && ! $this->cleanupAttempted) {
                try {
                    $this->cleanup();
                } catch (Throwable) {
                    // Keep the original failure. The isolated wrapper removes
                    // its temporary SQLite/storage root and MinIO container too.
                }
            }

            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function guardDisposableTarget(int $objects, int $bytes, string $output, bool $allowDirty): void
    {
        $this->assert(
            getenv('WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE') === 'YES' && $this->option('confirm-disposable'),
            'Set WAYFINDR_ATTACHMENT_RETENTION_DISPOSABLE=YES and pass --confirm-disposable only for an isolated throwaway run.',
        );
        $this->assert(! app()->isProduction(), 'Attachment-retention measurement is refused in production.');
        $this->assert(! app()->configurationIsCached(), 'Attachment-retention measurement refuses cached configuration.');
        $this->assert($objects >= 10 && $objects <= 50_000 && $objects % 10 === 0, '--objects must be a multiple of 10 from 10 through 50,000.');
        $this->assert($bytes >= 1 && $bytes <= 1_048_576, '--bytes must be between 1 byte and 1 MiB.');
        $this->assert($objects * $bytes <= 268_435_456, 'The synthetic fixture is capped at 256 MiB.');
        $this->assert($output !== '' && str_starts_with($output, DIRECTORY_SEPARATOR), '--output must be an absolute path.');
        $this->assert(! file_exists($output) && ! file_exists($output.'.tmp'), 'Refusing to overwrite an existing report or temporary report.');
        $this->assert(
            ! str_starts_with($this->normalPath($output), $this->normalPath((string) realpath(base_path('../..'))).DIRECTORY_SEPARATOR),
            'Write the measurement report outside the repository so evidence runs remain clean.',
        );
        $this->assert(DB::connection()->getDriverName() === 'sqlite', 'Measurement requires an isolated SQLite database.');

        if (! app()->environment('testing')) {
            $database = (string) config('database.connections.sqlite.database');
            $databaseDirectory = realpath(dirname($database));
            $storagePath = realpath(storage_path());
            $temporaryRoot = realpath(sys_get_temp_dir());

            $this->assert($databaseDirectory !== false && $storagePath !== false && $temporaryRoot !== false, 'Temporary database/storage paths must exist.');
            $isolatedRoot = $this->normalPath((string) $databaseDirectory);
            $this->assert(str_starts_with(basename($isolatedRoot), 'wayfindr-attachment-retention-'), 'SQLite must live in a wayfindr-attachment-retention-* temporary directory.');
            $this->assert(str_starts_with($isolatedRoot, $this->normalPath((string) $temporaryRoot).DIRECTORY_SEPARATOR), 'SQLite must live under the operating-system temporary directory.');
            $this->assert(str_starts_with($this->normalPath((string) $storagePath), $isolatedRoot.DIRECTORY_SEPARATOR), 'LARAVEL_STORAGE_PATH must live beside the disposable SQLite database.');
        }

        $diskName = (string) config('wayfindr.attachments.storage_disk');
        $this->assert(in_array($diskName, ['attachments', 'attachments-s3'], true), 'Measurement only supports the product attachment disks: attachments or attachments-s3.');
        $this->assert(trim((string) getenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY')) !== '', 'Describe the disposable storage topology with WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY.');

        $configuredAttachmentDisks = collect(config('filesystems.disks', []))
            ->filter(fn ($disk, string $name): bool => str_starts_with($name, 'attachments'))
            ->filter(function ($disk): bool {
                $driver = is_array($disk) ? ($disk['driver'] ?? 'local') : 'local';

                return $driver !== 's3' || filled(is_array($disk) ? ($disk['bucket'] ?? null) : null);
            })
            ->keys()
            ->all();
        $allowedAttachmentDisks = $diskName === 'attachments-s3'
            ? ['attachments', 'attachments-s3']
            : ['attachments'];
        $unexpectedAttachmentDisks = array_values(array_diff($configuredAttachmentDisks, $allowedAttachmentDisks));
        $this->assert(
            $unexpectedAttachmentDisks === [],
            'Measurement refuses additional configured attachment disks: '.implode(', ', $unexpectedAttachmentDisks).'.',
        );

        $localDisk = config('filesystems.disks.attachments');
        $this->assert(
            is_array($localDisk) && ($localDisk['driver'] ?? null) === 'local',
            'The attachments disk must be the isolated local driver during measurement.',
        );

        if (! app()->environment('testing')) {
            $databaseDirectory = $this->normalPath((string) realpath(dirname((string) config('database.connections.sqlite.database'))));
            $configuredRoot = (string) ($localDisk['root'] ?? '');
            $localRoot = $this->normalPath((string) (realpath($configuredRoot) ?: $configuredRoot));
            $this->assert(str_starts_with($localRoot, $databaseDirectory.DIRECTORY_SEPARATOR), 'Local attachments must live under the same isolated temporary root as SQLite.');
        }

        if ($diskName === 'attachments-s3') {
            $endpoint = parse_url((string) config('filesystems.disks.attachments-s3.endpoint'));
            $host = strtolower((string) ($endpoint['host'] ?? ''));
            $bucket = (string) config('filesystems.disks.attachments-s3.bucket');
            $root = trim((string) config('filesystems.disks.attachments-s3.root'), '/');

            $this->assert(in_array($host, ['127.0.0.1', '::1', 'localhost'], true), 'S3-compatible measurement requires a loopback endpoint.');
            $this->assert(
                in_array($endpoint['scheme'] ?? '', ['http', 'https'], true)
                && ! isset($endpoint['user'])
                && ! isset($endpoint['pass']),
                'S3-compatible measurement requires an HTTP(S) endpoint without embedded credentials.',
            );
            $this->assert(
                config('filesystems.disks.attachments-s3.key') === 'wayfindr-retention'
                && config('filesystems.disks.attachments-s3.secret') === 'wayfindr-retention-secret',
                'S3-compatible measurement requires the published synthetic MinIO credentials, never operator credentials.',
            );
            $this->assert($bucket === self::S3_BUCKET, 'S3-compatible measurement requires the dedicated wayfindr-attachment-retention bucket.');
            $this->assert(str_starts_with($root, 'runs/wayfindr-attachment-retention-') && ! str_contains($root, '..'), 'S3-compatible measurement requires a unique runs/wayfindr-attachment-retention-* root.');
            $this->assert((int) config('wayfindr.attachments.orphan_grace_hours') === 0, 'The disposable S3 run requires orphan_grace_hours=0 because S3 object timestamps cannot be backdated.');
        } else {
            $this->assert((int) config('wayfindr.attachments.orphan_grace_hours') >= 1, 'The local run requires at least a one-hour orphan grace window.');
        }

        $this->assert(
            ! $this->option('skip-scheduler-probe') || app()->environment('testing'),
            '--skip-scheduler-probe is available only to the automated test suite.',
        );

        $git = $this->gitState();
        $this->assert($allowDirty || $git['clean'], 'Measurement refuses a dirty worktree; commit or stash changes first.');
    }

    /**
     * @return array{account: Account, site: Site, visitor: Visitor, conversation: Conversation, message: ConversationMessage}
     */
    private function measurementDesk(): array
    {
        $account = Account::query()
            ->where('slug', self::MEASUREMENT_ACCOUNT_SLUG)
            ->where('name', 'Measurement Desk')
            ->first();
        $this->assert($account !== null, 'Seed the disposable Measurement Desk before running attachment retention.');

        $site = Site::query()->where('account_id', $account->id)->where('public_key', 'like', 'site_desk_%')->first();
        $visitor = $site ? Visitor::query()->where('site_id', $site->id)->first() : null;
        $conversation = $visitor ? Conversation::query()->where('visitor_id', $visitor->id)->first() : null;
        $message = $conversation ? ConversationMessage::query()->where('conversation_id', $conversation->id)->first() : null;
        $this->assert($site && $visitor && $conversation && $message, 'The Measurement Desk needs one site, visitor, conversation, and message.');

        return compact('account', 'site', 'visitor', 'conversation', 'message');
    }

    /**
     * @param  array{account: Account, site: Site, visitor: Visitor, conversation: Conversation, message: ConversationMessage}  $desk
     * @return array<string, int>
     */
    private function seedFixture(array $desk, int $objects): array
    {
        $tenth = intdiv($objects, 10);
        $categories = [
            'bound' => $tenth * 4,
            'fresh-pending' => $tenth,
            'expired-pending' => $tenth * 2,
            'failed' => $tenth,
        ];

        if ($this->diskName === 'attachments') {
            $categories['old-orphan'] = (int) (($tenth * 2) * 0.75);
            $categories['recent-orphan'] = ($tenth * 2) - $categories['old-orphan'];
        } else {
            $categories['orphan'] = $tenth * 2;
        }

        foreach ($categories as $category => $count) {
            $rows = [];

            for ($index = 0; $index < $count; $index++) {
                $key = sprintf('%s/%08d.txt', $category, $index);
                $this->assert($this->disk?->put($key, $this->fixtureBytes) === true, "Could not write synthetic object [{$key}].");

                if ($category === 'old-orphan') {
                    $path = $this->disk?->path($key);
                    $this->assert($path && touch($path, now()->subHours(2)->getTimestamp()), "Could not backdate [{$key}].");
                }

                if (str_contains($category, 'orphan')) {
                    continue;
                }

                $createdAt = $category === 'expired-pending' ? now()->subHours(48) : now();
                $rows[] = [
                    'conversation_message_id' => $category === 'bound' ? $desk['message']->id : null,
                    'conversation_id' => $desk['conversation']->id,
                    'account_id' => $desk['account']->id,
                    'site_id' => $desk['site']->id,
                    'uploaded_by_type' => $desk['visitor']->getMorphClass(),
                    'uploaded_by_id' => $desk['visitor']->id,
                    'storage_disk' => $this->diskName,
                    'storage_key' => $key,
                    'original_filename' => 'synthetic-retention-fixture.txt',
                    'mime_type' => 'text/plain',
                    'size_bytes' => $this->bytesPerObject,
                    'checksum' => $this->fixtureChecksum,
                    'status' => $category === 'failed'
                        ? ConversationMessageAttachment::STATUS_FAILED
                        : ConversationMessageAttachment::STATUS_READY,
                    'scan_status' => null,
                    'scanned_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if (count($rows) === self::CHUNK) {
                    DB::table('conversation_message_attachments')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('conversation_message_attachments')->insert($rows);
            }
        }

        return $categories;
    }

    /** @return array<string, int> */
    private function rowCounts(int $accountId): array
    {
        $counts = [];

        foreach (['bound', 'fresh-pending', 'expired-pending', 'failed', 'scheduler-expired'] as $category) {
            $counts[$category] = ConversationMessageAttachment::query()
                ->where('account_id', $accountId)
                ->where('storage_disk', $this->diskName)
                ->where('storage_key', 'like', $category.'/%')
                ->count();
        }

        return $counts;
    }

    /** @return array{count: int, bytes: int, categories: array<string, int>} */
    private function inventory(): array
    {
        $count = 0;
        $bytes = 0;
        $categories = [];

        foreach ($this->disk?->getDriver()->listContents('', true) ?? [] as $attributes) {
            if (! $attributes->isFile() || str_starts_with(basename($attributes->path()), '.')) {
                continue;
            }

            $count++;
            $bytes += (int) ($attributes->fileSize() ?? 0);
            $category = explode('/', $attributes->path(), 2)[0];
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        ksort($categories);

        return compact('count', 'bytes', 'categories');
    }

    /** @param array<string, mixed> $seed */
    private function assertFixtureBeforeSweep(array $seed, int $objects): void
    {
        $this->assert($seed['objects']['count'] === $objects, 'Seeded object count does not match the requested fixture.');
        $this->assert($seed['objects']['bytes'] === $objects * $this->bytesPerObject, 'Seeded object bytes do not match the requested fixture.');
        $expectedRows = $this->categoryCounts['bound']
            + $this->categoryCounts['fresh-pending']
            + $this->categoryCounts['expired-pending']
            + $this->categoryCounts['failed'];
        $this->assert(array_sum($seed['rows']) === $expectedRows, 'Seeded attachment row count is incomplete.');
    }

    /** @return array<string, mixed> */
    private function runSweep(bool $dryRun): array
    {
        $this->setRequestPhase($dryRun ? 'dry_run' : 'sweep');
        memory_reset_peak_usage();
        $memoryAtStart = memory_get_usage(true);
        $startedAt = hrtime(true);
        $output = new BufferedOutput;
        $exitCode = Artisan::call(
            'wayfindr:sweep-orphaned-attachments',
            $dryRun ? ['--dry-run' => true] : [],
            $output,
        );
        $wallMs = $this->elapsedMs($startedAt);
        $this->assert($exitCode === self::SUCCESS, 'Attachment sweep command failed.');

        return [
            'command' => 'php artisan wayfindr:sweep-orphaned-attachments'.($dryRun ? ' --dry-run' : ''),
            'exit_code' => $exitCode,
            'wall_ms' => $wallMs,
            'memory_at_start_bytes' => $memoryAtStart,
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'reported_output' => trim($output->fetch()) ?: null,
            'expected_eligible_rows' => $this->categoryCounts['expired-pending'] + $this->categoryCounts['failed'],
            'expected_eligible_orphan_objects' => $this->diskName === 'attachments'
                ? $this->categoryCounts['old-orphan']
                : $this->categoryCounts['orphan'],
        ];
    }

    /**
     * @param  array{account: Account, site: Site, visitor: Visitor, conversation: Conversation, message: ConversationMessage}  $desk
     * @param  array{rows: array<string, int>, objects: array<string, mixed>}  $after
     * @return array<string, bool>
     */
    private function verifyRetentionResult(array $desk, array $after): array
    {
        $expectedObjectCategories = [
            'bound' => $this->categoryCounts['bound'],
            'fresh-pending' => $this->categoryCounts['fresh-pending'],
        ];

        if ($this->diskName === 'attachments') {
            $expectedObjectCategories['recent-orphan'] = $this->categoryCounts['recent-orphan'];
        }

        ksort($expectedObjectCategories);
        $expectedObjects = array_sum($expectedObjectCategories);
        $bound = ConversationMessageAttachment::query()
            ->where('account_id', $desk['account']->id)
            ->where('storage_key', 'bound/00000000.txt')
            ->first();
        $fresh = ConversationMessageAttachment::query()
            ->where('account_id', $desk['account']->id)
            ->where('storage_key', 'fresh-pending/00000000.txt')
            ->first();
        $metadataMismatch = ConversationMessageAttachment::query()
            ->where('account_id', $desk['account']->id)
            ->where('storage_disk', $this->diskName)
            ->where(function ($query): void {
                $query
                    ->where('status', '!=', ConversationMessageAttachment::STATUS_READY)
                    ->orWhere('size_bytes', '!=', $this->bytesPerObject)
                    ->orWhere('checksum', '!=', $this->fixtureChecksum);
            })
            ->exists();

        $verification = [
            'expired_pending_rows_removed' => $after['rows']['expired-pending'] === 0,
            'failed_rows_removed' => $after['rows']['failed'] === 0,
            'bound_rows_preserved' => $after['rows']['bound'] === $this->categoryCounts['bound'],
            'unexpired_pending_rows_preserved' => $after['rows']['fresh-pending'] === $this->categoryCounts['fresh-pending'],
            'eligible_orphan_objects_removed' => $this->eligibleOrphanCount($after['objects']['categories']) === 0,
            'remaining_object_count_exact' => $after['objects']['count'] === $expectedObjects,
            'remaining_object_bytes_exact' => $after['objects']['bytes'] === $expectedObjects * $this->bytesPerObject,
            'remaining_object_categories_exact' => $after['objects']['categories'] === $expectedObjectCategories,
            'remaining_metadata_consistent' => ! $metadataMismatch,
            'bound_attachment_downloadable' => $bound?->isDownloadableBy($desk['visitor']) === true
                && $this->disk?->get($bound->storage_key) === $this->fixtureBytes,
            'unexpired_pending_attachment_downloadable' => $fresh?->isDownloadableBy($desk['visitor']) === true
                && $this->disk?->get($fresh->storage_key) === $this->fixtureBytes,
        ];

        if ($this->diskName === 'attachments') {
            $verification['recent_orphan_objects_preserved']
                = ($after['objects']['categories']['recent-orphan'] ?? 0) === $this->categoryCounts['recent-orphan'];
        }

        return $verification;
    }

    /** @param array<string, int> $categories */
    private function eligibleOrphanCount(array $categories): int
    {
        return $this->diskName === 'attachments'
            ? (int) ($categories['old-orphan'] ?? 0)
            : (int) ($categories['orphan'] ?? 0);
    }

    /**
     * @param  array{account: Account, site: Site, visitor: Visitor, conversation: Conversation, message: ConversationMessage}  $desk
     * @return array<string, mixed>
     */
    private function runSchedulerProbe(array $desk): array
    {
        $key = 'scheduler-expired/probe.txt';
        $this->setRequestPhase('scheduler_seed');
        $this->assert($this->disk?->put($key, $this->fixtureBytes) === true, 'Could not seed the scheduler probe object.');
        $createdAt = now()->subHours(48);
        $id = DB::table('conversation_message_attachments')->insertGetId([
            'conversation_message_id' => null,
            'conversation_id' => $desk['conversation']->id,
            'account_id' => $desk['account']->id,
            'site_id' => $desk['site']->id,
            'uploaded_by_type' => $desk['visitor']->getMorphClass(),
            'uploaded_by_id' => $desk['visitor']->id,
            'storage_disk' => $this->diskName,
            'storage_key' => $key,
            'original_filename' => 'scheduler-probe.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => $this->bytesPerObject,
            'checksum' => $this->fixtureChecksum,
            'status' => ConversationMessageAttachment::STATUS_READY,
            'scan_status' => null,
            'scanned_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $event = collect(Schedule::events())->first(
            fn (Event $candidate): bool => str_contains((string) $candidate->command, 'wayfindr:sweep-orphaned-attachments'),
        );
        $this->assert($event instanceof Event, 'The hourly attachment sweep is missing from the scheduler.');

        $succeeded = false;
        $failed = false;
        $event->onSuccess(function () use (&$succeeded): void {
            $succeeded = true;
        });
        $event->onFailure(function () use (&$failed): void {
            $failed = true;
        });

        $startedAt = hrtime(true);
        $event->run(app());
        $wallMs = $this->elapsedMs($startedAt);
        $rowRemoved = ! ConversationMessageAttachment::query()->whereKey($id)->exists();
        $objectRemoved = ! $this->disk?->exists($key);
        $this->assert($succeeded && ! $failed && $rowRemoved && $objectRemoved, 'The scheduled attachment sweep probe did not remove its expired upload.');

        return [
            'exercised' => true,
            'expression' => $event->getExpression(),
            'command' => 'php artisan wayfindr:sweep-orphaned-attachments',
            'wall_ms' => $wallMs,
            'row_removed' => $rowRemoved,
            'object_removed' => $objectRemoved,
            's3_request_attempts' => null,
            'request_count_note' => $this->diskName === 'attachments-s3'
                ? 'The scheduler executes in its own PHP process, outside the in-process AWS request counter.'
                : 'Not applicable to local filesystem storage.',
        ];
    }

    /** @return array<string, mixed> */
    private function skippedSchedulerProbe(): array
    {
        return [
            'exercised' => false,
            'testing_only_skip' => true,
        ];
    }

    private function createControls(string $runId): void
    {
        $this->controlAccount = Account::query()->create([
            'name' => 'Attachment Retention Control',
            'slug' => $runId.'-control',
        ]);

        if ($this->diskName === 'attachments-s3') {
            $client = $this->disk?->getClient();
            $bucket = (string) config('filesystems.disks.attachments-s3.bucket');

            if (! $client->doesBucketExistV2($bucket)) {
                $client->createBucket(['Bucket' => $bucket]);
            }

            $this->controlObject = 'controls/'.$runId.'.txt';
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $this->controlObject,
                'Body' => $this->fixtureBytes,
                'ACL' => 'private',
            ]);

            return;
        }

        $root = (string) config('filesystems.disks.attachments.root');
        $this->controlObject = dirname($root).DIRECTORY_SEPARATOR.'.'.$runId.'-control.txt';
        $this->assert(file_put_contents($this->controlObject, $this->fixtureBytes) !== false, 'Could not write the outside-root local control object.');
    }

    private function controlAccountStillMatches(): bool
    {
        if (! $this->controlAccount) {
            return false;
        }

        return Account::query()
            ->whereKey($this->controlAccount->id)
            ->where('name', 'Attachment Retention Control')
            ->where('slug', $this->controlAccount->slug)
            ->where('updated_at', $this->controlAccount->updated_at)
            ->exists();
    }

    private function controlObjectExists(bool $verifyContents = true): bool
    {
        if (! $this->controlObject) {
            return false;
        }

        if ($this->diskName === 'attachments-s3') {
            $client = $this->disk?->getClient();
            $bucket = (string) config('filesystems.disks.attachments-s3.bucket');
            $exists = $client?->doesObjectExistV2($bucket, $this->controlObject) === true;

            if (! $exists || ! $verifyContents) {
                return $exists;
            }

            $result = $client->getObject(['Bucket' => $bucket, 'Key' => $this->controlObject]);

            return hash('sha256', (string) $result['Body']) === $this->fixtureChecksum;
        }

        return is_file($this->controlObject)
            && hash_file('sha256', $this->controlObject) === $this->fixtureChecksum;
    }

    /** @return array<string, bool|int> */
    private function cleanup(): array
    {
        $this->cleanupAttempted = true;
        $this->setRequestPhase('cleanup');

        if ($this->disk) {
            $this->disk->deleteDirectory('');
        }

        Artisan::call('wayfindr:seed-desk', ['--purge' => true]);

        if ($this->controlAccount) {
            $this->controlAccount->delete();
        }

        if ($this->controlObject) {
            if ($this->diskName === 'attachments-s3' && $this->disk) {
                $this->disk->getClient()->deleteObject([
                    'Bucket' => (string) config('filesystems.disks.attachments-s3.bucket'),
                    'Key' => $this->controlObject,
                ]);
            } elseif (is_file($this->controlObject)) {
                unlink($this->controlObject);
            }
        }

        $measurementAccountRemoved = ! Account::query()->where('slug', self::MEASUREMENT_ACCOUNT_SLUG)->exists();
        $controlAccountRemoved = ! $this->controlAccount || ! Account::query()->whereKey($this->controlAccount->id)->exists();
        $measurementPrefixEmpty = ! $this->disk || $this->inventory()['count'] === 0;
        $controlObjectRemoved = ! $this->controlObjectExists(false);
        $result = compact('measurementAccountRemoved', 'controlAccountRemoved', 'measurementPrefixEmpty', 'controlObjectRemoved');
        $this->assertAll($result);

        return [
            'measurement_account_removed' => $measurementAccountRemoved,
            'control_account_removed' => $controlAccountRemoved,
            'measurement_prefix_empty' => $measurementPrefixEmpty,
            'synthetic_outside_prefix_control_removed' => $controlObjectRemoved,
        ];
    }

    private function setRequestPhase(string $phase): void
    {
        $this->requestPhase = $phase;
        AttachmentRetentionRequestCounter::phase($phase);
    }

    /** @return array<string, mixed> */
    private function environmentMetadata(): array
    {
        $metadata = [
            'machine' => php_uname('m'),
            'os' => php_uname('s').' '.php_uname('r'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => 'SQLite '.SQLite3::version()['versionString'],
            'storage_disk' => $this->diskName,
            'storage_driver' => (string) config("filesystems.disks.{$this->diskName}.driver"),
            'storage_topology' => (string) getenv('WAYFINDR_ATTACHMENT_RETENTION_STORAGE_TOPOLOGY'),
        ];

        if ($this->diskName === 'attachments-s3') {
            $endpoint = parse_url((string) config('filesystems.disks.attachments-s3.endpoint'));
            $metadata['s3_endpoint'] = sprintf('%s://%s:%s', $endpoint['scheme'] ?? 'http', $endpoint['host'] ?? 'localhost', $endpoint['port'] ?? 80);
            $metadata['s3_bucket'] = (string) config('filesystems.disks.attachments-s3.bucket');
            $metadata['s3_root'] = (string) config('filesystems.disks.attachments-s3.root');
        } else {
            $metadata['local_root'] = (string) config('filesystems.disks.attachments.root');
        }

        return $metadata;
    }

    /** @return array{revision: string, clean: bool} */
    private function gitState(): array
    {
        $root = (string) realpath(base_path('../..'));
        $revision = new Process(['git', '-C', $root, 'rev-parse', 'HEAD']);
        $revision->mustRun();
        $status = new Process(['git', '-C', $root, 'status', '--porcelain', '--untracked-files=normal']);
        $status->mustRun();

        return [
            'revision' => trim($revision->getOutput()),
            'clean' => trim($status->getOutput()) === '',
        ];
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $output, array $report): void
    {
        $directory = dirname($output);
        $this->assert(is_dir($directory) || mkdir($directory, 0755, true), 'Could not create the report directory.');
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $temporary = $output.'.tmp';
        $this->assert(file_put_contents($temporary, $json) !== false, 'Could not write the temporary report.');
        $this->assert(rename($temporary, $output), 'Could not publish the report atomically.');
    }

    private function syntheticBytes(int $bytes): string
    {
        $seed = 'WAYFINDR SYNTHETIC ATTACHMENT RETENTION FIXTURE'.PHP_EOL;

        return substr(str_repeat($seed, (int) ceil($bytes / strlen($seed))), 0, $bytes);
    }

    private function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 1);
    }

    private function normalPath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /** @param array<string, bool> $verification */
    private function assertAll(array $verification): void
    {
        foreach ($verification as $name => $passed) {
            $this->assert($passed, 'Verification failed: '.$name.'.');
        }
    }

    private function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        $this->assert($expected === $actual, $message);
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
