<?php

namespace App\Models;

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
        if (! $this->last_seen_at) {
            return 'unknown';
        }

        if ($this->last_seen_at->gte(now()->subMinutes(2))) {
            return 'active';
        }

        if ($this->last_seen_at->gte(now()->subMinutes(15))) {
            return 'recent';
        }

        return 'quiet';
    }

    public function presenceLabel(): string
    {
        return match ($this->presenceState()) {
            'active' => 'Active recently',
            'recent' => 'Recently active',
            'quiet' => 'Quiet',
            default => 'Not reported',
        };
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
