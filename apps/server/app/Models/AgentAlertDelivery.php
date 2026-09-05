<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One claimed or accepted off-dashboard delivery of an exact alert version. */
#[Fillable([
    'notification_id',
    'alert_version',
    'state_key',
    'channel',
    'claim_token',
    'started_at',
    'accepted_at',
])]
final class AgentAlertDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
