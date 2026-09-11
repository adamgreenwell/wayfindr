<?php

namespace App\Console\Commands;

use App\Support\Backup\PartialRestoreException;
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

        if ($preflight['version_indeterminate'] ?? false) {
            // Which side is unidentified changes the remedy — an unidentified
            // ARCHIVE may be from newer code, where migrations cannot help.
            $detail = match (true) {
                ! ($preflight['archive_version_known'] ?? false) && ($preflight['running_version_known'] ?? false) => 'the ARCHIVE carries no release identity; if it came from a newer release, migrations here cannot bring the schema forward. Identify the archive (or deploy a matching release) before serving traffic.',
                ($preflight['archive_version_known'] ?? false) && ! ($preflight['running_version_known'] ?? false) => 'THIS INSTALL carries no release identity. Confirm it runs code compatible with the archive, and set WAYFINDR_VERSION so this can be checked automatically.',
                default => 'neither side carries a release identity. Confirm the schema is current before serving traffic, and set WAYFINDR_VERSION so this can be checked automatically.',
            };

            $this->warn(sprintf(
                'Versions could NOT be verified (archive: %s, this install: %s) — %s',
                $preflight['archive_version'],
                $preflight['running_version'],
                $detail,
            ));
        } elseif ($preflight['version_skew']) {
            $this->warn(sprintf(
                'Version skew: the archive was taken on %s but this install runs %s. '
                .'Run migrations after restoring if the schema has moved on.',
                $preflight['archive_version'],
                $preflight['running_version'],
            ));
        }

        // Separate from version skew, and a different kind of harm: a version
        // mismatch leaves a schema to migrate, while a key mismatch leaves
        // columns that load and then throw. Warn about it in its own sentence
        // rather than folding it into the version block.
        if (($preflight['app_key_skew'] ?? false) && ! ($preflight['app_key_no_overlap'] ?? false)) {
            // Partial: the two key sets intersect, so rows written under the
            // shared key still decrypt. Warning here in the total-loss language
            // below would push an operator to abandon a restore that loses
            // nothing, or to clear columns that are perfectly readable.
            $this->warn(
                'This archive was taken with more than one APP_KEY and this install is missing one of '
                .'them. Values written under the missing key will be unreadable afterwards; values '
                .'written under the key you do have will read normally. Add the missing key to '
                .'APP_PREVIOUS_KEYS before restoring and nothing is lost. If you restore without it, '
                .'do NOT clear the encrypted columns — you would destroy the values that still read.'
            );
        } elseif ($preflight['app_key_skew'] ?? false) {
            // Deliberately NOT an inventory of affected columns. An earlier
            // version listed the integration secrets and read as exhaustive
            // while omitting the one that matters most: two_factor_secret is
            // encrypted and hasTwoFactorAuthentication() reads it during SIGN
            // IN, so every agent with 2FA is locked out before anyone can
            // re-enter anything. Lead with that, describe the rest as a class.
            $this->warn(
                'This install shares no APP_KEY with the key set this archive was taken with. EVERY encrypted '
                .'value in the archive becomes unreadable, and the first casualty is sign-in itself: '
                .'agents with two-factor authentication cannot authenticate, because their secret is '
                .'encrypted and is read while they log in. Operator-managed credentials, single '
                .'sign-on secrets, webhook URLs and secrets, and external-issue credentials go with '
                .'it. Restore the original APP_KEY (and any APP_PREVIOUS_KEYS) into this install '
                .'before restoring. If they are gone, follow "If the keys are genuinely gone" in '
                .'docs/self-hosting/backup-restore.md rather than improvising: five of the nine '
                .'encrypted columns are NOT NULL, so those rows are deleted rather than cleared, and '
                .'an empty string does not work because the cast still tries to decrypt it.'
            );
        } elseif ($preflight['app_key_indeterminate'] ?? false) {
            $this->warn(
                'APP_KEY could NOT be verified against this archive — it predates the fingerprint, or '
                .'this install has no key set. If the key differs from the one the archive was taken '
                .'with, encrypted columns will not be readable afterwards.'
            );
        }

        try {
            $result = $restores->restore($archive, (bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->error('Restore failed: '.$exception->getMessage());

            // Only when destructive work actually began. RestoreService throws
            // from its existing-data guard BEFORE touching anything -- the
            // ordinary "refusing without --force" case -- and following that
            // refusal with "this may have applied partially" contradicts the
            // line above it and sends an operator to check a database nothing
            // touched.
            if ($exception instanceof PartialRestoreException) {
                $this->warn(RestoreService::PARTIAL_FAILURE_ADVICE);
            }

            return self::FAILURE;
        }

        $this->line('Database restored.');

        // Only when a single version was actually ESTABLISHED — i.e. both sides
        // were known and agreed. Printing it for an indeterminate pair would
        // contradict the "could not be verified" warning just above, presenting
        // 'unknown' as if it were a confirmed common version.
        if (! $result['version_skew'] && ! ($result['version_indeterminate'] ?? false)) {
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
