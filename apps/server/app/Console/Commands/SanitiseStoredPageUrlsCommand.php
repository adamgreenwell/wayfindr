<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Visitors\StoredPageUrlSweep;
use Illuminate\Console\Command;

/**
 * Re-run the stored page-address sweep.
 *
 * The migration does the bulk of it, but it runs BEFORE the new release is
 * activated on the supported zero-downtime path -- so the old writers are still
 * accepting widget traffic while it works, and anything written after its chunk
 * was passed keeps its query string.
 *
 * The deploy script calls this after activation, when the only code serving is
 * the code that sanitises on the way in. Operators on other install shapes can
 * run it by hand; it is idempotent, so running it again costs a table scan and
 * changes nothing.
 */
class SanitiseStoredPageUrlsCommand extends Command
{
    protected $signature = 'wayfindr:sanitise-page-urls';

    protected $description = 'Rewrite stored visitor page addresses that still carry a query string';

    public function handle(): int
    {
        $rewritten = StoredPageUrlSweep::run();

        foreach ($rewritten as $table => $count) {
            $this->line(sprintf('  %-15s %d rewritten', $table, $count));
        }

        $total = array_sum($rewritten);

        // Nothing to do is the expected result on every run after the first,
        // and saying so plainly stops it reading like a failure in a deploy log.
        $this->info($total === 0
            ? 'No stored page address needed rewriting.'
            : sprintf('Rewrote %d stored page address(es).', $total));

        return self::SUCCESS;
    }
}
