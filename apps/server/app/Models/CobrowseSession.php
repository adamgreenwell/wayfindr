<?php

namespace App\Models;

use App\Models\Concerns\SanitisesStoredPageUrls;
use Carbon\CarbonInterface;
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

    use SanitisesStoredPageUrls;

    /** The idle window shared by active reads and the scheduled expiry write. */
    public static function idleExpiryMinutes(): int
    {
        return max(1, (int) config('wayfindr.cobrowse.session_idle_expiry_minutes', 15));
    }

    public static function idleCutoff(): CarbonInterface
    {
        return now()->subMinutes(self::idleExpiryMinutes());
    }

    /**
     * Every place the three cobrowse writers put a page address.
     *
     * The last one is a LIST -- a run of recent mutation batches, each carrying
     * the address it was reported from -- which is why the trait grew a `*`
     * segment.
     *
     * A visitor who grants cobrowse has agreed to share the PAGE; that is the
     * whole feature and ADR 0005 gates it on exactly that agreement. Agreeing
     * to share a page is not agreeing to hand over the credential in its
     * address, and this path keeps addresses longer than any other in the
     * product: the content pruner strips the heavy payloads on schedule and
     * RETAINS the URLs by design, so an unsanitised one outlives everything
     * around it.
     *
     * @return array<int, string>
     */
    protected static function pageUrlPaths(): array
    {
        return [
            'page_state.page_url',
            'snapshot.page_url',
            'mutations.last_page_url',
            'mutations.recent_batches.*.page_url',
        ];
    }

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
