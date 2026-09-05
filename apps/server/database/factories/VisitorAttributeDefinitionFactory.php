<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\VisitorAttributeType;
use App\Models\Account;
use App\Models\VisitorAttributeDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisitorAttributeDefinition> */
final class VisitorAttributeDefinitionFactory extends Factory
{
    public function definition(): array
    {
        $key = 'attribute_'.fake()->unique()->numberBetween(1, 100000);

        return [
            'account_id' => Account::factory(),
            'key' => $key,
            'label' => str($key)->replace('_', ' ')->headline()->toString(),
            'type' => VisitorAttributeType::Text,
        ];
    }
}
