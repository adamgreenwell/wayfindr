<?php

namespace App\Models;

use Database\Factories\OutboundWebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboundWebhookDelivery extends Model
{
    /** @use HasFactory<OutboundWebhookDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'outbound_webhook_endpoint_id',
        'site_id',
        'event',
        'sequence',
        'payload',
        'attempts',
        'last_attempted_at',
        'response_status',
        'response_body',
        'last_error',
        'delivered_at',
        'failed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            // A subscriber response may contain its own operational details.
            // Keep it behind the app key like destinations and provider keys.
            'response_body' => 'encrypted',
            'last_attempted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(OutboundWebhookEndpoint::class, 'outbound_webhook_endpoint_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeAwaitingDispatch(Builder $query): Builder
    {
        return $query
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->whereNull('cancelled_at')
            ->whereHas('endpoint', fn (Builder $endpoint): Builder => $endpoint->whereNull('disabled_at'));
    }

    public function isRetrying(): bool
    {
        return $this->attempts > 0
            && $this->delivered_at === null
            && $this->failed_at === null
            && $this->cancelled_at === null;
    }
}
