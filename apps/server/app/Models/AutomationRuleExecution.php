<?php

namespace App\Models;

use App\Casts\OrderedJsonList;
use App\Enums\AutomationExecutionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'account_id',
    'automation_rule_id',
    'automation_macro_id',
    'triggered_by_user_id',
    'subject_type',
    'subject_id',
    'rule_name',
    'event',
    'status',
    'conditions',
    'actions',
    'action_results',
    'metadata',
    'error_message',
    'started_at',
    'completed_at',
])]
class AutomationRuleExecution extends Model
{
    protected function casts(): array
    {
        return [
            'conditions' => OrderedJsonList::class,
            'actions' => OrderedJsonList::class,
            'action_results' => OrderedJsonList::class,
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected function status(): Attribute
    {
        return Attribute::set(
            fn (AutomationExecutionStatus|string $status): string => $status instanceof AutomationExecutionStatus
                ? $status->value
                : AutomationExecutionStatus::from($status)->value,
        );
    }

    public function statusEnum(): AutomationExecutionStatus
    {
        return AutomationExecutionStatus::from((string) $this->status);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function macro(): BelongsTo
    {
        return $this->belongsTo(AutomationMacro::class, 'automation_macro_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
