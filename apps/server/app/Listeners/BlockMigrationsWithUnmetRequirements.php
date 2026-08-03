<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\UpgradeGuard;
use Illuminate\Console\Events\CommandStarting;

/**
 * Refuses to migrate while this release has unmet operator requirements
 * (ADR 0013).
 *
 * Auto-discovered — the framework scans app/Listeners — so there is no
 * registration to forget.
 *
 * WHY `CommandStarting` and not a migration event. Three other hooks were tried
 * against the real console kernel and each let something through:
 *
 *   - `MigrationsStarted` is dispatched inside `Migrator::runPending()`, which
 *     returns early when nothing is pending. A release whose action depends on
 *     its CODE ships no migration at all, so the listener never fires and the
 *     command exits 0 — the silent progression this exists to prevent.
 *   - A `migrator` extension fires after `prepareDatabase()`, and `--graceful`
 *     turns its exception into a warning with exit 0.
 *   - Overriding `MigrateCommand` misses `migrate:fresh`, which calls
 *     `$this->call('migrate')` and discards the result: the guard refuses and
 *     the command still exits 0, after `db:wipe` has already dropped everything.
 *
 * Firing before the command runs is also the only point that precedes
 * `db:wipe`, so `migrate:fresh` is stopped with the schema intact.
 */
class BlockMigrationsWithUnmetRequirements
{
    /**
     * Commands that reach the schema. Deliberately explicit: this is a small,
     * closed, framework-owned set rather than the thirteen shell and
     * documentation call sites the guard replaces, and no documented command
     * routes around it.
     *
     * `migrate:rollback` and `migrate:reset` are NOT here on purpose. They are
     * how an operator retreats from a bad upgrade, and blocking them would take
     * away the recovery this guard tells them to use.
     */
    private const GUARDED = ['migrate', 'migrate:fresh', 'migrate:refresh'];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::GUARDED, true)) {
            return;
        }

        $assessment = app(UpgradeGuard::class)->assess();

        if (! $assessment['blocked']) {
            return;
        }

        $output = $event->output;
        $output->writeln('');
        $output->writeln('  <error> UPGRADE BLOCKED </error>');
        $output->writeln('');
        $output->writeln(sprintf(
            '  This release (%s) needs something done before its migrations may run.',
            $assessment['target'] ?? 'unknown',
        ));

        if ($assessment['legacy']) {
            $output->writeln('');
            $output->writeln('  This install has no recorded release, so every requirement');
            $output->writeln('  published so far is being checked. Some may already be done.');
        }

        foreach ($assessment['actions'] as $action) {
            $output->writeln('');
            $output->writeln(sprintf('  <comment>%s</comment> (from %s, %s)',
                $action['id'] ?? '?', $action['release'] ?? '?', $action['phase'] ?? '?'));
            $output->writeln('    '.($action['summary'] ?? ''));

            if (($action['detail'] ?? '') !== '') {
                $output->writeln('    '.$action['detail']);
            }

            $output->writeln(sprintf('    Acknowledge with: %s/%s',
                $action['release'] ?? '?', $action['id'] ?? '?'));
        }

        $output->writeln('');
        $output->writeln('  Once done, set WAYFINDR_ACKNOWLEDGED_ACTIONS to the comma-separated');
        $output->writeln('  list of the entries above and start again. Nothing has been changed.');
        $output->writeln('');

        // exit() rather than an exception: the console kernel catches every
        // exception, hard-returns 1 and prints a stack trace, so a distinguished
        // code cannot survive a throw. The entrypoint needs that code to tell a
        // refusal from an unreachable database.
        exit(UpgradeGuard::EXIT_BLOCKED);
    }
}
