<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['agent_id', 'request_id', 'attempts', 'requested_at', 'last_attempted_at'])]
class AgentRealtimeEviction extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'agent_id' => 'integer',
            'attempts' => 'integer',
            'requested_at' => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }
}
