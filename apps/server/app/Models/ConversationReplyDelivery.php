<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversation_message_id',
    'recipient',
    'message_id',
    'in_reply_to',
    'attempts',
    'last_attempted_at',
    'accepted_at',
    'failed_at',
])]
class ConversationReplyDelivery extends Model
{
    protected function casts(): array
    {
        return [
            // The destination is support data. Keep it behind the application
            // key just like provider credentials, rather than in plaintext in
            // a queue-support table or database export.
            'recipient' => 'encrypted',
            'last_attempted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingDispatch(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('failed_at');
    }
}
