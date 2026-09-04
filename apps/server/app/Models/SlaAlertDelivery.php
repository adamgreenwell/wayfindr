<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
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
    'last_attempted_at',
    'accepted_at',
    'failed_at',
])]
class SlaAlertDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
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
}
