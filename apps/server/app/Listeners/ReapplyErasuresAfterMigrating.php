<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Visitors\ErasureReapplier;
use Illuminate\Console\Events\CommandFinished;
use Throwable;

/**
 * Re-applies erasures a restore had to leave outstanding (ADR 0026 §8).
 *
 * A restored archive older than the running code needs its migrations before
 * anything can be erased from it, and the runbook's next step after such a
 * restore is `php artisan migrate --force`. Finishing the work there, and
 * saying so in its output, closes the window in which the erased are back;
 * the scheduled wayfindr:finish-erasures is the fallback.
 *
 * Auto-discovered, like the upgrade guard's listener. `CommandFinished` fires
 * for a top-level run whether or not anything was pending, which is the case
 * that matters: an archive can be one migration behind or none.
 */
class ReapplyErasuresAfterMigrating
{
    public function __construct(private readonly ErasureReapplier $erasures) {}

    public function handle(CommandFinished $event): void
    {
        if ($event->command !== 'migrate' || $event->exitCode !== 0) {
            return;
        }

        try {
            $result = $this->erasures->reapplyOutstanding();
        } catch (Throwable $e) {
            report($e);
            $event->output->writeln('<error>Erasures could not be re-applied after the restore: '.$e->getMessage().' Keep the app in maintenance mode and run php artisan wayfindr:finish-erasures.</error>');

            return;
        }

        if ($result === null) {
            return;
        }

        $event->output->writeln(sprintf(
            'Erasures re-applied after the restore: %d contact(s), from %d of the %d erasure(s) in the ledger.',
            $result['visitors'],
            $result['reapplied'],
            $result['entries'],
        ));

        if ($result['unverifiable'] !== []) {
            $event->output->writeln('<comment>These erasures name restored contacts but were recorded without their site\'s key, so they were left as they are. Check each by its receipt: '.implode(', ', $result['unverifiable']).'</comment>');
        }

        if ($result['failed'] !== null) {
            $event->output->writeln('<error>Some erasures could not be re-applied yet: '.$result['failed'].' Keep the app in maintenance mode and run php artisan wayfindr:finish-erasures.</error>');
        }
    }
}
