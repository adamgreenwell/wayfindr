<?php

namespace App\Models;

use Database\Factories\ConversationRatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a visitor said about how it went.
 *
 * The only measure in the product of whether support WORKED. Volume, first
 * response and resolution time all describe how fast it moved, and a desk can
 * improve every one of them while getting worse at helping people.
 */
#[Fillable(['conversation_id', 'site_id', 'score', 'comment', 'rated_at'])]
class ConversationRating extends Model
{
    /** @use HasFactory<ConversationRatingFactory> */
    use HasFactory;

    public const SCORES = ['good', 'ok', 'bad'];

    protected function casts(): array
    {
        return [
            'rated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
