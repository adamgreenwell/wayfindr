<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\UpgradeContext;
use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Illuminate\Console\Events\CommandStarting;
use Throwable;

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

    /**
     * Commands that tear the schema down and rebuild it, each running `migrate`
     * themselves. Their own start is guarded; the nested run is not.
     */
    private const REBUILDING_COMMANDS = ['migrate:fresh', 'migrate:refresh'];

    public function handle(CommandStarting $event): void
    {
        if (! in_array($event->command, self::GUARDED, true)) {
            return;
        }

        // The nested migration inside a rebuild is not a second upgrade.
        //
        // `migrate:fresh` and `migrate:refresh` guard their own start, then wipe
        // or reset, then run `migrate` — which arrives here again with the schema
        // already gone. A `state` check that passed against the old schema can
        // now answer false or null, so the guard would refuse AFTER the database
        // was destroyed, and the hard exit would skip the CommandFinished cleanup
        // that clears the stale release record.
        $outer = app(UpgradeContext::class)->outerCommand();

        if ($outer !== $event->command && in_array($outer, self::REBUILDING_COMMANDS, true)) {
            return;
        }

        try {
            $assessment = app(UpgradeGuard::class)->assess();
        } catch (Throwable $e) {
            // The assessment could not be made at all — the database is
            // unreachable, so whether this install is fresh or legacy is unknown.
            //
            // It must still not migrate: a connection that recovers between here
            // and the migrator's own access would apply schema changes with every
            // requirement unevaluated. But this is NOT a refusal, so it must not
            // exit 78 — the container entrypoint treats that as final, and a
            // database still starting up would take the whole stack down instead
            // of being retried. An ordinary failure is what gets retried.
            $event->output->writeln('');
            $event->output->writeln('<error>Cannot check this release: '.$e->getMessage().'</error>');
            $event->output->writeln('  Not migrating, because whether this upgrade owes anything is unknown.');
            $event->output->writeln('');

            exit(1);
        }

        if (! $assessment['blocked']) {
            return;
        }

        $output = $event->output;
        $output->writeln('');
        $output->writeln('  <error> UPGRADE BLOCKED </error>');
        $output->writeln('');

        // A floor refusal is not a to-do list. The migrations that would carry
        // this install forward have been retired, so there is nothing the
        // operator can do here except upgrade in steps - saying "acknowledge
        // these" would be advice that cannot work.
        if (($assessment['floor'] ?? null) !== null) {
            $output->writeln(sprintf(
                '  This install (%s) is older than %s allows to upgrade directly.',
                $assessment['from'] ?? 'unknown', $assessment['target'] ?? 'this release',
            ));
            $output->writeln('');
            $output->writeln(sprintf('  The oldest supported starting point is %s.', $assessment['floor']));
            $output->writeln('  Upgrade to that release first, let it start, then upgrade again.');
            $output->writeln('');
            $output->writeln('  Nothing has been changed. Acknowledgement cannot help here: the');
            $output->writeln('  migrations for this jump no longer ship.');
            $output->writeln('');

            exit(UpgradeGuard::EXIT_BLOCKED);
        }

        $output->writeln(sprintf(
            '  This release (%s) needs something done before its migrations may run.',
            $assessment['target'] ?? 'unknown',
        ));

        if ($assessment['legacy']) {
            $output->writeln('');
            $output->writeln('  This install has no recorded release, so every requirement');
            $output->writeln('  published so far is being checked. Some may already be done.');
        }

        $stranded = false;

        foreach ($assessment['actions'] as $action) {
            $output->writeln('');
            $output->writeln(sprintf('  <comment>%s</comment> (from %s, %s)',
                $action['id'] ?? '?', $action['release'] ?? '?', $action['phase'] ?? '?'));
            $output->writeln('    '.($action['summary'] ?? ''));

            if (($action['detail'] ?? '') !== '') {
                $output->writeln('    '.$action['detail']);
            }

            // A stranded action gets instructions, not a key. Acknowledging one
            // no longer settles it, so printing the key here told the operator to
            // do something that produces this identical refusal — and never
            // mentioned the intermediate release they actually have to install.
            //
            // This is the refusal an operator meets on Forge, on a manual
            // migration, or on any upgrade whose installer preflight was skipped,
            // so it has to carry the same guidance the command does rather than
            // relying on them running the command as well.
            if (UpgradeRequirements::unacknowledgeable($action, $assessment['target'] ?? null, $assessment['from'] ?? null)) {
                $output->writeln(sprintf(
                    '    <error>Cannot be done on this jump.</error> It needs %s, which this upgrade skips.',
                    $action['release'] ?? 'an intermediate release',
                ));
                $output->writeln('    Install that release first, let it start, then upgrade again.');
                $output->writeln('    Acknowledging will not clear this: the work is unreachable, not undone.');

                $stranded = true;

                continue;
            }

            $output->writeln(sprintf('    Acknowledge with: %s/%s',
                $action['release'] ?? '?', $action['id'] ?? '?'));
        }

        $output->writeln('');

        if ($stranded) {
            $output->writeln('  At least one step above belongs to a release this upgrade skips over.');
            $output->writeln('  Install that release first — no acknowledgement can substitute for it.');
            $output->writeln('');
        }

        $output->writeln('  For anything else, set WAYFINDR_ACKNOWLEDGED_ACTIONS to the comma-separated');
        $output->writeln('  list of the entries above and start again. Nothing has been changed.');
        $output->writeln('');

        // exit() rather than an exception: the console kernel catches every
        // exception, hard-returns 1 and prints a stack trace, so a distinguished
        // code cannot survive a throw. The entrypoint needs that code to tell a
        // refusal from an unreachable database.
        exit(UpgradeGuard::EXIT_BLOCKED);
    }
}
