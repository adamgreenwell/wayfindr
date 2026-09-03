<?php

namespace Database\Factories;

use App\Models\OutboundWebhookDelivery;
use App\Models\OutboundWebhookEndpoint;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OutboundWebhookDelivery> */
class OutboundWebhookDeliveryFactory extends Factory
{
    protected $model = OutboundWebhookDelivery::class;

    public function definition(): array
    {
        $publicId = (string) Str::uuid();

        return [
            'public_id' => $publicId,
            'outbound_webhook_endpoint_id' => OutboundWebhookEndpoint::factory(),
            'site_id' => function (array $attributes): int {
                $endpoint = OutboundWebhookEndpoint::query()->findOrFail($attributes['outbound_webhook_endpoint_id']);

                return Site::factory()->for($endpoint->account)->create()->id;
            },
            'event' => OutboundWebhookEndpoint::EVENT_TICKET_CREATED,
            'sequence' => 1,
            'payload' => [
                'id' => $publicId,
                'event' => OutboundWebhookEndpoint::EVENT_TICKET_CREATED,
                'sequence' => 1,
                'occurred_at' => now()->toJSON(),
                'site_id' => 1,
                'resource' => ['type' => 'ticket', 'id' => 1],
            ],
            'attempts' => 0,
        ];
    }
}
