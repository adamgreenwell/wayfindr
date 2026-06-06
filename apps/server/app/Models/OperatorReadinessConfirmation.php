<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'key',
    'confirmed_by_id',
    'confirmed_at',
    'note',
])]
class OperatorReadinessConfirmation extends Model
{
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
        ];
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }
}
