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
use Illuminate\Support\Carbon;

#[Fillable(['site_id', 'external_id', 'anonymous_id', 'name', 'email', 'metadata', 'last_seen_at', 'current_visit_started_at', 'presence_only'])]
class Visitor extends Model
{
    use SanitisesStoredPageUrls;

    /**
     * Keep the current visit's start honest, whoever touches `last_seen_at`.
     *
     * Presence is not the only writer: bootstrap, conversation start, message
     * fetch and typing all stamp `last_seen_at`, and any of them can land
     * before the first heartbeat of a returning visitor. If the rule lived only
     * in the presence recorder, a visitor who opens the panel before their
     * heartbeat arrives would have `last_seen_at` refreshed first -- and the
     * heartbeat would then see a RECENT timestamp, keep the previous visit's
     * start, and report a visit spanning days.
     *
     * So the transition is computed here, from the value being replaced, and it
     * is true for every writer including ones nobody has written yet. Same
     * reasoning as the page-URL hook above: a rule that depends on one caller
     * winning a race is not a rule.
     */
    protected static function booted(): void
    {
        static::saving(function (Visitor $visitor): void {
            if (! $visitor->isDirty('last_seen_at') || $visitor->last_seen_at === null) {
                return;
            }

            $previous = $visitor->getOriginal('last_seen_at');
            $previous = $previous === null ? null : Carbon::parse($previous);

            // No previous sighting, or none recorded for the visit: this
            // report starts one. The first clause is load-bearing -- an opening
            // heartbeat has nothing to be "older than", so a rule written only
            // around the gap would never start a visit at all.
            if ($previous === null || $visitor->current_visit_started_at === null) {
                $visitor->current_visit_started_at = $visitor->last_seen_at;

                return;
            }

            // A gap long enough to read as `quiet` is long enough to be a new
            // visit, which reuses a cutoff the product already has rather than
            // inventing a session length.
            if ($previous->lt($visitor->last_seen_at->copy()->subMinutes(VisitorPresence::RECENT_MINUTES))) {
                $visitor->current_visit_started_at = $visitor->last_seen_at;
            }
        });
    }

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
            'current_visit_started_at' => 'datetime',
            'presence_only' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Tickets raised by this visitor.
     *
     * Exists for the presence pruner, which must never delete a visitor who has
     * made contact: `tickets.requester_id` is `nullOnDelete`, so removing one
     * silently detaches their tickets from whoever raised them rather than
     * failing loudly.
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'requester_id');
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
