<?php

namespace App\Models;

use Database\Factories\CobrowseSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'conversation_id',
    'site_id',
    'visitor_id',
    'requested_by_id',
    'status',
    'metadata',
    'consented_at',
    'ended_at',
])]
class CobrowseSession extends Model
{
    /** @use HasFactory<CobrowseSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'consented_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'subject');
    }

    /**
     * @param  callable(self): void  $callback
     */
    public function updateAtomically(callable $callback): self
    {
        return DB::transaction(function () use ($callback): self {
            $session = self::query()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $callback($session);
            $session->save();

            return $session;
        });
    }

    /**
     * @param  callable(array<string, mixed>, self): array<string, mixed>  $callback
     */
    public function updateMetadataAtomically(callable $callback): self
    {
        return $this->updateAtomically(function (self $session) use ($callback): void {
            $session->forceFill([
                'metadata' => $callback($session->metadata ?? [], $session),
            ]);
        });
    }
}
