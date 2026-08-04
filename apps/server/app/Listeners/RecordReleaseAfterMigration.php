<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeGuard;
use Illuminate\Console\Events\CommandFinished;

/**
 * Records which release this install is running, once its migrations succeed
 * (ADR 0013).
 *
 * The guard needs the upgrade's starting point to know which declarations a span
 * covers, and nothing recorded it before this.
 *
 * WHY after migration rather than after the server starts. The ADR asks for
 * "after a successful start", and this is the closest point every deployment
 * path shares — the container entrypoint, both Forge scripts and a manual
 * upgrade all run migrations, while "the server began serving" has no hook that
 * is common to them. It is also the moment that matters for the question being
 * asked: the recorded version is used to work out which schema changes have been
 * applied, and a release whose migrations succeeded has applied its own.
 *
 * A release whose migrations FAILED is deliberately not recorded, so the next
 * upgrade does not compute its span from a release that never took effect.
 */
class RecordReleaseAfterMigration
{
    private const MIGRATION_COMMANDS = ['migrate', 'migrate:fresh', 'migrate:refresh'];

    public function handle(CommandFinished $event): void
    {
        if (! in_array($event->command, self::MIGRATION_COMMANDS, true)) {
            return;
        }

        if ($event->exitCode !== 0) {
            return;
        }

        $version = config('wayfindr.release.version');

        // Nothing to record on a development checkout: no identity means no
        // release, and writing a placeholder would give the guard a starting
        // point it should not trust.
        if (! is_string($version) || trim($version) === '') {
            return;
        }

        $commit = config('wayfindr.release.commit');

        // Evaluated BEFORE recording, while the state still says where this
        // upgrade started — recording first would collapse the span to the
        // target and answer "nothing outstanding" every time.
        //
        // Target-inclusive, because an after-start requirement of the release
        // just installed is exactly the kind this has to see.
        $outstanding = app(UpgradeGuard::class)->assessAll();

        // The marker advances only on a clean assessment. Left where it is, the
        // span keeps reaching back past the intermediate releases whose
        // after-start work is still owed — the alternative is that migrating
        // silently discharges requirements nobody performed.
        //
        // It stays put until the NEXT successful migration finds nothing
        // outstanding, which is later than strictly necessary and harmless: a
        // wider span re-evaluates actions that are already satisfied and finds
        // them satisfied.
        app(ReleaseState::class)->record(
            $version,
            is_string($commit) && trim($commit) !== '' ? $commit : null,
            satisfiedThrough: $outstanding === [] ? $version : null,
        );
    }
}
