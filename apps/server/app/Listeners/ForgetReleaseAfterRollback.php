<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\ReleaseState;
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
    private const REWINDING_COMMANDS = ['migrate:rollback', 'migrate:reset'];

    public function handle(CommandFinished $event): void
    {
        if (! in_array($event->command, self::REWINDING_COMMANDS, true)) {
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
