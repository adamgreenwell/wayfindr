<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A durable, unique handoff from one SLA stage to one recipient channel. */
#[Fillable([
    'public_id',
    'sla_clock_id',
    'user_id',
    'stage',
    'channel',
    'attempts',
    'claimed_at',
    'started_at',
    'last_attempted_at',
    'accepted_at',
    'deduplicated_at',
    'failed_at',
    'cancelled_at',
])]
class SlaAlertDelivery extends Model
{
    public const CLAIM_LEASE_MINUTES = 5;

    public const FAILED_RETRY_AFTER_MINUTES = 60;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'deduplicated_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function clock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class, 'sla_clock_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingDispatch(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('deduplicated_at')
            // Once SMTP begins, its outcome is ambiguous without a receipt.
            // Never turn that uncertainty into an automatic duplicate.
            ->whereNull('started_at')
            ->whereNull('cancelled_at')
            ->where(function (Builder $query): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', now()->subMinutes(self::CLAIM_LEASE_MINUTES));
            })
            ->where(function (Builder $query): void {
                $query->whereNull('failed_at')
                    ->orWhere('failed_at', '<=', now()->subMinutes(self::FAILED_RETRY_AFTER_MINUTES));
            });
    }
}
