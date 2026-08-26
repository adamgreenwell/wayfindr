<?php

use App\Support\Visitors\VisitorPageUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite page addresses already stored whole.
 *
 * The forward fix stops new query strings being kept. It does nothing about the
 * ones already in the table -- and those are the reason this matters: a reset
 * token stored last month is on an agent's screen the next time somebody opens
 * that visitor's profile.
 *
 * THE SAME URL LANDS IN THREE PLACES, and the first draft of this migration
 * only knew about one:
 *
 *   visitors.metadata.last_page_url                       where they are now
 *   conversations.metadata.started_page_url               where they asked from
 *   tickets.metadata.visitor_context.{last,started}_page_url
 *                                                         a snapshot, taken at
 *                                                         ticket creation and
 *                                                         durable afterwards
 *
 * The ticket copy is the one that would have survived everything else. It is a
 * point-in-time snapshot rather than a reference, so sanitising the sources it
 * was copied FROM does not reach it, and tickets outlive the conversations that
 * produced them.
 *
 * Runs itself rather than waiting for an operator to be told. An upgrade that
 * leaves credentials in a column until somebody reads a release note has not
 * fixed anything.
 *
 * `DB::table` and manual JSON rather than the Eloquent models: this runs against
 * whatever the schema is on the day it executes, and it must not inherit casts,
 * global scopes or accessors that may have moved on by then. No JSON-path SQL
 * either -- these are `json` columns and the expression for reaching into them
 * differs between SQLite and PostgreSQL, which is the driver split this repo has
 * been bitten by before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('visitors', ['last_page_url']);
        $this->rewrite('conversations', ['started_page_url']);
        $this->rewrite('tickets', ['visitor_context.last_page_url', 'visitor_context.started_page_url']);
    }

    /**
     * Irreversible on purpose.
     *
     * The query strings are gone, which is the point. A `down()` that pretended
     * otherwise would be a lie, and one that threw would block a rollback for
     * no reason.
     */
    public function down(): void {}

    /**
     * @param  array<int, string>  $paths  dot paths within the metadata column
     */
    private function rewrite(string $table, array $paths): void
    {
        DB::table($table)
            ->select('id', 'metadata')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table, $paths): void {
                foreach ($rows as $row) {
                    $metadata = $this->decode($row->metadata);

                    if ($metadata === null) {
                        continue;
                    }

                    $changed = false;

                    foreach ($paths as $path) {
                        if ($this->rewritePath($metadata, explode('.', $path))) {
                            $changed = true;
                        }
                    }

                    if (! $changed) {
                        continue;
                    }

                    // Timestamps untouched. This is us correcting our own
                    // storage, not anybody doing anything, and bumping
                    // `updated_at` would move rows in every list that orders by
                    // it -- including the ticket queue.
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $path
     */
    private function rewritePath(array &$metadata, array $path): bool
    {
        $key = array_shift($path);

        if (! array_key_exists($key, $metadata)) {
            return false;
        }

        if ($path !== []) {
            if (! is_array($metadata[$key])) {
                return false;
            }

            return $this->rewritePath($metadata[$key], $path);
        }

        $stored = $metadata[$key];

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        $sanitised = VisitorPageUrl::sanitise($stored);

        if ($sanitised === $stored) {
            return false;
        }

        $metadata[$key] = $sanitised;

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $metadata): ?array
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
};
