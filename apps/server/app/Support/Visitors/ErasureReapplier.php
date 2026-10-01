<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Makes erasures survive a restore (ADR 0026 §8). Restoring an archive taken
 * before an erasure brings the person back, and an operator restoring after
 * an incident will not remember last month's requests, so the ledger on the
 * volume, which the restore cannot roll back, puts them right.
 *
 * Re-applying erases by visitor ID, which is only safe while no new visitor
 * can take an erased person's ID. An imported dump resets the ID sequence to
 * the archive's, so the restore moves it past every ID the ledger holds before
 * anything else can create a visitor, and re-application only ever runs for a
 * restore that did.
 */
final class ErasureReapplier
{
    /** Whether this restore is what made re-application outstanding. */
    private bool $markedOutstanding = false;

    public function __construct(
        private readonly ErasureLedger $ledger,
        private readonly VisitorEraser $eraser,
    ) {}

    /**
     * Before the restore replaces the database: the last moment it can say
     * which pending erasures committed. One it cannot answer for is kept as
     * committed and listed.
     *
     * @return array{fresh_volume: bool, unconfirmed: list<string>}
     */
    public function beforeRestore(): array
    {
        // A ledger the restore itself would purge is refused while nothing
        // has changed.
        $this->ledger->assertOutsideAttachmentDisks();

        // Before anything creates the directory: a volume that never held
        // the ledger cannot know about erasures made after the archive.
        $fresh = ! $this->ledger->exists();

        // First, so the serving gate refuses every request from here on and
        // no erasure can start while the restore is under way; so a restore
        // that fails anywhere after the load still leaves its re-application
        // on record; and so a volume the ledger cannot be written to stops the
        // restore while nothing has changed. An erasure already past the gate
        // may still write its entry after the snapshot below: afterRestore()
        // settles that one.
        $this->markedOutstanding = ! $this->ledger->reapplyOutstanding();
        $this->ledger->markReapplyOutstanding();

        $this->ledger->backfill();
        $settled = $this->ledger->reconcile(assumeCommitted: true);

        return ['fresh_volume' => $fresh, 'unconfirmed' => $settled['unconfirmed']];
    }

