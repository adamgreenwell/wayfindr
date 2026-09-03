<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OidcConnection;
use App\Models\OidcIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OidcIdentity> */
final class OidcIdentityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'oidc_connection_id' => OidcConnection::factory(),
            'user_id' => User::factory(),
            'subject' => (string) Str::uuid(),
        ];
    }
}
