<?php

namespace Database\Factories;

use App\Models\ProactiveMessageRule;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProactiveMessageRule>
 */
class ProactiveMessageRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->unique()->words(3, true),
            'message' => fake()->sentence(),
            'url_contains' => null,
            'referrer_contains' => null,
            'delay_seconds' => 30,
            'minimum_visit_count' => 1,
            'requires_available_agent' => true,
            'frequency_cap_minutes' => 7 * 24 * 60,
            'dismissal_snooze_minutes' => 30 * 24 * 60,
            'position' => 0,
            'is_enabled' => false,
        ];
    }
}
