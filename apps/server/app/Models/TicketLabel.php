<?php

namespace App\Models;

use Database\Factories\TicketLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['account_id', 'name', 'slug'])]
class TicketLabel extends Model
{
    /** @use HasFactory<TicketLabelFactory> */
    use HasFactory;

    public const RESERVED_SLUGS = ['all'];

    public static function normalizeName(string $labelName): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $labelName)), 0, 64);
    }

    public static function slugForName(string $labelName): string
    {
        return Str::slug(self::normalizeName($labelName));
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array($slug, self::RESERVED_SLUGS, true);
    }

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
