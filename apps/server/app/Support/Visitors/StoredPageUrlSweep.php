<?php

declare(strict_types=1);

namespace App\Support\Visitors;

use Illuminate\Support\Facades\DB;

/**
 * Rewrite page addresses already stored whole.
 *
 * Idempotent and re-runnable on purpose, because it has to run TWICE and the
 * second run is the one that closes the hole.
 *
 * The zero-downtime deploy runs `migrate` BEFORE `$ACTIVATE_RELEASE()`, so
 * while this sweeps, the previous release is still serving widget traffic with
 * the old unsanitised writers. A visitor written after their chunk has been
 * passed keeps their query string indefinitely, and the migration reports
 * success. Sweeping again once the new code is actually serving is the only
 * thing that catches those.
 *
 * The same address is stored in three places and read from all three. The
 * ticket copy is the one that outlives everything else: it is a point-in-time
 * snapshot rather than a reference, so sanitising the sources it was copied
 * from never reaches it.
 *
 * `DB::table` and manual JSON rather than the Eloquent models: this runs from a
 * migration against whatever the schema is that day, and must not inherit
 * casts, scopes or accessors that have since moved on. No JSON-path SQL either
 * -- these are `json` columns and the expression for reaching into them differs
 * between SQLite and PostgreSQL.
 */
final class StoredPageUrlSweep
{
    /**
     * Every column holding a copy, and the dot paths within it.
     *
     * @var array<string, array<int, string>>
     */
    private const TARGETS = [
        'visitors' => ['last_page_url'],
        'conversations' => ['started_page_url'],
        'tickets' => ['visitor_context.last_page_url', 'visitor_context.started_page_url'],
    ];

    /**
     * @return array<string, int> rows rewritten, per table
     */
    public static function run(): array
    {
        $rewritten = [];

        foreach (self::TARGETS as $table => $paths) {
            $rewritten[$table] = self::sweep($table, $paths);
        }

        return $rewritten;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private static function sweep(string $table, array $paths): int
    {
        $count = 0;

        DB::table($table)
            ->select('id', 'metadata')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $paths, &$count): void {
                foreach ($rows as $row) {
                    $metadata = self::decode($row->metadata);

                    if ($metadata === null) {
                        continue;
                    }

                    $changed = false;

                    foreach ($paths as $path) {
                        if (self::rewritePath($metadata, explode('.', $path))) {
                            $changed = true;
                        }
                    }

                    if (! $changed) {
                        continue;
                    }

                    if (self::rewriteRow($table, (int) $row->id, $paths)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Re-read under a lock, and write what is there NOW.
     *
     * The chunk above decides which rows are worth touching. It cannot be the
     * thing that writes them: both passes run while requests are being served,
     * so between selecting a chunk and updating a row a widget can have written
     * that row's metadata -- a new page, a new context blob -- and replacing the
     * whole document from the earlier snapshot would silently discard it.
     *
     * A row-level lock and a fresh decode inside a transaction removes the
     * window rather than narrowing it. Not a compare-and-swap on the column:
     * PostgreSQL's `json` type has no equality operator, so `where('metadata',
     * $raw)` is not portable, and the cast that would make it work is not either.
     *
     * @param  array<int, string>  $paths
     */
    private static function rewriteRow(string $table, int $id, array $paths): bool
    {
        return (bool) DB::transaction(function () use ($table, $id, $paths): bool {
            $fresh = DB::table($table)->where('id', $id)->lockForUpdate()->first();

            if ($fresh === null) {
                // Deleted while we were working, which is a fine outcome.
                return false;
            }

            $metadata = self::decode($fresh->metadata);

            if ($metadata === null) {
                return false;
            }

            $changed = false;

            foreach ($paths as $path) {
                if (self::rewritePath($metadata, explode('.', $path))) {
                    $changed = true;
                }
            }

            if (! $changed) {
                // The value moved on and is already clean -- a sanitising
                // writer got there first. Nothing to do, and nothing lost.
                return false;
            }

            // Timestamps untouched. This is us correcting our own storage, not
            // anybody doing anything, and bumping `updated_at` would move rows
            // in every list that orders by it -- including the ticket queue.
            DB::table($table)
                ->where('id', $id)
                ->update(['metadata' => json_encode($metadata)]);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $path
     */
    private static function rewritePath(array &$metadata, array $path): bool
    {
        $key = array_shift($path);

        if (! array_key_exists($key, $metadata)) {
            return false;
        }

        if ($path !== []) {
            if (! is_array($metadata[$key])) {
                return false;
            }

            return self::rewritePath($metadata[$key], $path);
        }

        $stored = $metadata[$key];

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        // reduce(), not forSite(): the sweep has a row, not a site.
        $sanitised = VisitorPageUrl::reduce($stored);

        if ($sanitised === $stored) {
            return false;
        }

        $metadata[$key] = $sanitised;

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $metadata): ?array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return null;
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : null;
    }
}
