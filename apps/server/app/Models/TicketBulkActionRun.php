<?php

namespace App\Models;

use App\Enums\TicketBulkAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'account_id',
    'triggered_by_user_id',
    'action',
    'value',
    'item_count',
    'changed_count',
    'changes',
    'return_query',
    'undone_at',
    'undone_by_user_id',
    'undo_result',
])]
class TicketBulkActionRun extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'item_count' => 'integer',
            'changed_count' => 'integer',
            'changes' => 'array',
            'return_query' => 'array',
            'undone_at' => 'datetime',
            'undo_result' => 'array',
        ];
    }

    protected function action(): Attribute
    {
        return Attribute::set(
            fn (TicketBulkAction|string $action): string => $action instanceof TicketBulkAction
                ? $action->value
                : TicketBulkAction::from($action)->value,
        );
    }

    public function actionEnum(): TicketBulkAction
    {
        return TicketBulkAction::from((string) $this->action);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by_user_id');
    }
}
