<?php

namespace App\Models;

use App\Enums\ConversationBulkAction;
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
class ConversationBulkActionRun extends Model
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
            fn (ConversationBulkAction|string $action): string => $action instanceof ConversationBulkAction
                ? $action->value
                : ConversationBulkAction::from($action)->value,
        );
    }

    public function actionEnum(): ConversationBulkAction
    {
        return ConversationBulkAction::from((string) $this->action);
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
