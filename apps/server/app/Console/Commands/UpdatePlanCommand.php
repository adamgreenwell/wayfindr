<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Release\UpgradeGuard;
use App\Support\Updates\InstallationCapabilities;
use App\Support\Updates\ReleaseCatalogClient;
use App\Support\Updates\ReleaseMetadataException;
use App\Support\Updates\UpdatePlanner;
use Illuminate\Console\Command;
use Throwable;

/** Metadata inspection only: never pulls, writes state, or starts an update. */
final class UpdatePlanCommand extends Command
{
    protected $signature = 'wayfindr:update-plan {--ref= : Exact stable release tag; defaults to the latest published stable release} {--json : Output the frozen review receipt as JSON}';

    protected $description = 'Review an exact Wayfindr update without changing the installation';

    public function handle(ReleaseCatalogClient $catalogs, UpdatePlanner $planner, UpgradeGuard $guard): int
    {
        try {
            $ref = $this->option('ref');
            $catalog = $catalogs->fetch(is_string($ref) && $ref !== '' ? $ref : null);
            $plan = $planner->build($catalog, InstallationCapabilities::local($guard->installationProfile()))->toArray();
        } catch (Throwable $exception) {
            $reason = $exception instanceof ReleaseMetadataException ? $exception->reason : 'assessment_failed';

            if ($this->option('json')) {
                $this->line(json_encode(['schema' => 1, 'status' => 'failed', 'reason' => $reason], JSON_THROW_ON_ERROR));
            } else {
                $this->error('The update plan could not be verified ('.$reason.'). Recheck the release metadata and installation prerequisites.');
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Update review: '.str_replace('_', ' ', $plan['status']));
            $this->line(($plan['source']['runtime_version'] ?? 'unknown').' → '.$plan['target']['tag']);
            $this->line('Plan: '.$plan['plan_id']);
            $this->line('Release: '.$plan['target']['release_url']);
            $this->line('Prerequisites: '.$plan['release_requirements']['reason']);

            if ($plan['release_requirements']['minimum_upgrade_from'] !== null) {
                $this->line('Minimum supported starting release: '.$plan['release_requirements']['minimum_upgrade_from']);
            }

            foreach ($plan['release_requirements']['actions'] as $action) {
                $this->warn($action['release'].'/'.$action['id'].' ('.$action['phase'].', '.$action['disposition'].'): '.$action['summary']);
                $this->line('  '.$action['guidance']);
            }

            foreach ($plan['advisory_notices'] as $notice) {
                $this->line('Advisory: '.$notice['summary']);
            }

            $this->line('Manual upgrade: '.$plan['manual']['guidance']);
            $this->line('Managed execution is unavailable in this read-only planner. Use --json for capability details.');
            $this->line('Expected interruption: '.$plan['effects']['interruption']);
            $this->line('Recovery limit: '.$plan['recovery']['after_schema_mutation']);
        }

        return in_array($plan['status'], ['update_available', 'up_to_date'], true) ? self::SUCCESS : UpgradeGuard::EXIT_BLOCKED;
    }
}
