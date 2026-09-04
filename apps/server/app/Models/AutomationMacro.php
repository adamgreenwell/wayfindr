<?php

namespace App\Models;

use App\Casts\OrderedJsonList;
use App\Enums\AutomationMacroSubjectType;
use App\Support\Automation\AutomationRuleDefinition;
use Database\Factories\AutomationMacroFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable([
    'account_id',
    'name',
    'subject_type',
    'actions',
    'position',
    'is_enabled',
])]
class AutomationMacro extends Model
{
    /** @use HasFactory<AutomationMacroFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $macro): void {
            if ($macro->actions === []) {
                throw new InvalidArgumentException('Automation macros require at least one action.');
            }

            AutomationRuleDefinition::assertActionsForSubjectType(
                $macro->subjectTypeEnum(),
                $macro->actions,
            );
        });
    }

    protected function casts(): array
    {
        return [
            'actions' => OrderedJsonList::class,
            'position' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    protected function subjectType(): Attribute
    {
        return Attribute::set(
            fn (AutomationMacroSubjectType|string $type): string => $type instanceof AutomationMacroSubjectType
                ? $type->value
                : AutomationMacroSubjectType::from($type)->value,
        );
    }

    public function subjectTypeEnum(): AutomationMacroSubjectType
    {
        return AutomationMacroSubjectType::from((string) $this->subject_type);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationRuleExecution::class, 'automation_macro_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeForSubjectType(
        Builder $query,
        AutomationMacroSubjectType|string $subjectType,
    ): Builder {
        return $query->where(
            'subject_type',
            $subjectType instanceof AutomationMacroSubjectType
                ? $subjectType->value
                : AutomationMacroSubjectType::from($subjectType)->value,
        );
    }

    public function scopeInDisplayOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
