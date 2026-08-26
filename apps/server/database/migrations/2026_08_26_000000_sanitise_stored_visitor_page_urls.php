<?php

use App\Support\Visitors\VisitorPageUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrite page addresses already stored whole.
 *
 * The forward fix stops new query strings being kept. It does nothing about the
 * ones already in the table -- and those are the reason this matters: a
 * reset token stored last month is on an agent's screen the next time somebody
 * opens that visitor's profile.
 *
 * Runs itself rather than waiting for an operator to be told. An upgrade that
 * leaves credentials in a column until somebody reads a release note has not
 * fixed anything.
 *
 * `DB::table` and manual JSON rather than the Eloquent model: this runs against
 * whatever the schema is on the day it executes, and it must not inherit casts,
 * global scopes or accessors that may have moved on by then. No JSON-path SQL
 * either -- `metadata` is a `json` column and the expression for reaching into
 * it differs between SQLite and PostgreSQL, which is the driver split this
 * repo has been bitten by before.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visitors')
            ->select('id', 'metadata')
            ->orderBy('id')
            ->chunkById(500, function ($visitors): void {
                foreach ($visitors as $visitor) {
                    $metadata = $this->decode($visitor->metadata);

                    if ($metadata === null) {
                        continue;
                    }

                    $stored = $metadata['last_page_url'] ?? null;

                    if (! is_string($stored) || $stored === '') {
                        continue;
                    }

                    $sanitised = VisitorPageUrl::sanitise($stored);

                    if ($sanitised === $stored) {
                        continue;
                    }

                    $metadata['last_page_url'] = $sanitised;

                    // Timestamps untouched. This is us correcting our own
                    // storage, not the visitor doing anything, and bumping
                    // `updated_at` would move rows in every list that orders by
                    // it.
                    DB::table('visitors')
                        ->where('id', $visitor->id)
                        ->update(['metadata' => json_encode($metadata)]);
                }
            });
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
