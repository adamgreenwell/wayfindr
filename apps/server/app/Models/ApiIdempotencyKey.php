<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'api_token_id',
    'key_hash',
    'request_hash',
    'resource_type',
    'resource_id',
    'expires_at',
])]
class ApiIdempotencyKey extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiToken::class);
    }
}
