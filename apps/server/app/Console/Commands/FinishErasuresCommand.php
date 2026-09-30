<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VisitorErasure;
use App\Support\Visitors\VisitorEraser;
use Illuminate\Console\Command;

/**
 * Finish what an erasure could not do inside its transaction (ADR 0026 §1):
 * remove attachment binaries that storage refused, or that a crash after the
 * commit left behind. Each erasure's receipt lists them until they are gone.
 */
class FinishErasuresCommand extends Command
{
    protected $signature = 'wayfindr:finish-erasures';

    protected $description = 'Remove attachment binaries that a contact erasure could not remove at the time.';

    public function handle(VisitorEraser $eraser): int
    {
        $remaining = 0;

        // By ID, not by page: each receipt that finishes leaves this query,
        // and offset pages would step over the ones that moved up.
        VisitorErasure::query()
            ->whereNotNull('pending_files')
            ->lazyById(500)
            ->each(function (VisitorErasure $erasure) use ($eraser, &$remaining): void {
                $remaining += $eraser->removePendingFiles($erasure);
            });

        if ($remaining > 0) {
            $this->warn("{$remaining} erased attachment file(s) could not be removed yet; the next run tries again.");

            return self::FAILURE;
        }

        $this->info('No erased attachment files are waiting to be removed.');

        return self::SUCCESS;
    }
}
