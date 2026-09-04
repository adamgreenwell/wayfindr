<?php

namespace App\Models;

use App\Casts\OrderedJsonList;
use App\Enums\AutomationRuleEvent;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'name',
    'event',
    'conditions',
    'actions',
    'position',
    'is_enabled',
])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'conditions' => OrderedJsonList::class,
            'actions' => OrderedJsonList::class,
            'position' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    protected function event(): Attribute
    {
        return Attribute::set(
            fn (AutomationRuleEvent|string $event): string => $event instanceof AutomationRuleEvent
                ? $event->value
                : AutomationRuleEvent::from($event)->value,
        );
    }

    public function eventEnum(): AutomationRuleEvent
    {
        return AutomationRuleEvent::from((string) $this->event);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForEvent(Builder $query, AutomationRuleEvent|string $event): Builder
    {
        return $query->where(
            'event',
            $event instanceof AutomationRuleEvent ? $event->value : AutomationRuleEvent::from($event)->value,
        );
    }

    public function scopeInEvaluationOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
