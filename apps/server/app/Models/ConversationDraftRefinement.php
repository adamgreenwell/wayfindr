<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One expiring private slot, never a shared artifact or durable draft history. */
final class ConversationDraftRefinement extends Model
{
    protected $guarded = [];

    protected $hidden = ['input', 'scope', 'preview'];

    protected function casts(): array
    {
        return ['input' => 'encrypted:array', 'scope' => 'encrypted:array', 'preview' => 'encrypted:array',
            'operation' => 'array', 'requested_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
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
