<?php

namespace App\Console\Commands;

use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Illuminate\Console\Command;
use Throwable;

class UpgradeGuardCommand extends Command
{
    protected $signature = 'wayfindr:upgrade-guard
        {--json : Emit the assessment as JSON for tooling}';

    protected $description = 'Report whether this release has operator requirements outstanding.';

    public function handle(UpgradeGuard $guard): int
    {
        try {
            $assessment = $guard->assess();
        } catch (Throwable $e) {
            $this->error('Cannot check this release.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        // assess() reports only what blocks MIGRATION. Reporting that alone would
        // print "nothing outstanding" and exit 0 while the serving gate refuses
        // traffic for an unmet after-start action — the command contradicting the
        // running system.
        $all = $guard->assessAll();
        $assessment['actions'] = $all;

        // ADD to what assess() decided; never recompute it. A refusal can carry
        // no actions at all — the floor, an unreadable manifest, an unreadable
        // history — and deriving `blocked` from the action list alone reported
        // success for a release the migration gate refuses. Enumerating those
        // cases here was the previous shape and it went stale the moment another
        // actionless refusal was added, so the count only ever adds now.
        $assessment['blocked'] = $assessment['blocked'] || $all !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode($assessment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $assessment['blocked'] ? self::FAILURE : self::SUCCESS;
        }

        if ($assessment['target'] === null) {
            // A refusal can also have no target — an unreadable manifest names no
            // release. Reporting "nothing to check" and exiting 0 for the same
            // build the migration gate refuses is the contradiction this branch
            // was written to avoid, arrived at from the other side.
            if ($assessment['blocked']) {
                $this->error('Cannot check this release.');
                $this->line('  '.$assessment['reason']);

                return self::FAILURE;
            }

            // Not a failure: a development checkout and a pre-manifest build both
            // land here, and neither has anything to answer for.
            $this->info('No requirements to check.');
            $this->line('  '.$assessment['reason']);

            return self::SUCCESS;
        }

        $this->info(sprintf('Release %s', $assessment['target']));
        $this->line(sprintf('  Upgrading from: %s', $assessment['from'] ?? 'unrecorded'));

        if ($assessment['legacy']) {
            $this->warn('  This install has no recorded release, so every published requirement');
            $this->warn('  is being checked. Some may already have been done.');
        }

        if (($assessment['floor'] ?? null) !== null) {
            // Two different refusals share this field. One is "you are demonstrably
            // below the floor"; the other is "nothing records where you are, so the
            // floor cannot be checked". They have different remedies, and printing
            // the first for the second sends an operator who may be perfectly
            // current off to reinstall an older release.
            if ($assessment['from'] === null) {
                $this->error('  This install has no recorded release, so the upgrade floor cannot be verified.');
                $this->line(sprintf('  %s only supports upgrading from %s or later.',
                    $assessment['target'] ?? 'This release', $assessment['floor']));
                $this->line('  Either upgrade to that release first, which records where you are,');
                $this->line('  or state the version you are on:');
                $this->line(sprintf('    WAYFINDR_UPGRADE_FROM=%s', $assessment['floor']));

                return self::FAILURE;
            }

            $this->error(sprintf('  This install (%s) is older than %s allows to upgrade directly.',
                $assessment['from'], $assessment['target'] ?? 'this release'));
            $this->line(sprintf('  The oldest supported starting point is %s.', $assessment['floor']));
            $this->line('  Upgrade to that release first, let it start, then upgrade again.');
            $this->line('  Acknowledgement cannot help: the migrations for this jump no longer ship.');

            return self::FAILURE;
        }

        if (! $assessment['blocked']) {
            $this->info('  Nothing outstanding — migrations may run.');

            return self::SUCCESS;
        }

        $this->error(sprintf('  %d requirement(s) outstanding:', count($assessment['actions'])));

        foreach ($assessment['actions'] as $action) {
            $this->line('');
            $this->warn(sprintf('  %s (from %s, %s)',
                $action['id'] ?? '?', $action['release'] ?? '?', $action['phase'] ?? '?'));
            $this->line('    '.($action['summary'] ?? ''));

            if (($action['detail'] ?? '') !== '') {
                $this->line('    '.$action['detail']);
            }

            // Not offered for a stranded action: acknowledging one no longer
            // settles it, and printing the key beside instructions to step
            // through the release was the contradiction that made the bypass
            // look like the documented route.
            if (! UpgradeRequirements::stranded($action, $assessment['target'] ?? null, $assessment['from'] ?? null)) {
                $this->line(sprintf('    Acknowledge with: %s/%s',
                    $action['release'] ?? '?', $action['id'] ?? '?'));
            }
            // Phase alone is the wrong label for a stranded action. One belonging
            // to a release this jump skips past blocks MIGRATION whatever its
            // phase, so labelling an after-start one "serving" contradicts the
            // listener that actually refuses — in precisely the case where the
            // operator's recovery (roll back, step through) depends on knowing
            // the migration is what stopped.
            $blocksMigration = in_array(
                $action['phase'] ?? '', UpgradeRequirements::BLOCKS_MIGRATION, true,
            ) || UpgradeRequirements::stranded($action, $assessment['target'] ?? null, $assessment['from'] ?? null);

            $this->line(sprintf('    Blocks: %s', $blocksMigration ? 'migration' : 'serving'));

            if (UpgradeRequirements::stranded($action, $assessment['target'] ?? null, $assessment['from'] ?? null)) {
                $this->line(sprintf('    Cannot be done on this jump: it needs %s, which this upgrade skips.',
                    $action['release'] ?? 'an intermediate release'));
                $this->line('    Upgrade to that release first, let it start, then continue.');
            }
        }

        $this->line('');
        $this->line('  Set WAYFINDR_ACKNOWLEDGED_ACTIONS to the comma-separated entries above');
        $this->line('  once the work is done.');

        return self::FAILURE;
    }
}
