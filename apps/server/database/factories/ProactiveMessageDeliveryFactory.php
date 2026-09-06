<?php

namespace Database\Factories;

use App\Models\ProactiveMessageDelivery;
use App\Models\ProactiveMessageRule;
use App\Models\Site;
use App\Models\Visitor;
use App\Support\ProactiveMessages\ProactiveVisitorKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProactiveMessageDelivery>
 */
class ProactiveMessageDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'proactive_message_rule_id' => fn (array $attributes): int => ProactiveMessageRule::factory()->create([
                'site_id' => $attributes['site_id'],
            ])->id,
            'visitor_id' => fn (array $attributes): int => Visitor::factory()->create([
                'site_id' => $attributes['site_id'],
            ])->id,
            'public_id' => (string) Str::uuid(),
            'rule_public_id' => fn (array $attributes): string => ProactiveMessageRule::query()
                ->findOrFail($attributes['proactive_message_rule_id'])
                ->public_id,
            'visitor_key' => fn (array $attributes): string => ProactiveVisitorKey::for(
                (int) $attributes['site_id'],
                (string) Visitor::query()->findOrFail($attributes['visitor_id'])->anonymous_id,
            ),
            'claim_key' => 'wf-'.Str::uuid(),
            'message' => fake()->sentence(),
            'claimed_at' => now(),
            'expires_at' => now()->addMinutes(ProactiveMessageDelivery::CLAIM_MINUTES),
        ];
    }
}
