<?php

namespace App\Models;

use App\Support\TicketCategory;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'account_id',
    'site_id',
    'conversation_id',
    'requester_id',
    'assignee_id',
    'status',
    'priority',
    'category',
    'subject',
    'description',
    'metadata',
    'closed_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'closed_at' => 'datetime',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function attentionState(): string
    {
        if ($this->status === 'closed') {
            return 'resolved';
        }

        $latestMessage = $this->latestConversationMessage();

        if ($this->status === 'pending' && $latestMessage?->sender_type !== Visitor::class) {
            return 'waiting_on_customer';
        }

        if (! $this->assignee_id) {
            return 'needs_owner';
        }

        if ($latestMessage) {
            return $latestMessage->sender_type === User::class
                ? 'waiting_on_customer'
                : 'needs_reply';
        }

        return 'needs_agent';
    }

    public function attentionLabel(): string
    {
        return match ($this->attentionState()) {
            'needs_reply' => 'Needs reply',
            'needs_owner' => 'Needs owner',
            'waiting_on_customer' => 'Waiting on customer',
            'resolved' => 'Resolved',
            default => 'Needs agent',
        };
    }

    public function attentionDescription(): string
    {
        return match ($this->attentionState()) {
            'needs_reply' => 'Visitor replied last.',
            'needs_owner' => 'Assign this ticket to keep it moving.',
            'waiting_on_customer' => $this->status === 'pending' ? 'Marked pending.' : 'Agent replied last.',
            'resolved' => 'Ticket is closed.',
            default => 'Ready for an agent update.',
        };
    }

    /**
     * @return array{title: string, body: string, cta: string, href: string}
     */
    public function nextAction(): array
    {
        return match ($this->attentionState()) {
            'needs_reply' => [
                'body' => 'Visitor replied last. Send a clear response, then mark the ticket pending or close it when the outcome is settled.',
                'cta' => 'Jump to reply',
                'href' => '#ticket-reply',
                'title' => 'Reply to visitor',
            ],
            'needs_owner' => [
                'body' => 'No agent owns this ticket yet. Assign someone before work gets lost.',
                'cta' => 'Assign ticket',
                'href' => '#ticket-actions-heading',
                'title' => 'Assign an owner',
            ],
            'waiting_on_customer' => [
                'body' => 'Agent replied last. Keep the ticket visible, then reopen the loop when the visitor answers.',
                'cta' => 'Review status actions',
                'href' => '#ticket-actions-heading',
                'title' => 'Wait on customer',
            ],
            'resolved' => [
                'body' => 'This ticket is closed. Reopen it only if the customer comes back or the outcome changes.',
                'cta' => 'Review status actions',
                'href' => '#ticket-actions-heading',
                'title' => 'Review resolution',
            ],
            default => [
                'body' => 'This ticket is assigned and ready for an agent update. Add a reply, internal note, or status change.',
                'cta' => 'Review actions',
                'href' => '#ticket-actions-heading',
                'title' => 'Add the next update',
            ],
        };
    }

    public function attentionSortRank(): int
    {
        return match ($this->attentionState()) {
            'needs_reply' => 10,
            'needs_owner' => 20,
            'needs_agent' => 30,
            'waiting_on_customer' => 70,
            'resolved' => 90,
            default => 50,
        };
    }

    private function latestConversationMessage(): ?ConversationMessage
    {
        return $this->conversation?->relationLoaded('latestMessage')
            ? $this->conversation->latestMessage
            : $this->conversation?->messages()
                ->latest('created_at')
                ->latest('id')
                ->first();
    }

    public function categoryLabel(): string
    {
        return TicketCategory::label($this->category);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TicketLabel::class, 'ticket_label_ticket')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'subject');
    }

    public function externalLinks(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class);
    }
}
