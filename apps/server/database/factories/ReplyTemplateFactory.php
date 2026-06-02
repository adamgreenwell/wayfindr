<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ReplyTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplyTemplate>
 */
class ReplyTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(3, true),
            'body' => fake()->paragraph(),
            'is_active' => true,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
