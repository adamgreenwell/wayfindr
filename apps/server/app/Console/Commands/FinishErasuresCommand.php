<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\VisitorErasure;
use App\Support\Visitors\ErasureLedger;
use App\Support\Visitors\ErasureReapplier;
use App\Support\Visitors\VisitorEraser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Finish what an erasure could not do inside its transaction (ADR 0026 §1, §8):
 * settle ledger entries whose erasure ended without saying whether it
 * committed, record erasures the volume's ledger does not hold yet, finish a
 * re-application a restore left outstanding, and remove attachment binaries
 * that storage refused, or that a crash after the commit left behind.
 */
class FinishErasuresCommand extends Command
{
    protected $signature = 'wayfindr:finish-erasures';

    protected $description = 'Settle the erasure ledger and remove attachment binaries that a contact erasure could not remove at the time.';

    public function handle(ErasureLedger $ledger, ErasureReapplier $erasures, VisitorEraser $eraser): int
    {
        $failed = false;

        // Settled first: a failed erasure's list of binaries names files that
        // live rows still point at, and must remove nothing.
        try {
            $settled = $ledger->reconcile();
            $backfilled = $ledger->backfill();

            if ($settled['discarded'] !== []) {
                $this->line('Ledger entries removed for erasures that never committed: '.count($settled['discarded']).'.');
            }

            if ($backfilled > 0) {
                $this->line("Erasures newly recorded in the ledger on the storage volume: {$backfilled}.");
            }
        } catch (Throwable $e) {
            report($e);
            $this->error('The erasure ledger could not be settled: '.$e->getMessage());
            $failed = true;
        }

        try {
            $reapplied = $erasures->reapplyOutstanding();

            if ($reapplied !== null) {
                $this->line("Erasures re-applied after a restore: {$reapplied['visitors']} contact(s).");

                if ($reapplied['failed'] !== null) {
                    $this->error('Some erasures could not be re-applied yet, and the next run tries again: '.$reapplied['failed']);
                    $failed = true;
                }
            }
        } catch (Throwable $e) {
            report($e);
            $this->error('Erasures could not be re-applied after a restore: '.$e->getMessage());
            $failed = true;
        }

        // The volume's entries and the ledger rows each list what they know:
        // the volume survives a restore, and a row survives a lost volume.
        $receipts = collect($ledger->committed())
            ->filter(fn (array $entry): bool => ErasureLedger::files($entry['pending_files'] ?? []) !== [])
            ->pluck('receipt')
            ->merge(VisitorErasure::query()->whereNotNull('pending_files')->pluck('public_id'))
            ->map(fn (mixed $receipt): string => (string) $receipt)
            ->unique()
            ->values();

        $remaining = 0;

        foreach ($receipts as $receipt) {
            // One receipt the ledger cannot update must not keep the rest
            // waiting for another hour.
            try {
                $remaining += $eraser->removePendingFiles($receipt);
            } catch (Throwable $e) {
                report($e);
                $this->error("The files of erasure {$receipt} could not be struck off: ".$e->getMessage());
                $failed = true;
            }
        }

        if ($remaining > 0) {
            $this->warn("{$remaining} erased attachment file(s) could not be removed yet; the next run tries again.");

            return self::FAILURE;
        }

        if ($failed) {
            return self::FAILURE;
        }

        $this->info('No erased attachment files are waiting to be removed.');

        return self::SUCCESS;
    }
}
