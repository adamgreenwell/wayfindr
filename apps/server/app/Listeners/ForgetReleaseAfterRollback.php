<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeContext;
use Illuminate\Console\Events\CommandFinished;

/**
 * Drops the recorded release when the schema is rewound (ADR 0013).
 *
 * `migrate:rollback` and `migrate:reset` are the documented recovery path when an
 * upgrade goes wrong, and they undo migrations without touching the state file —
 * which then goes on claiming a release whose schema is no longer installed.
 *
 * That claim is load-bearing rather than cosmetic. The next upgrade measures its
 * floor and its span from it, so a rewound install can clear a
 * `minimum_upgrade_from` it is now below and migrate on a path that has since
 * been retired: precisely the jump the floor exists to refuse.
 *
 * `migrate:refresh` is deliberately absent. It ends by migrating, so the recorder
 * runs after it and writes an accurate record; forgetting here would be undone a
 * moment later anyway.
 */
class ForgetReleaseAfterRollback
{
    private const REWINDING_COMMANDS = ['migrate:rollback', 'migrate:reset', 'db:wipe'];

    /**
     * Commands that tear the ledger down and rebuild it in one go.
     *
     * A successful rebuild ends by migrating, so the recorder writes an accurate
     * record and nothing needs forgetting. A FAILED one has wiped or reset the
     * ledger and then not finished re-applying it, so the record describes a
     * schema that is no longer installed — and the refresh path deliberately
     * preserves state through its nested reset, so nothing else clears it.
     */
    private const REBUILDING_COMMANDS = ['migrate:fresh', 'migrate:refresh'];

    public function handle(CommandFinished $event): void
    {
        if (in_array($event->command, self::REBUILDING_COMMANDS, true)) {
            if ($event->exitCode !== 0) {
                $this->forget($event);
            }

            return;
        }

        if (! in_array($event->command, self::REWINDING_COMMANDS, true)) {
            return;
        }

        // Nested inside a rebuild, rather than run on its own.
        //
        // `migrate:refresh` runs `migrate:reset`, and `migrate:fresh` runs
        // `db:wipe` — each firing its own CommandFinished here. Forgetting on
        // those left the nested migration reading an empty ledger, classifying a
        // long-standing install as fresh, and recording that exemption, which
        // erases outstanding after-start work from the serving gate.
        //
        // A rebuild ends by migrating, so the recorder writes an accurate record
        // and nothing needs forgetting; a rebuild that FAILS is caught above.
        //
        // Written as "the outer command is a rebuild and this is not it" rather
        // than by naming the pairs, so a rebuild calling something else still
        // behaves.
        $outer = app(UpgradeContext::class)->outerCommand();

        if ($outer !== $event->command && in_array($outer, self::REBUILDING_COMMANDS, true)) {
            return;
        }

        // `--pretend` prints the SQL and rewinds nothing, so there is no
        // disagreement to resolve — and forgetting would make an inspection
        // change the install, which is the one thing a dry run must not do.
        if ($event->input->hasParameterOption('--pretend')) {
            return;
        }

        // Deliberately regardless of exit code. A rollback that reverts one
        // migration and then fails on a later `down()` exits non-zero having
        // already changed the schema, so a non-zero exit is not evidence that
        // nothing happened — and the record would go on claiming a release whose
        // migrations are now partly undone.
        //
        // The asymmetry with the recorder is intended: recording a release that
        // did not install is a false claim, while forgetting one that is still
        // installed only costs a refusal the operator can clear by saying where
        // they are.

        $this->forget($event);
    }

    private function forget(CommandFinished $event): void
    {
        if (app(ReleaseState::class)->forget()) {
            return;
        }

        $output = $event->output;
        $output->writeln('');
        $output->writeln('<error>Could not clear the recorded release after rolling back.</error>');
        $output->writeln('  The schema has been rewound, but the upgrade guard still believes the');
        $output->writeln('  previous release is installed — so a later upgrade may clear a floor');
        $output->writeln('  this schema is now below, or skip requirements it still needs.');
        $output->writeln(sprintf('  Remove this file by hand: %s', (string) config('wayfindr.release.state_path')));
        $output->writeln('');

        // Non-zero, overriding the command's own success. The rollback did what
        // it was asked; the install is nonetheless in a state where the next
        // upgrade will be evaluated against a release that is no longer here, and
        // automation reading only the exit code would carry straight on into it.
        //
        // exit() rather than an exception: this runs on CommandFinished, after
        // the exit code is settled, and the console kernel would turn a throw
        // into the same 1 with a stack trace over the message above.
        exit(1);
    }
}
