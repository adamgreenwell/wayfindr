<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ai\Refinement\DraftRefinements;
use Illuminate\Console\Command;

final class PruneDraftRefinementsCommand extends Command
{
    protected $signature = 'wayfindr:prune-draft-refinements';

    protected $description = 'Delete expired private refinement content and aged send claims.';

    public function handle(DraftRefinements $refinements): int
    {
        $this->info((string) $refinements->cleanup());

        return self::SUCCESS;
    }
}
