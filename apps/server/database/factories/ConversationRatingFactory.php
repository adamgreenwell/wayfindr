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
        ];
    }
}
