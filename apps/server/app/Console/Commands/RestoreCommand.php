<?php

namespace App\Console\Commands;

use App\Support\Backup\RestoreService;
use Illuminate\Console\Command;
use Throwable;

class RestoreCommand extends Command
{
    protected $signature = 'wayfindr:restore
        {archive : Path to a wayfindr:backup archive (.tar.gz)}
        {--force : Overwrite a database that already contains data}';

    protected $description = 'Restore a Wayfindr backup archive (Postgres dump + local attachment binaries).';

    public function handle(RestoreService $restores): int
    {
        $archive = (string) $this->argument('archive');

        $this->info('Restoring Wayfindr from '.$archive);

        // Preflight: surface a version mismatch BEFORE anything destructive, so
        // the operator sees it while they can still abort.
        try {
            $preflight = $restores->preflight($archive);
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($preflight['version_skew']) {
            $this->warn(sprintf(
                'Version skew: the archive was taken on %s but this install runs %s. '
                .'Run migrations after restoring if the schema has moved on.',
                $preflight['archive_version'],
                $preflight['running_version'],
            ));
        }

        try {
            $result = $restores->restore($archive, (bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Database restored.');

        if (! $result['version_skew']) {
            $this->line('  Wayfindr version: '.$result['archive_version']);
        }

        if ($result['restored_disks'] !== []) {
            $this->line('  Local attachment binaries restored to: '.implode(', ', $result['restored_disks']));
        }

        if ($result['unconfigured_disks'] !== []) {
            $this->warn(
                '  The archive carried binaries for disks not configured here (['
                .implode(', ', $result['unconfigured_disks'])
                .']); those attachments could not be placed. Configure the disk(s) and restore again.'
            );
        }

        $integrity = $result['integrity'];

        if ($integrity['skipped']) {
            $this->warn('  Attachment integrity check skipped: the restored schema predates the current attachments table. Run migrations, then re-check attachments.');

            $this->info('Restore complete.');

            return self::SUCCESS;
        }

        $this->line('  Attachments verified present: '.$integrity['verified']);

        if ($integrity['external'] !== []) {
            $pairs = collect($integrity['external'])
                ->map(fn (int $count, string $disk): string => "{$disk} ({$count})")
                ->implode(', ');

            $this->warn('  Attachments served from external object stores, not this archive: '.$pairs.'. Keep those buckets reachable.');
        }

        if ($integrity['dangling'] !== []) {
            $count = count($integrity['dangling']);
            $this->warn("  {$count} attachment(s) have NO binary in the archive (dangling — the row exists but its file is gone):");

            foreach (array_slice($integrity['dangling'], 0, 10) as $row) {
                $this->warn(sprintf('    - #%d on %s (%s)', $row['id'], $row['disk'], $row['key']));
            }

            if ($count > 10) {
                $this->warn('    ... and '.($count - 10).' more.');
            }
        }

        $this->info('Restore complete.');

        return self::SUCCESS;
    }
}
