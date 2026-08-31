<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    /**
     * A fixture that sets one sighting means both.
     *
     * This factory makes a WIDGET visitor: it gives them an `anonymous_id`,
     * which is the browser's own session identifier and the same evidence the
     * backfill migration used to decide who had been on the website. So a test
     * saying `last_seen_at` is describing somebody on the site, which is what
     * it meant before mail and web were separated -- and copying it across
     * keeps every one of those tests meaning what it was written to mean,
     * including the ones that age it deliberately to make somebody quiet.
     *
     * A non-null value passed in is left alone. Passing NULL explicitly is not
     * distinguishable from the default here, so a fixture describing an email
     * correspondent -- contact with no browser behind it -- clears the column
     * after creating rather than through the factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Visitor $visitor): void {
            if ($visitor->last_web_seen_at === null && $visitor->last_seen_at !== null) {
                $visitor->last_web_seen_at = $visitor->last_seen_at;
            }
        });
    }

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'external_id' => null,
            'anonymous_id' => 'anon_'.Str::lower((string) Str::ulid()),
            'name' => null,
            'email' => null,
            'metadata' => [],
            'last_seen_at' => now(),
            'last_web_seen_at' => null,
        ];
    }
}
