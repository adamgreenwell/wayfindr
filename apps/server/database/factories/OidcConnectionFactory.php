<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\OidcConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OidcConnection> */
final class OidcConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'public_id' => (string) Str::uuid(),
            'configuration_version' => (string) Str::uuid(),
            'name' => 'Company SSO',
            'issuer_url' => 'https://id.example.com',
            'client_id' => 'wayfindr',
            'client_secret' => Str::random(40),
            'is_enabled' => true,
        ];
    }
}
