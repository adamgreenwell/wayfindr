<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use App\Models\Site;
use App\Models\VisitorErasure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * The erasure ledger on the storage volume (ADR 0026 §8): one file per erased
 * contact, so a restore can re-apply the erasures its archive predates.
 *
 * Backups archive the database and the attachment disks, not this directory,
 * so a restore cannot roll it back; the `visitor_erasures` table is the copy
 * later backups carry. An entry holds internal IDs, the site's public key, a
 * time, the receipt and any binaries still to remove: never a name, email,
 * browser ID or content.
 *
 * An erasure writes its entry as `<receipt>.pending.json` inside its own
 * transaction, before it changes anything, and renames it to `<receipt>.json`
 * once the database has committed. A pending entry is settled against the
 * database by {@see self::reconcile()}.
 */
final class ErasureLedger
{
    private const RECEIPT = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** Written by a restore whose re-application has not run yet. */
    private const REAPPLY_MARKER = 'reapply-after-restore.json';

    public function path(): string
    {
        return rtrim((string) config('wayfindr.erasure.ledger_path', storage_path('app/erasure-ledger')), '/');
    }

    /**
     * Whether this volume has ever held the ledger. A restore onto a volume
     * without it cannot know about erasures made after its archive was taken.
     */
    public function exists(): bool
    {
        return is_dir($this->path());
    }

    /**
     * The entry for an erasure about to happen. Throws when it cannot be made
     * durable, so the erasure refuses with nothing changed.
     *
     * @param  array<string, mixed>  $entry
     */
    public function writePending(array $entry): void
    {
        $this->write($this->receiptOf($entry).'.pending.json', $entry);
    }

    /**
     * The erasure committed: its entry is part of the ledger from now on. The
     * erasure and a reconciliation can both get here; whichever is second
     * finds the work done.
     */
    public function promote(string $receipt): void
    {
        $this->assertReceipt($receipt);

        if (@rename($this->pendingPath($receipt), $this->committedPath($receipt))) {
            $this->syncDirectory();

            return;
        }

        if (! is_file($this->pendingPath($receipt)) && is_file($this->committedPath($receipt))) {
            return;
        }

        throw new RuntimeException("Could not record erasure {$receipt} as committed in {$this->path()}.");
    }

