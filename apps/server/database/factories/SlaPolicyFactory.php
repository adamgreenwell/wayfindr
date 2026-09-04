<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SlaPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlaPolicy> */
class SlaPolicyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'priority' => 'normal',
            'first_response_minutes' => 60,
            'resolution_minutes' => 480,
            'effective_at' => now(),
        ];
    }
}
