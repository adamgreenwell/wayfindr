<?php

namespace Database\Factories;

use App\Enums\AutomationMacroSubjectType;
use App\Models\Account;
use App\Models\AutomationMacro;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationMacro>
 */
class AutomationMacroFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(3, true),
            'subject_type' => AutomationMacroSubjectType::Ticket,
            'actions' => [['type' => 'set_priority', 'value' => 'normal']],
            'position' => 0,
            'is_enabled' => false,
        ];
    }

    public function enabled(): static
    {
        return $this->state(fn (): array => [
            'is_enabled' => true,
        ]);
    }
}
