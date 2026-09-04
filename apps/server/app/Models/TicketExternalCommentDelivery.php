<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id',
    'account_id',
    'site_id',
    'ticket_id',
    'ticket_external_link_id',
    'provider_connection_id',
    'actor_id',
    'note_audit_event_id',
    'body',
    'attempts',
    'started_at',
    'accepted_at',
    'delivered_at',
    'failed_at',
    'remote_comment_id',
    'remote_url',
    'last_error',
])]
class TicketExternalCommentDelivery extends Model
{
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'remote_url' => 'encrypted',
            'started_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function externalLink(): BelongsTo
    {
        return $this->belongsTo(TicketExternalLink::class, 'ticket_external_link_id');
    }

    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ExternalIssueProviderConnection::class, 'provider_connection_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeAwaitingDispatch(Builder $query): Builder
    {
        return $query
            ->whereNull('delivered_at')
            ->whereNull('failed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('started_at')
                    // Provider acceptance is already durable. Re-run only the
                    // idempotent local completion; handle() never POSTs again.
                    ->orWhereNotNull('accepted_at');
            });
    }
}
