<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\TicketLabel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TicketLabel>
 */
class TicketLabelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'account_id' => Account::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
