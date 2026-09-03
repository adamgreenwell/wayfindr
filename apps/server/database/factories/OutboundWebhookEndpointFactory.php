<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\OutboundWebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OutboundWebhookEndpoint> */
class OutboundWebhookEndpointFactory extends Factory
{
    protected $model = OutboundWebhookEndpoint::class;

    public function definition(): array
    {
        $secret = OutboundWebhookEndpoint::generateSecret();

        return [
            'account_id' => Account::factory(),
            'created_by_id' => null,
            'name' => $this->faker->words(2, true),
            'url' => 'https://hooks.example.test/wayfindr',
            'secret' => $secret['plain'],
            'secret_last_four' => $secret['last_four'],
            'events' => OutboundWebhookEndpoint::EVENTS,
            'restricts_sites' => true,
            'next_sequence' => 1,
            'disabled_at' => null,
        ];
    }
}
