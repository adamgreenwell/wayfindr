<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Content-free, conversation-owned terminal cache. Server-managed only. */
final class AiAssistPreparationReceipt extends Model
{
    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'retry_not_before' => 'immutable_datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }
}