    /** The erasure never committed: its entry goes. */
    public function discard(string $receipt): void
    {
        $this->assertReceipt($receipt);

        $path = $this->pendingPath($receipt);

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException("Could not remove the entry of erasure {$receipt}, which never happened, from {$this->path()}.");
        }
    }

    /**
     * Write a committed entry whole: a backfilled one, or one whose list of
     * binaries has changed.
     *
     * @param  array<string, mixed>  $entry
     */
    public function record(array $entry): void
    {
        $this->write($this->receiptOf($entry).'.json', $entry);
    }

    /**
     * Every committed entry.
     *
     * @return list<array<string, mixed>>
     */
    public function committed(): array
    {
        return $this->entries('/^('.self::RECEIPT.')\.json$/');
    }

    /**
     * Every entry whose erasure has not been settled yet.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        return $this->entries('/^('.self::RECEIPT.')\.pending\.json$/');
    }

    /** @return array<string, mixed>|null */
    public function find(string $receipt): ?array
    {
        $this->assertReceipt($receipt);

        return $this->read($this->committedPath($receipt), $receipt);
    }

    /**
     * Change a committed entry's list of binaries still to remove, under a
     * lock, so two runs cannot each write back a list the other has changed.
     *
     * @param  callable(list<array{disk: string, key: string}>): list<array{disk: string, key: string}>  $change
     */
    public function updatePendingFiles(string $receipt, callable $change): void
    {
        $this->exclusively(function () use ($receipt, $change): void {
            $entry = $this->find($receipt);

            if ($entry === null) {
                return;
            }

            $entry['pending_files'] = self::files($change(self::files($entry['pending_files'] ?? [])));
            $this->record($entry);
        });
    }

    /**
     * The highest visitor ID any entry names. A restore moves the visitor ID
     * sequence past it, so no new visitor can take an erased person's ID and
     * be erased in their place by the next restore.
     */
    public function highestVisitorId(): int
    {
        $highest = 0;

        foreach ([...$this->committed(), ...$this->pending()] as $entry) {
            foreach (self::lineage($entry) as $id) {
                $highest = max($highest, $id);
            }
        }

        return $highest;
    }

    /** A restore replaced the database before its erasures could be re-applied. */
    public function markReapplyOutstanding(): void
    {
        $this->write(self::REAPPLY_MARKER, ['since' => gmdate('c')]);
    }

    public function reapplyOutstanding(): bool
    {
        return is_file($this->path().'/'.self::REAPPLY_MARKER);
    }

    public function clearReapplyOutstanding(): void
    {
        $path = $this->path().'/'.self::REAPPLY_MARKER;

        if (is_file($path) && ! @unlink($path)) {
            throw new RuntimeException("Could not clear {$path}.");
        }
    }

    /**
     * Settle every pending entry against the database. Each waits for the lock
     * on its site, which the erasure holds from before its entry is written
     * until its transaction ends: once it is free, the transaction is over,
     * however long it ran. Age is never taken as proof. Then a receipt with a
     * row committed, and one without never did.
     *
     * A database that cannot answer, because it is unreachable or older than
     * the ledger table, leaves the entries pending; a restore, which is about
     * to replace that database for good, passes $assumeCommitted instead, and
     * they are kept as committed. An erasure an operator confirmed costs far
     * less to re-apply by mistake than to lose.
     *
     * @return array{promoted: list<string>, discarded: list<string>, unconfirmed: list<string>}
     */
    public function reconcile(bool $assumeCommitted = false): array
    {
        $settled = ['promoted' => [], 'discarded' => [], 'unconfirmed' => []];
        $pending = $this->pending();

        if ($pending === []) {
            return $settled;
        }

        $answerable = $this->databaseCanAnswer();

        foreach ($pending as $entry) {
            $receipt = $this->receiptOf($entry);

            if (! $answerable) {
                if ($assumeCommitted) {
                    $this->promote($receipt);
                    $settled['unconfirmed'][] = $receipt;
                }

                continue;
            }

            try {
                $committed = DB::transaction(function () use ($entry, $receipt): bool {
                    // A site that no longer exists has no lock to wait on, and
                    // nothing can still be erasing on it.
                    if (is_int($entry['site_id'] ?? null)) {
                        Site::query()->whereKey($entry['site_id'])->lockForUpdate()->first(['id']);
                    }

                    return VisitorErasure::query()->where('public_id', $receipt)->exists();
                });
            } catch (Throwable $e) {
                report($e);

                if ($assumeCommitted) {
                    $this->promote($receipt);
                    $settled['unconfirmed'][] = $receipt;
                }

                continue;
            }

            if ($committed) {
                $this->promote($receipt);
                $settled['promoted'][] = $receipt;
            } else {
                $this->discard($receipt);
                $settled['discarded'][] = $receipt;
            }
        }

        return $settled;
    }

    /**
     * Write an entry for every ledger row that has none on this volume: an
     * erasure made before the volume held the ledger, or one a restored
     * database knows about and a fresh volume does not. A row exists only for
     * an erasure that committed, so each is safe to record. Returns how many
     * were written.
     */
    public function backfill(): int
    {
        if (! $this->databaseCanAnswer()) {
            return 0;
        }

        $written = 0;

        VisitorErasure::query()
            ->with('site:id,public_key')
            ->lazyById(500)
            ->each(function (VisitorErasure $erasure) use (&$written): void {
                $receipt = (string) $erasure->public_id;

                if (is_file($this->committedPath($receipt)) || is_file($this->pendingPath($receipt))) {
                    return;
                }

                $this->record(self::entryFor($erasure));
                $written++;
            });

        if (! $this->exists()) {
            $this->ensureDirectory();
        }

        return $written;
    }

    /**
     * The entry that records an erasure.
     *
     * @param  list<int>  $mergedVisitorIds
     * @param  list<array{disk: string, key: string}>  $pendingFiles
     * @return array<string, mixed>
     */
    public static function entry(
        string $receipt,
        int $accountId,
        ?Site $site,
        int $erasedVisitorId,
        array $mergedVisitorIds,
        ?int $actorId,
        string $erasedAt,
        array $pendingFiles,
    ): array {
        return [
            'receipt' => $receipt,
            'account_id' => $accountId,
            'site_id' => $site?->id === null ? null : (int) $site->id,
            // With the ID, what tells this install's site from another's: an
            // archive from somewhere else can hold a site, and visitors, under
            // the same IDs.
            'site_public_key' => $site?->public_key === null ? null : (string) $site->public_key,
            'erased_visitor_id' => $erasedVisitorId,
            'merged_visitor_ids' => array_values($mergedVisitorIds),
            'actor_id' => $actorId,
            'erased_at' => $erasedAt,
            'pending_files' => array_values($pendingFiles),
        ];
    }

    /** @return array<string, mixed> */
    public static function entryFor(VisitorErasure $erasure): array
    {
        return self::entry(
            (string) $erasure->public_id,
            (int) $erasure->account_id,
            $erasure->site,
            (int) $erasure->erased_visitor_id,
            array_map('intval', array_values((array) ($erasure->merged_visitor_ids ?? []))),
            $erasure->actor_id === null ? null : (int) $erasure->actor_id,
            $erasure->erased_at?->toIso8601ZuluString() ?? gmdate('Y-m-d\TH:i:s\Z'),
            self::files($erasure->pending_files ?? []),
        );
    }

    /**
     * Every visitor ID an entry names: the erased one and each merged into it.
     *
     * @param  array<string, mixed>  $entry
     * @return list<int>
     */
    public static function lineage(array $entry): array
    {
        return collect([$entry['erased_visitor_id'] ?? null, ...((array) ($entry['merged_visitor_ids'] ?? []))])
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<array{disk: string, key: string}>
     */
    public static function files(mixed $files): array
    {
        return collect(is_array($files) ? $files : [])
            ->filter(fn (mixed $file): bool => is_array($file) && is_string($file['key'] ?? null) && $file['key'] !== '')
            ->map(fn (array $file): array => ['disk' => (string) ($file['disk'] ?? ''), 'key' => (string) $file['key']])
            ->unique(fn (array $file): string => $file['disk']."\0".$file['key'])
            ->values()
            ->all();
    }

    private function databaseCanAnswer(): bool
    {
        try {
            return Schema::hasTable('visitor_erasures');
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(string $pattern): array
    {
        $directory = $this->path();

        if (! is_dir($directory)) {
            return [];
        }

        $entries = [];
        $names = scandir($directory);

        foreach ($names === false ? [] : $names as $name) {
            if (preg_match($pattern, $name, $match) !== 1) {
                continue;
            }

            $entry = $this->read($directory.'/'.$name, $match[1]);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @return array<string, mixed>|null */
    private function read(string $path, string $receipt): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $entry = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            // Every write is renamed into place whole, so this is a file
            // someone edited. Skipped, and said, rather than trusted.
            report(new RuntimeException("Unreadable erasure ledger entry {$path}.", previous: $e));

            return null;
        }

        if (! is_array($entry) || ($entry['receipt'] ?? null) !== $receipt) {
            report(new RuntimeException("Erasure ledger entry {$path} does not name its own receipt."));

            return null;
        }

        return $entry;
    }

    /**
     * To a temporary file, flushed to disk, then renamed into place, and the
     * directory flushed too: a crash leaves the old file or the new one, never
     * part of either.
     *
     * @param  array<string, mixed>  $contents
     */
    private function write(string $name, array $contents): void
    {
        $this->ensureDirectory();

        $json = json_encode($contents, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $temporary = $this->path().'/.'.$name.'.'.bin2hex(random_bytes(6)).'.tmp';
        $handle = @fopen($temporary, 'xb');

        if (! is_resource($handle)) {
            throw new RuntimeException("Could not write to the erasure ledger at {$this->path()}.");
        }

        try {
            $remaining = $json;

            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);

                if ($written === false || $written === 0) {
                    throw new RuntimeException("Could not write to the erasure ledger at {$this->path()}.");
                }

                $remaining = substr($remaining, $written);
            }

            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException("Could not flush the erasure ledger at {$this->path()} to disk.");
            }

            fclose($handle);
            $handle = null;

            if (! @rename($temporary, $this->path().'/'.$name)) {
                throw new RuntimeException("Could not write to the erasure ledger at {$this->path()}.");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        $this->syncDirectory();
    }

    private function ensureDirectory(): void
    {
        $directory = $this->path();

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create the erasure ledger directory {$directory}.");
        }
    }

    /**
     * A rename is durable only once the directory that holds it is. A
     * platform that cannot open a directory as a file has no such flush to
     * make; one that can and fails is an error.
     */
    private function syncDirectory(): void
    {
        $handle = @fopen($this->path(), 'r');

        if (! is_resource($handle)) {
            return;
        }

        try {
            if (! @fsync($handle)) {
                throw new RuntimeException("Could not flush the erasure ledger directory {$this->path()} to disk.");
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param callable(): void $work */
    private function exclusively(callable $work): void
    {
        $this->ensureDirectory();
        $lock = @fopen($this->path().'/.lock', 'c');

        if (! is_resource($lock) || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException("Could not lock the erasure ledger at {$this->path()}.");
        }

        try {
            $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $entry */
    private function receiptOf(array $entry): string
    {
        $receipt = (string) ($entry['receipt'] ?? '');
        $this->assertReceipt($receipt);

        return $receipt;
    }

    private function assertReceipt(string $receipt): void
    {
        if (preg_match('/^'.self::RECEIPT.'$/', $receipt) !== 1) {
            throw new RuntimeException('An erasure receipt must be a UUID.');
        }
    }

    private function pendingPath(string $receipt): string
    {
        return $this->path().'/'.$receipt.'.pending.json';
    }

    private function committedPath(string $receipt): string
    {
        return $this->path().'/'.$receipt.'.json';
    }
}
