<?php

namespace App\Console\Commands;

use App\Support\Release\ActionAdvice;
use App\Support\Release\FloorAdvice;
use App\Support\Release\UpgradeGuard;
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
            // Two different refusals share this field, and they have different
            // remedies — see FloorAdvice. Both this command and the migration
            // refusal render its lines, because the distinction was made here
            // and not in its twin, which is the message an operator actually
            // meets during an upgrade.
            $advice = FloorAdvice::for(
                $assessment['from'] ?? null,
                $assessment['target'] ?? null,
                (string) $assessment['floor'],
            );

            $lines = $advice->lines;
            $this->error('  '.array_shift($lines));

            foreach ($lines as $line) {
                $this->line('  '.$line);
            }

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

            // The same advice the migration refusal gives, rendered from the same
            // ordered list. Deriving it separately is what let the two drift
            // apart at nearly every step of #648.
            $advice = ActionAdvice::for($action, $assessment['target'] ?? null, $assessment['from'] ?? null);

            // Phase alone is the wrong label for an action needing a release the
            // pull replaced. One belonging to a release this jump skips past
            // blocks MIGRATION whatever its phase, so labelling an after-start
            // one "serving" contradicts the listener that actually refuses — in
            // precisely the case where the operator's recovery (roll back, step
            // through) depends on knowing the migration is what stopped.
            //
            // Printed with the header rather than between the key and the
            // recovery: it is metadata about the action, and keeping it out of
            // the advice block is what lets both sites render that block
            // identically.
            $this->line(sprintf('    Blocks: %s', $advice->blocksMigration ? 'migration' : 'serving'));

            foreach ($advice->lines() as $line) {
                $this->line('    '.$line);
            }
        }

        $this->line('');
        $this->line('  Set WAYFINDR_ACKNOWLEDGED_ACTIONS to the comma-separated entries above');
        $this->line('  once the work is done.');

        return self::FAILURE;
    }
}
