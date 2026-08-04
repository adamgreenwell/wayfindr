<?php

namespace App\Console\Commands;

use App\Support\Release\UpgradeGuard;
use App\Support\Release\UpgradeRequirements;
use Illuminate\Console\Command;

class UpgradeGuardCommand extends Command
{
    protected $signature = 'wayfindr:upgrade-guard
        {--json : Emit the assessment as JSON for tooling}';

    protected $description = 'Report whether this release has operator requirements outstanding.';

    public function handle(UpgradeGuard $guard): int
    {
        $assessment = $guard->assess();

        // assess() reports only what blocks MIGRATION. Reporting that alone would
        // print "nothing outstanding" and exit 0 while the serving gate refuses
        // traffic for an unmet after-start action — the command contradicting the
        // running system.
        $all = $guard->assessAll();
        $assessment['actions'] = $all;

        // A floor refusal carries NO actions: nothing an operator could do to
        // this install makes the jump supported, so assess() returns before it
        // evaluates requirements at all. Deriving `blocked` from the action count
        // alone therefore overwrote a refusal with success — and the --json
        // branch returns right below, before the floor-specific path that would
        // otherwise have caught it. Tooling was handed a clean assessment for an
        // upgrade the guard had already refused.
        $assessment['blocked'] = $all !== [] || ($assessment['floor'] ?? null) !== null;

        if ($this->option('json')) {
            $this->line((string) json_encode($assessment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $assessment['blocked'] ? self::FAILURE : self::SUCCESS;
        }

        if ($assessment['target'] === null) {
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
            $this->error(sprintf('  This install (%s) is older than %s allows to upgrade directly.',
                $assessment['from'] ?? 'unknown', $assessment['target'] ?? 'this release'));
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

            $this->line(sprintf('    Acknowledge with: %s/%s',
                $action['release'] ?? '?', $action['id'] ?? '?'));
            $this->line(sprintf('    Blocks: %s',
                in_array($action['phase'] ?? '', UpgradeRequirements::BLOCKS_MIGRATION, true)
                    ? 'migration' : 'serving'));
        }

        $this->line('');
        $this->line('  Set WAYFINDR_ACKNOWLEDGED_ACTIONS to the comma-separated entries above');
        $this->line('  once the work is done.');

        return self::FAILURE;
    }
}
