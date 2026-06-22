<?php

namespace App\Models;

use App\Support\TicketCategory;
use Carbon\CarbonInterface;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

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
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null, reply_visibility: array{label: string, detail: string, tone: string}|null}
     */
    public function queueActivityPreview(): array
    {
        $latestMessage = $this->latestConversationMessage();

        if ($latestMessage) {
            return [
                'body' => $this->activityPreviewSnippet($latestMessage->body) ?: 'Message has no text preview.',
                'label' => $this->activityPreviewLabel($latestMessage),
                'occurred_at' => $latestMessage->created_at,
                'reply_visibility' => $latestMessage->sender_type === User::class
                    ? $this->replyVisibility()
                    : null,
            ];
        }

        $description = $this->activityPreviewSnippet($this->description);

        if ($description !== '') {
            return [
                'body' => $description,
                'label' => 'Ticket summary',
                'occurred_at' => $this->created_at,
                'reply_visibility' => null,
            ];
        }

        return [
            'body' => 'Open the ticket to add context or send the next update.',
            'label' => 'No activity preview yet',
            'occurred_at' => null,
            'reply_visibility' => null,
        ];
    }

    /**
     * @return array{label: string, detail: string, tone: string}
     */
    public function replyVisibility(): array
    {
        $conversation = $this->relationLoaded('conversation')
            ? $this->conversation
            : $this->conversation()->first();

        if (! $conversation) {
            return [
                'detail' => 'Reply visibility starts once this ticket is connected to a conversation.',
                'label' => 'No linked conversation',
                'tone' => 'manual',
            ];
        }

        return [
            'detail' => $conversation->visitorReadDetail(),
            'label' => $conversation->visitorReadLabel(),
            'tone' => match ($conversation->visitorReadState()) {
                'seen' => 'ready',
                'unseen' => 'attention',
                default => 'manual',
            },
        ];
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

    /**
     * @return array{title: string, detail: string, cta: string, href: string, tone: string}
     */
    public function statusActionReadiness(): array
    {
        $latestMessage = $this->latestConversationMessage();

        if ($this->status !== 'closed' && $latestMessage?->sender_type === Visitor::class) {
            return [
                'cta' => 'Jump to reply',
                'detail' => 'Visitor replied last. Closing now may leave the customer waiting. Use pending or close only after an agent update or a confirmed outcome.',
                'href' => '#ticket-reply',
                'title' => 'Reply before closing',
                'tone' => 'attention',
            ];
        }

        return match ($this->attentionState()) {
            'needs_reply' => [
                'cta' => 'Jump to reply',
                'detail' => 'Visitor replied last. Closing now may leave the customer waiting. Use pending or close only after an agent update or a confirmed outcome.',
                'href' => '#ticket-reply',
                'title' => 'Reply before closing',
                'tone' => 'attention',
            ],
            'needs_owner' => [
                'cta' => 'Assign ticket',
                'detail' => 'Assign an owner before changing status so follow-up does not drift.',
                'href' => '#assignee_id',
                'title' => 'Assign before status changes',
                'tone' => 'manual',
            ],
            'waiting_on_customer' => $this->status === 'pending'
                ? [
                    'cta' => 'Review reopen option',
                    'detail' => 'This ticket is pending. Reopen it when the visitor answers or new work is needed.',
                    'href' => '#reopen_note',
                    'title' => 'Pending ticket',
                    'tone' => 'manual',
                ]
                : [
                    'cta' => 'Review status actions',
                    'detail' => 'Agent replied last. Mark pending if you are waiting on the visitor, or close once the outcome is settled.',
                    'href' => '#ticket-actions-heading',
                    'title' => 'Lifecycle options are calm',
                    'tone' => 'ready',
                ],
            'resolved' => [
                'cta' => 'Review reopen option',
                'detail' => 'Reopen only if the customer comes back or the outcome changes. Use the reopen note to leave the next agent enough context.',
                'href' => '#reopen_note',
                'title' => 'Closed ticket',
                'tone' => 'manual',
            ],
            default => [
                'cta' => 'Review status actions',
                'detail' => 'Add the next update, internal note, pending state, or close once the outcome is clear.',
                'href' => '#ticket-actions-heading',
                'title' => 'Ready for lifecycle update',
                'tone' => 'manual',
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

    /**
     * @return array{opened_label: string, wait_label: string}
     */
    public function queueTimingContext(): array
    {
        $latestMessage = $this->latestConversationMessage();

        return [
            'opened_label' => 'Opened '.$this->created_at->diffForHumans(),
            'wait_label' => $this->queueWaitLabel($latestMessage),
        ];
    }

    private function queueWaitLabel(?ConversationMessage $latestMessage): string
    {
        $attentionState = $this->attentionState();

        if ($attentionState === 'resolved') {
            return 'Closed '.($this->closed_at ?? $this->updated_at)->diffForHumans();
        }

        if ($attentionState === 'needs_owner') {
            return 'Waiting on owner for '.$this->elapsedQueueTime($latestMessage?->created_at ?? $this->created_at);
        }

        if ($latestMessage?->created_at) {
            $elapsed = $this->elapsedQueueTime($latestMessage->created_at);

            return match ($latestMessage->sender_type) {
                Visitor::class => 'Waiting on reply for '.$elapsed,
                User::class => 'Waiting on customer for '.$elapsed,
                default => 'Waiting on update for '.$elapsed,
            };
        }

        return match ($attentionState) {
            'waiting_on_customer' => 'Waiting on customer since ticket opened',
            default => 'Waiting on agent update since ticket opened',
        };
    }

    private function elapsedQueueTime(CarbonInterface $since): string
    {
        return $since->diffForHumans([
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 1,
        ]);
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

    private function activityPreviewLabel(ConversationMessage $message): string
    {
        return match ($message->sender_type) {
            Visitor::class => 'Visitor message',
            User::class => 'Agent reply',
            default => 'Latest message',
        };
    }

    private function activityPreviewSnippet(?string $body): string
    {
        $body = (string) Str::of((string) $body)->squish();

        return Str::limit($body, 150);
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

    public function latestEscalationEvent(): MorphOne
    {
        return $this->morphOne(AuditEvent::class, 'subject')
            ->ofMany([
                'occurred_at' => 'max',
                'id' => 'max',
            ], fn (Builder $query) => $query->where('action', 'ticket.escalated'));
    }

    public function latestRecentEscalationEvent(): ?AuditEvent
    {
        if ($this->status === 'closed') {
            return null;
        }

        $event = $this->relationLoaded('latestEscalationEvent')
            ? $this->latestEscalationEvent
            : $this->latestEscalationEvent()->with('actor')->first();

        if (! $event?->occurred_at) {
            return null;
        }

        return $event->occurred_at->greaterThanOrEqualTo(now()->subDay())
            ? $event
            : null;
    }

    public function hasRecentEscalation(): bool
    {
        return $this->latestRecentEscalationEvent() !== null;
    }

    public function escalationAudienceLabelFor(User $agent): string
    {
        $targetAgentId = data_get($this->latestRecentEscalationEvent()?->metadata, 'target_agent_id');

        return (int) $targetAgentId === (int) $agent->id
            ? 'Escalated to you'
            : 'Recently escalated';
    }

    public function externalLinks(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class);
    }
}
