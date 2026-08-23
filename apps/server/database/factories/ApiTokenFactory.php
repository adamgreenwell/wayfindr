<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    protected $model = ApiToken::class;

    public function definition(): array
    {
        $generated = ApiToken::generate();

        return [
            'account_id' => Account::factory(),
            'created_by_id' => null,
            'name' => $this->faker->words(2, true),
            'token_hash' => $generated['hash'],
            'last_four' => $generated['last_four'],
            'abilities' => [ApiToken::ABILITY_READ],
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
