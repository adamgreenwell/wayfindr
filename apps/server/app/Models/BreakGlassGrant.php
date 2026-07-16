<?php

namespace App\Models;

use Database\Factories\BreakGlassGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A break-glass grant (ADR 0008): the ONLY way a platform operator reaches
 * customer support content. Scoped to exactly one conversation, site, or
 * account; reasoned; time-bound (expires_at stamped at approval, never
 * extended); read-only by policy. Coverage checks re-derive the account per
 * resource, so an in-scope check can never cross accounts.
 */
#[Fillable([
    'account_id',
    'scope_type',
    'conversation_id',
    'site_id',
    'requester_id',
    'reason',
    'status',
    'approver_id',
    'self_approved',
    'requested_minutes',
    'approved_at',
    'expires_at',
    'closed_at',
])]
class BreakGlassGrant extends Model
{
    /** @use HasFactory<BreakGlassGrantFactory> */
    use HasFactory;

    public const SCOPE_CONVERSATION = 'conversation';

    public const SCOPE_SITE = 'site';

    public const SCOPE_ACCOUNT = 'account';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_EXPIRED = 'expired';

    public const DEFAULT_MINUTES = 60;

    public const MAX_MINUTES = 1440;

    protected function casts(): array
    {
        return [
            'self_approved' => 'boolean',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'subject');
    }

    /**
     * Active means approved AND not yet expired or closed — expiry is checked
     * live, so a grant past its expires_at opens nothing even before the
     * scheduled sweep stamps it.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<BreakGlassGrant>  $query
     * @return Builder<BreakGlassGrant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now());
    }

    /**
     * Coverage re-derives ownership from the RESOURCE side on every check: a
     * conversation is covered only when its own site/account chain lands
     * inside this grant's scope. A grant can therefore never cover anything
     * outside its account, whatever its scope columns claim.
     */
    public function coversConversation(Conversation $conversation): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $conversation->loadMissing('site');

        if ((int) $conversation->site?->account_id !== (int) $this->account_id) {
            return false;
        }

        return match ($this->scope_type) {
            self::SCOPE_CONVERSATION => (int) $conversation->id === (int) $this->conversation_id,
            self::SCOPE_SITE => (int) $conversation->site_id === (int) $this->site_id,
            self::SCOPE_ACCOUNT => true,
            default => false,
        };
    }

    public function coversTicket(Ticket $ticket): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        // Re-derive ownership through the ticket's SITE as well as its
        // denormalized account_id — a mismatched row (claiming one account
        // while its site belongs to another) is covered by neither.
        $ticket->loadMissing('site');

        if ((int) $ticket->account_id !== (int) $this->account_id
            || (int) $ticket->site?->account_id !== (int) $this->account_id) {
            return false;
        }

        return match ($this->scope_type) {
            // A conversation-scoped grant covers the tickets that belong to
            // that conversation — they are its support artifacts. The ticket
            // must sit on the conversation's own site too: a row from another
            // site claiming the covered conversation is not covered.
            self::SCOPE_CONVERSATION => $ticket->conversation_id !== null
                && (int) $ticket->conversation_id === (int) $this->conversation_id
                && (int) $ticket->site_id === (int) $this->site_id,
            self::SCOPE_SITE => (int) $ticket->site_id === (int) $this->site_id,
            self::SCOPE_ACCOUNT => true,
            default => false,
        };
    }

    public function coversSite(Site $site): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ((int) $site->account_id !== (int) $this->account_id) {
            return false;
        }

        return match ($this->scope_type) {
            self::SCOPE_SITE => (int) $site->id === (int) $this->site_id,
            self::SCOPE_ACCOUNT => true,
            default => false,
        };
    }

    /**
     * A short human status for the operator console and the account-visible
     * record. An overdue-but-unswept active grant already reads as expired.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_REQUESTED => 'Awaiting approval',
            self::STATUS_ACTIVE => $this->isActive() ? 'Active' : 'Expired',
            self::STATUS_DENIED => 'Denied',
            self::STATUS_CLOSED => 'Closed early',
            self::STATUS_EXPIRED => 'Expired',
            default => $this->status,
        };
    }

    /**
     * A short human label for audit trails and the account-visible record.
     * The referenced resource is only NAMED when it actually belongs to the
     * grant's account — a mismatched row must not leak another account's
     * support code or site name into pages or audit metadata.
     */
    public function scopeLabel(): string
    {
        return match ($this->scope_type) {
            self::SCOPE_CONVERSATION => 'Conversation '.$this->conversationReference(),
            self::SCOPE_SITE => 'Site '.$this->siteReference(),
            self::SCOPE_ACCOUNT => 'Entire account',
            default => $this->scope_type,
        };
    }

    private function conversationReference(): string
    {
        if ($this->conversation_id === null) {
            return '(deleted)';
        }

        $conversation = $this->conversation?->loadMissing('site');

        if (! $conversation || (int) $conversation->site?->account_id !== (int) $this->account_id) {
            return '(out of scope)';
        }

        return (string) $conversation->support_code;
    }

    private function siteReference(): string
    {
        if ($this->site_id === null) {
            return '(deleted)';
        }

        if (! $this->site || (int) $this->site->account_id !== (int) $this->account_id) {
            return '(out of scope)';
        }

        return (string) $this->site->name;
    }
}
