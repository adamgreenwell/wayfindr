<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountRole;
use App\Models\OidcConnection;
use App\Models\OidcRoleMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OidcRoleMapping> */
final class OidcRoleMappingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'oidc_connection_id' => OidcConnection::factory(),
            'claim_value' => fake()->unique()->word(),
            'built_in_role' => AccountRole::Agent,
            'custom_role_id' => null,
        ];
    }
}
