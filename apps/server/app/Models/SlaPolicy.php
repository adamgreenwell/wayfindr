<?php

namespace App\Models;

use Database\Factories\SlaPolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'priority',
    'first_response_minutes',
    'resolution_minutes',
    'effective_at',
])]
class SlaPolicy extends Model
{
    /** @use HasFactory<SlaPolicyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'first_response_minutes' => 'integer',
            'resolution_minutes' => 'integer',
            'effective_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function targetMinutes(string $metric): ?int
    {
        $value = match ($metric) {
            SlaClock::METRIC_FIRST_RESPONSE => $this->first_response_minutes,
            SlaClock::METRIC_RESOLUTION => $this->resolution_minutes,
            default => null,
        };

        return is_int($value) && $value > 0 ? $value : null;
    }
}
