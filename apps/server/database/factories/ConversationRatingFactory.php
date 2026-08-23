<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationRating;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationRating>
 */
class ConversationRatingFactory extends Factory
{
    protected $model = ConversationRating::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'site_id' => Site::factory(),
            'score' => $this->faker->randomElement(ConversationRating::SCORES),
            'comment' => null,
            'rated_at' => now(),
            // Defaults to the same moment, so a factory-made rating lands in
            // the same reporting window a reader would expect it to. Override
            // it to place the CLOSE being answered somewhere else.
            'episode_closed_at' => now(),
            // Unique per rating by default, so a factory cannot accidentally
            // create two answers about the same close.
            'episode_event_id' => fake()->unique()->numberBetween(1, 1000000),
        ];
    }
}
