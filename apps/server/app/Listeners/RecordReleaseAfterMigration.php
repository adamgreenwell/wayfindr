<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Release\ReleaseState;
use App\Support\Release\UpgradeContext;
use App\Support\Release\UpgradeGuard;
use App\Support\Version\VersionComparator;
use Illuminate\Console\Events\CommandFinished;
use Throwable;

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

        // `--pretend` prints the SQL and runs none of it, exiting 0. Recording it
        // would mark the release installed when nothing was applied, and the next
        // REAL migration would then compute its span from the release it is about
        // to install — skipping that release's own pre-migration requirements.
        //
        // Checked against the raw tokens rather than getOption(), because not
        // every guarded command defines the option and asking for an undefined
        // one throws.
        if ($event->input->hasParameterOption('--pretend')) {
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
        $guard = app(UpgradeGuard::class);

        try {
            $outstanding = $guard->assessAll();
        } catch (Throwable) {
            // The migration succeeded, so the database was reachable a moment
            // ago; if it is not now, record nothing rather than fail a migration
            // that worked. An unrecorded release reads as legacy next time, which
            // evaluates more than necessary — the safe direction.
            return;
        }

        // Record the CANONICAL release, not the running identity.
        //
        // On a source deployment those differ: the identity is
        // `<version>-dev+<sha>` and changes with every commit, while the manifest
        // is stamped with `VERSION` so acknowledgements survive a redeploy. Store
        // the identity and the recorded release never equals the guard's own
        // target — which reclassifies a brand-new Forge install as an upgrade and
        // hands it upgrade-only work — and it cannot be ordered against a floor
        // either, since a development identity does not compare.
        //
        // The commit is kept separately below, so nothing about which build ran
        // is lost by recording the release it belongs to.
        $version = $guard->lastTarget() ?? $version;

        // The manifest's commit too, for the same reason. `buildChanged()`
        // compares what is on record against the MANIFEST, so recording the
        // runtime identity means a stale or overridden WAYFINDR_COMMIT reads as a
        // different build on the very next request — which drops a fresh
        // install's exemption and gates serving on work it never owed.
        $commit = $guard->lastCommit() ?? $commit;

        // The marker advances only on a clean assessment. Left where it is, the
        // span keeps reaching back past the intermediate releases whose
        // after-start work is still owed — the alternative is that migrating
        // silently discharges requirements nobody performed.
        //
        // It stays put until the NEXT successful migration finds nothing
        // outstanding, which is later than strictly necessary and harmless: a
        // wider span re-evaluates actions that are already satisfied and finds
        // them satisfied.
        $state = app(ReleaseState::class);
        $commit = is_string($commit) && trim($commit) !== '' ? $commit : null;

        // Observed before this command migrated anything. Recording it is what
        // lets the serving gate — a different process, which never saw the empty
        // database — agree that this install upgraded from nowhere.
        $freshInstall = app(UpgradeContext::class)->wasFreshInstall() === true;

        // And a no-op rerun must not clear it. Every container start runs
        // `migrate` again, and an install with a release on record never
        // re-observes freshness — the observation is only made when there is
        // nothing recorded to read. Without this the flag survives exactly one
        // restart, and the serving gate then treats a brand-new install as an
        // upgrade and refuses traffic for work it never owed.
        //
        // Carried only for the SAME build. A different release or commit is a
        // real upgrade, and freshness does not survive one.
        if (! $freshInstall
            && $state->wasFreshInstall()
            && $state->recordedVersion() === $version
            && $state->recordedCommit() === $commit) {
            $freshInstall = true;
        }

        $recorded = $state->record(
            $version,
            $commit,
            satisfiedThrough: $this->cleanThrough($state, $version, $outstanding),
            freshInstall: $freshInstall,
        );

        if ($recorded) {
            return;
        }

        // The write failed — an unwritable or full storage volume. The migration
        // itself succeeded, so failing here is not an option worth taking: the
        // container entrypoint retries a failed migrate, and a retry would find
        // nothing pending, fail to record again, and loop for as long as the disk
        // stays full.
        //
        // Said loudly instead, because the consequence is delayed and confusing
        // rather than immediate: the next process sees a populated database with
        // no state, reads the install as legacy, and may refuse a floor it cannot
        // verify or gate serving on the target's own after-start work. Those are
        // over-refusals rather than silent passes, which is the safe direction —
        // but an operator who never saw this line will not know why.
        $output = $event->output;
        $output->writeln('');
        $output->writeln('<error>Could not record this release to the state file.</error>');
        $output->writeln(sprintf('  Tried: %s', (string) config('wayfindr.release.state_path')));
        $output->writeln('  The migration succeeded, but the upgrade guard has no record of it.');
        $output->writeln('  Until this is writable, later upgrades will be treated as starting from');
        $output->writeln('  an unknown release, and may refuse until you say where you are.');
        $output->writeln('');
    }

    /**
     * The newest release this install owes nothing for.
     *
     * Three answers, and the middle one is the one that was missing:
     *
     * - nothing outstanding: the release just installed is clean
     * - something outstanding, and the stored marker sits BELOW all of it: keep
     *   the marker, because it is still the last release that owed nothing
     * - anything else: unknown, so the whole published history stays in span
     *
     * The marker used to be preserved unconditionally when work was outstanding,
     * which is wrong once it sits at or above the release carrying the debt — a
     * later build under the same VERSION adding an action, or a check that
     * regressed. Serving still blocked while that release was current, because
     * the target is included explicitly; but the next upgrade computed
     * (marker, target], the release carrying the debt fell outside it, and the
     * debt was dropped without a word.
     *
     * @param  list<array<string, mixed>>  $outstanding
     */
    private function cleanThrough(ReleaseState $state, string $version, array $outstanding): ?string
    {
        if ($outstanding === []) {
            return $version;
        }

        $marker = $state->satisfiedThrough();

        if ($marker === null) {
            return null;
        }

        foreach ($outstanding as $action) {
            $release = $action['release'] ?? null;

            if (! is_string($release)) {
                return null;
            }

            $rank = VersionComparator::compare($marker, $release);

            // Undecidable, or at/above something still owed: the marker can no
            // longer be shown to sit below the debt, so the span must widen to
            // everything rather than quietly exclude it.
            if ($rank === null || $rank >= 0) {
                return null;
            }
        }

        return $marker;
    }
}
