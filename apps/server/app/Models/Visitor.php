<?php

namespace App\Models;

use App\Models\Concerns\SanitisesStoredPageUrls;
use App\Support\Visitors\VisitorPresence;
use Database\Factories\VisitorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['site_id', 'external_id', 'anonymous_id', 'name', 'email', 'metadata', 'last_seen_at'])]
class Visitor extends Model
{
    use SanitisesStoredPageUrls;

    /**
     * @return array<int, string>
     */
    protected static function pageUrlPaths(): array
    {
        return ['last_page_url'];
    }

    /** @use HasFactory<VisitorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function requestedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'requester_id');
    }

    public function cobrowseSessions(): HasMany
    {
        return $this->hasMany(CobrowseSession::class);
    }

    public function sentConversationMessages(): MorphMany
    {
        return $this->morphMany(ConversationMessage::class, 'sender');
    }

    public function presenceState(): string
    {
        $state = VisitorPresence::stateFor($this->last_seen_at);

        // This surface has always called the null case "unknown" while the
        // queue filter calls it "not_reported". The name is in the realtime
        // presence payload and the views that read it, so it is translated
        // here rather than changed underneath them; the cutoffs, which are the
        // part that must not diverge, now live in one place.
        return $state === VisitorPresence::NOT_REPORTED ? 'unknown' : $state;
    }

    public function presenceLabel(): string
    {
        // English on purpose -- see `Conversation::attentionLabel()` for the
        // reasoning. A model can be reached outside a request, where no locale
        // has been scoped to a surface; extracted surfaces translate
        // `presenceState()` at their own call site instead.
        return match ($this->presenceState()) {
            'active' => 'Active recently',
            'recent' => 'Recently active',
            'quiet' => 'Quiet',
            default => 'Not reported',
        };
    }

    /**
     * How recently the visitor was seen, as a KEY plus the moment.
     *
     * Keys rather than sentences -- a model has no surface-scoped locale. The
     * English below stays for the surfaces that are not extracted.
     *
     * @return array{key: string, seen_at: CarbonInterface|null}
     */
    public function presenceCue(): array
    {
        if (! $this->last_seen_at) {
            return ['key' => 'no_heartbeat', 'seen_at' => null];
        }

        return $this->presenceState() === 'active'
            ? ['key' => 'seen_recently', 'seen_at' => null]
            : ['key' => 'seen_at', 'seen_at' => $this->last_seen_at];
    }

    public function presenceDetail(): string
    {
        if (! $this->last_seen_at) {
            return 'No visitor heartbeat yet.';
        }

        if ($this->presenceState() === 'active') {
            return 'Seen in the last 2 minutes';
        }

        return 'Seen '.$this->last_seen_at->diffForHumans();
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'actor');
    }
}