    /**
     * The restore stopped before it replaced anything, so it has nothing to
     * re-apply. One an earlier restore left outstanding stays that way.
     */
    public function abandon(): void
    {
        if (! $this->markedOutstanding) {
            return;
        }

        try {
            $this->ledger->clearReapplyOutstanding();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * After the dump is imported. Erasing reads the tables the running code
     * knows, so a schema that differs from them waits: one behind waits for
     * `migrate`, and one ahead, from a newer release, for that release,
     * whose own migrate run then finishes it. Re-application stays
     * outstanding on the ledger until then, and the next scheduled
     * wayfindr:finish-erasures also tries.
     *
     * @return array{unconfirmed: list<string>, entries: int, reapplied: int, visitors: int, deferred: bool, failed: string|null, unverifiable: list<string>}
     */
    public function afterRestore(): array
    {
        // An erasure that wrote its entry after beforeRestore() looked, and
        // committed before the load replaced its row, is pending still, and
        // the restored database cannot answer for it. Kept as committed: one
        // the operator confirmed costs less to re-apply than to lose.
        $late = $this->ledger->reconcile(assumeCommitted: true, restoredSinceErasure: true);

        // The restored ledger table may know erasures this volume does not:
        // a volume that is new, or older than the ledger.
        $this->ledger->backfill();
        $this->moveVisitorSequencePast($this->ledger->highestVisitorId());

        if (! $this->schemaIsCurrent()) {
            return [
                'unconfirmed' => $late['unconfirmed'],
                'entries' => count($this->ledger->committed()),
                'reapplied' => 0,
                'visitors' => 0,
                'deferred' => true,
                'failed' => null,
                'unverifiable' => [],
            ];
        }

        return ['unconfirmed' => $late['unconfirmed'], ...$this->reapplyAll(), 'deferred' => false];
    }

    /**
     * Re-application a restore left outstanding, once the schema has caught
     * up. Null when none is outstanding, or the schema is still behind.
     *
     * @return array{entries: int, reapplied: int, visitors: int, failed: string|null, unverifiable: list<string>}|null
     */
    public function reapplyOutstanding(): ?array
    {
        if (! $this->ledger->reapplyOutstanding() || ! $this->schemaIsCurrent()) {
            return null;
        }

        $this->ledger->reconcile();

        return $this->reapplyAll();
    }

    /**
     * @return array{entries: int, reapplied: int, visitors: int, failed: string|null, unverifiable: list<string>}
     */
    private function reapplyAll(): array
    {
        $entries = $this->ledger->committed();
        // Again, and before any erasing: a restore that failed after its load
        // left the move undone, and erasing by ID is only safe past it.
        $this->moveVisitorSequencePast($this->ledger->highestVisitorId());
        $present = $this->presentVisitorIds($entries);
        $reapplied = 0;
        $visitors = 0;
        $failures = [];

        // An entry that cannot be read may be an erasure this restore undid,
        // so the work is not done until it is repaired.
        $unreadable = $this->ledger->unreadable();

        if ($unreadable !== []) {
            $failures[] = sprintf(
                'Ledger entries in %s cannot be read: %s. Put each back from a copy of the ledger, then run php artisan wayfindr:finish-erasures.',
                $this->ledger->path(),
                implode(', ', $unreadable),
            );
        }

        $unverifiable = [];

        foreach ($entries as $entry) {
            if (array_intersect(ErasureLedger::lineage($entry), $present) === []) {
                continue;
            }

            // Recorded before entries kept their site's key, by a site since
            // purged. Nothing can show the matching rows are this install's,
            // so they are left, and named, rather than risk erasing someone
            // else's contact.
            if (! is_string($entry['site_public_key'] ?? null) || $entry['site_public_key'] === '') {
                $unverifiable[] = (string) $entry['receipt'];

                continue;
            }

            try {
                $erased = $this->eraser->reapply($entry);
            } catch (Throwable $e) {
                report($e);
                $failures[] = $entry['receipt'].': '.$e->getMessage();

                continue;
            }

            if ($erased > 0) {
                $reapplied++;
                $visitors += $erased;
            }
        }

        // Kept while anything failed, so the next migrate or scheduled run
        // tries again.
        if ($failures === []) {
            $this->ledger->clearReapplyOutstanding();
        }

        return [
            'entries' => count($entries),
            'reapplied' => $reapplied,
            'visitors' => $visitors,
            'failed' => $failures === [] ? null : implode(' ', $failures),
            'unverifiable' => $unverifiable,
        ];
    }

    /**
     * The visitor IDs the ledger names that the database holds, so an
     * erasure the restore did not bring back costs no transaction.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<int>
     */
    private function presentVisitorIds(array $entries): array
    {
        $ids = array_values(array_unique(array_merge([], ...array_map(ErasureLedger::lineage(...), $entries))));
        $present = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            $present = [...$present, ...DB::table('visitors')->whereIn('id', $chunk)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()];
        }

        return $present;
    }

    /**
     * Move the visitor ID sequence past the highest ID the ledger names, and
     * never back.
     */
    private function moveVisitorSequencePast(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $connection = DB::connection();

        match ($connection->getDriverName()) {
            'pgsql' => $connection->select(
                "select setval(pg_get_serial_sequence('visitors', 'id'), greatest(?::bigint, (select coalesce(max(id), 0) from visitors), coalesce(pg_sequence_last_value(pg_get_serial_sequence('visitors', 'id')::regclass), 0)))",
                [$id],
            ),
            'sqlite' => $this->moveSqliteSequencePast($id),
            default => throw new RuntimeException(sprintf(
                'Cannot move the visitor ID sequence on the %s driver; restore supports PostgreSQL.',
                $connection->getDriverName(),
            )),
        };
    }

    private function moveSqliteSequencePast(int $id): void
    {
        $current = DB::table('sqlite_sequence')->where('name', 'visitors')->value('seq');
        $past = max($id, (int) $current, (int) DB::table('visitors')->max('id'));

        if ($current === null) {
            DB::table('sqlite_sequence')->insert(['name' => 'visitors', 'seq' => $past]);
        } else {
            DB::table('sqlite_sequence')->where('name', 'visitors')->update(['seq' => $past]);
        }
    }

    /**
     * Whether the database has run exactly the migrations the running code
     * ships. One it has not run is a table erasing would miss or a column it
     * would not find; one the code does not know is a newer release's table
     * that could hold the person, and this code cannot reach it.
     */
    private function schemaIsCurrent(): bool
    {
        try {
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return false;
            }

            $shipped = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));
            $ran = $migrator->getRepository()->getRan();

            return array_diff($shipped, $ran) === [] && array_diff($ran, $shipped) === [];
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
