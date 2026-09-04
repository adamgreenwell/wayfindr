<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\CustomRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<CustomRole> */
final class CustomRoleFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Support lead '.fake()->unique()->numberBetween(1, 100000);

        return [
            'account_id' => Account::factory(),
            'name' => $name,
            'name_key' => Str::lower($name),
            'permissions' => [
                AccountPermission::ViewConversations->value,
                AccountPermission::ManageTickets->value,
            ],
        ];
    }
}
