<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiAssistFeedback extends Model
{
    protected $table = 'ai_assist_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['viewed_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime'];
    }
}
