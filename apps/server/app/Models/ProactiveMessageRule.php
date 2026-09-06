<?php

namespace App\Models;

use Database\Factories\ProactiveMessageRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'site_id',
    'name',
    'message',
    'url_contains',
    'referrer_contains',
    'delay_seconds',
    'minimum_visit_count',
    'requires_available_agent',
    'frequency_cap_minutes',
    'dismissal_snooze_minutes',
    'position',
    'is_enabled',
])]
class ProactiveMessageRule extends Model
{
    /** @use HasFactory<ProactiveMessageRuleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $rule): void {
            $rule->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'delay_seconds' => 'integer',
            'minimum_visit_count' => 'integer',
            'requires_available_agent' => 'boolean',
            'frequency_cap_minutes' => 'integer',
            'dismissal_snooze_minutes' => 'integer',
            'position' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ProactiveMessageDelivery::class);
    }

    /**
     * Public configuration evaluated in the browser. No observed page or
     * referrer comes back with it; those comparisons stay on the page.
     *
     * @return array<string, bool|int|string|null>
     */
    public function toWidgetPayload(): array
    {
        return [
            'id' => $this->public_id,
            'message' => $this->message,
            'url_contains' => $this->url_contains,
            'referrer_contains' => $this->referrer_contains,
            'delay_seconds' => $this->delay_seconds,
            'minimum_visit_count' => $this->minimum_visit_count,
            'requires_available_agent' => $this->requires_available_agent,
            'frequency_cap_minutes' => $this->frequency_cap_minutes,
            'dismissal_snooze_minutes' => $this->dismissal_snooze_minutes,
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeInEvaluationOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
