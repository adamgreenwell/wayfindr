<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One erased visitor, as the ledger records it (ADR 0026 §8, §9): internal
 * identifiers, the actor, counts and a receipt reference. Never a name,
 * email, browser ID or any of the content that was erased.
 */
#[Fillable(['public_id', 'account_id', 'site_id', 'site_public_key', 'erased_visitor_id', 'merged_visitor_ids', 'actor_id', 'counts', 'pending_files', 'erased_at'])]
final class VisitorErasure extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'merged_visitor_ids' => 'array',
            'counts' => 'array',
            'pending_files' => 'array',
            'erased_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
