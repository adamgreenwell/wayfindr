<?php

namespace App\Console\Commands;

use App\Support\Release\UpgradeGuard;
use Illuminate\Console\Command;

class UpgradeGuardCommand extends Command
{
    protected $signature = 'wayfindr:upgrade-guard
        {--json : Emit the assessment as JSON for tooling}';

    protected $description = 'Report whether this release has operator requirements outstanding.';

    public function handle(UpgradeGuard $guard): int
    {
        $assessment = $guard->assess();

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

        if (! $assessment['blocked']) {
            $this->info('  Nothing outstanding — migrations may run.');

            return self::SUCCESS;
        }

        $this->error(sprintf('  %d requirement(s) must be met before migrating:', count($assessment['actions'])));

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
        }

        $this->line('');
        $this->line('  Set WAYFINDR_ACKNOWLEDGED_ACTIONS to the comma-separated entries above');
        $this->line('  once the work is done.');

        return self::FAILURE;
    }
}
