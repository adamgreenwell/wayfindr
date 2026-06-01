<?php

namespace App\Models;

use Database\Factories\TicketLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['account_id', 'name', 'slug'])]
class TicketLabel extends Model
{
    /** @use HasFactory<TicketLabelFactory> */
    use HasFactory;

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_label_ticket')
            ->withTimestamps();
    }
}
