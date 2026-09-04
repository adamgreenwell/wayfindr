<?php

namespace Database\Factories;

use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\AutomationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->words(3, true),
            'event' => AutomationRuleEvent::TicketCreated,
            'conditions' => [],
            'actions' => [],
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
