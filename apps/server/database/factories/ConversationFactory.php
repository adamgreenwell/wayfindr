<?php

namespace Database\Factories;

use App\Enums\ConversationStatus;
use App\Enums\TicketPriority;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'visitor_id' => fn (array $attributes) => Visitor::factory()
                ->create(['site_id' => $attributes['site_id']])
                ->id,
            'assigned_agent_id' => null,
            'support_code' => fake()->unique()->bothify('WF-######'),
            'status' => ConversationStatus::Open,
            'priority' => TicketPriority::Normal,
            'subject' => null,
            'metadata' => [],
            'last_message_at' => null,
            'closed_at' => null,
        ];
    }
}
