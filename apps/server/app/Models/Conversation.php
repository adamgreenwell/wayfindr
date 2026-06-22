<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

#[Fillable([
    'site_id',
    'visitor_id',
    'assigned_agent_id',
    'support_code',
    'status',
    'subject',
    'metadata',
    'last_message_at',
    'closed_at',
])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    private const AGENT_TYPING_FRESH_SECONDS = 20;

    private const VISITOR_TYPING_FRESH_SECONDS = 20;

    public static function visitorTypingFreshMilliseconds(): int
    {
        return self::VISITOR_TYPING_FRESH_SECONDS * 1000;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->ofMany([
            'created_at' => 'max',
            'id' => 'max',
        ]);
    }

    public function latestAgentMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)
            ->where('sender_type', User::class)
            ->latestOfMany('created_at');
    }

    public function readStates(): HasMany
    {
        return $this->hasMany(ConversationReadState::class);
    }

    public function readStateFor(User $agent): ?ConversationReadState
    {
        if ($this->relationLoaded('readStates')) {
            return $this->readStates->firstWhere('user_id', $agent->id);
        }

        return $this->readStates()
            ->where('user_id', $agent->id)
            ->first();
    }

    public function markReadFor(User $agent, ?CarbonInterface $readAt = null): ConversationReadState
    {
        return $this->readStates()->updateOrCreate(
            ['user_id' => $agent->id],
            ['last_read_at' => $readAt ?? now()],
        );
    }

    public function hasNewActivityFor(User $agent): bool
    {
        $lastActivityAt = $this->lastActivityAtForReadState();

        if (! $lastActivityAt) {
            return false;
        }

        $lastReadAt = $this->readStateFor($agent)?->last_read_at;

        return ! $lastReadAt || $lastActivityAt->gt($lastReadAt);
    }

    public function readStateLabelFor(User $agent): string
    {
        return $this->hasNewActivityFor($agent) ? 'New activity' : 'Seen';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithNewActivityFor(Builder $query, User $agent): Builder
    {
        return $query->where(function (Builder $query) use ($agent): void {
            $query
                ->whereDoesntHave('readStates', fn (Builder $query) => $query->where('user_id', $agent->id))
                ->orWhereHas('readStates', fn (Builder $query) => $query
                    ->where('user_id', $agent->id)
                    ->whereRaw('conversation_read_states.last_read_at < coalesce(conversations.last_message_at, conversations.created_at)'));
        });
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWithActiveCobrowseSession(Builder $query): Builder
    {
        return $query->whereHas('cobrowseSessions', fn (Builder $query) => $query
            ->where('status', 'granted')
            ->whereNull('ended_at'));
    }

    public function attentionState(): string
    {
        $latestMessage = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->messages()
                ->latest('created_at')
                ->latest('id')
                ->first();

        return $latestMessage?->sender_type === User::class
            ? 'waiting_on_visitor'
            : 'needs_reply';
    }

    public function attentionLabel(): string
    {
        return match ($this->attentionState()) {
            'waiting_on_visitor' => 'Waiting on visitor',
            default => 'Needs reply',
        };
    }

    /**
     * @return array{title: string, body: string, cta: string, href: string}
     */
    public function nextAction(): array
    {
        if ($this->status === 'closed') {
            return [
                'body' => 'This conversation is closed. Reopen it only if the visitor returns or the outcome changes.',
                'cta' => 'Review status actions',
                'href' => '#conversation-context-heading',
                'title' => 'Review closed conversation',
            ];
        }

        $latestMessage = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->messages()
                ->latest('created_at')
                ->latest('id')
                ->first();

        if (! $latestMessage) {
            return [
                'body' => 'No messages have landed yet. Use the current visitor context to decide whether to greet, wait, or create a ticket.',
                'cta' => 'Review context',
                'href' => '#visitor-context-heading',
                'title' => 'Start the conversation',
            ];
        }

        if ($latestMessage->sender_type === User::class) {
            return [
                'body' => 'Agent replied last. Keep the conversation visible and respond when the visitor comes back.',
                'cta' => 'Review messages',
                'href' => '#messages-heading',
                'title' => 'Wait on visitor',
            ];
        }

        return [
            'body' => 'Visitor replied last. Send a clear response or create a ticket when the request needs durable follow-up.',
            'cta' => 'Jump to reply',
            'href' => '#reply-heading',
            'title' => 'Reply to visitor',
        ];
    }

    /**
     * @return array{title: string, detail: string, cta: string, href: string, tone: string}
     */
    public function statusActionReadiness(): array
    {
        if ($this->status === 'closed') {
            return [
                'cta' => 'Review reopen option',
                'detail' => 'Reopen only if the visitor returns or the outcome changes. The existing transcript stays attached to this support code.',
                'href' => '#conversation-status-action',
                'title' => 'Closed conversation',
                'tone' => 'manual',
            ];
        }

        $latestMessage = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->messages()
                ->latest('created_at')
                ->latest('id')
                ->first();

        if (! $latestMessage) {
            return [
                'cta' => 'Review context',
                'detail' => 'Wait for the visitor or use the context panel before closing an empty conversation.',
                'href' => '#visitor-context-heading',
                'title' => 'No messages yet',
                'tone' => 'manual',
            ];
        }

        if ($latestMessage->sender_type === Visitor::class) {
            return [
                'cta' => 'Jump to reply',
                'detail' => 'Visitor replied last. Closing now may leave the visitor waiting. Send a reply or create a ticket before closing the live chat.',
                'href' => '#reply-heading',
                'title' => 'Reply before closing',
                'tone' => 'attention',
            ];
        }

        return [
            'cta' => 'Review close option',
            'detail' => 'Agent replied last. Keep it open while waiting, or close once the outcome is settled.',
            'href' => '#conversation-status-action',
            'title' => 'Ready to close when settled',
            'tone' => 'ready',
        ];
    }

    /**
     * @return array{label: string, body: string, occurred_at: CarbonInterface|null}
     */
    public function queueActivityPreview(): array
    {
        $latestMessage = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->messages()
                ->latest('created_at')
                ->latest('id')
                ->first();

        if ($latestMessage) {
            return [
                'body' => $this->activityPreviewSnippet($latestMessage->body) ?: 'Message has no text preview.',
                'label' => $this->activityPreviewLabel($latestMessage),
                'occurred_at' => $latestMessage->created_at,
            ];
        }

        return [
            'body' => 'No messages have been sent yet.',
            'label' => 'No activity preview yet',
            'occurred_at' => null,
        ];
    }

    /**
     * @return array{opened_label: string, wait_label: string}
     */
    public function queueTimingContext(): array
    {
        $latestMessage = $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->messages()
                ->latest('created_at')
                ->latest('id')
                ->first();

        return [
            'opened_label' => 'Opened '.$this->created_at->diffForHumans(),
            'wait_label' => $this->queueWaitLabel($latestMessage),
        ];
    }

    public function visitorReadState(): string
    {
        $message = $this->latestAgentMessageForReadReceipt();

        if (! $message) {
            return 'none';
        }

        return $message->seen_at ? 'seen' : 'unseen';
    }

    public function visitorReadLabel(): string
    {
        return match ($this->visitorReadState()) {
            'seen' => 'Visitor saw reply',
            'unseen' => 'Not seen yet',
            default => 'No agent reply yet',
        };
    }

    public function visitorReadDetail(): string
    {
        $message = $this->latestAgentMessageForReadReceipt();

        if (! $message) {
            return 'No agent reply has been sent.';
        }

        if ($message->seen_at) {
            return 'Seen '.$message->seen_at->diffForHumans();
        }

        return 'Latest agent reply has not been seen.';
    }

    /**
     * @return array{message_id: int|null, state: string, label: string, detail: string, seen_at: string|null, seen_label: string|null}
     */
    public function visitorReadPayload(): array
    {
        $message = $this->latestAgentMessageForReadReceipt();

        return [
            'message_id' => $message?->id,
            'state' => $this->visitorReadState(),
            'label' => $this->visitorReadLabel(),
            'detail' => $this->visitorReadDetail(),
            'seen_at' => $message?->seen_at?->toJSON(),
            'seen_label' => $message?->seen_at?->diffForHumans(),
        ];
    }

    public function visitorTypingState(): string
    {
        $typingAt = $this->visitorTypingAt();

        if (! $typingAt) {
            return 'idle';
        }

        return $typingAt->gte(now()->subSeconds(self::VISITOR_TYPING_FRESH_SECONDS))
            ? 'typing'
            : 'idle';
    }

    public function visitorTypingLabel(): string
    {
        return $this->visitorTypingState() === 'typing'
            ? 'Typing now'
            : 'Not typing';
    }

    public function visitorTypingDetail(): string
    {
        $typingAt = $this->visitorTypingAt();

        if (! $typingAt) {
            return 'No typing signal reported.';
        }

        if ($this->visitorTypingState() === 'typing') {
            return 'Updated '.$typingAt->diffForHumans();
        }

        return 'Last typing signal '.$typingAt->diffForHumans().'.';
    }

    /**
     * @return array{state: string, label: string, updated_at: string|null}
     */
    public function visitorTypingPayload(): array
    {
        $typingAt = $this->visitorTypingAt();

        return [
            'state' => $this->visitorTypingState(),
            'label' => $this->visitorTypingLabel(),
            'updated_at' => $typingAt?->toJSON(),
        ];
    }

    /**
     * @return array{state: string, label: string, detail: string, last_seen_at: string|null, last_seen_label: string}
     */
    public function visitorPresencePayload(): array
    {
        $visitor = $this->relationLoaded('visitor')
            ? $this->visitor
            : $this->visitor()->first();

        return [
            'state' => $visitor?->presenceState() ?? 'unknown',
            'label' => $visitor?->presenceLabel() ?? 'Not reported',
            'detail' => $visitor?->presenceDetail() ?? 'No visitor heartbeat yet.',
            'last_seen_at' => $visitor?->last_seen_at?->toJSON(),
            'last_seen_label' => $visitor?->last_seen_at?->diffForHumans() ?? 'Not reported',
        ];
    }

    /**
     * @return array{state: string, label: string|null, updated_at: string|null}
     */
    public function agentTypingPayload(): array
    {
        $typingAt = $this->agentTypingAt();

        if (! $typingAt || $typingAt->lt(now()->subSeconds(self::AGENT_TYPING_FRESH_SECONDS))) {
            return [
                'state' => 'idle',
                'label' => null,
                'updated_at' => null,
            ];
        }

        return [
            'state' => 'typing',
            'label' => 'Support is typing...',
            'updated_at' => $typingAt->toJSON(),
        ];
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function cobrowseSessions(): HasMany
    {
        return $this->hasMany(CobrowseSession::class);
    }

    public function latestCobrowseSession(): HasOne
    {
        return $this->hasOne(CobrowseSession::class)->latestOfMany();
    }

    public function auditEvents(): MorphMany
    {
        return $this->morphMany(AuditEvent::class, 'subject');
    }

    private function lastActivityAtForReadState(): ?CarbonInterface
    {
        if ($this->last_message_at) {
            return $this->last_message_at;
        }

        if ($this->relationLoaded('latestMessage') && $this->latestMessage?->created_at) {
            return $this->latestMessage->created_at;
        }

        return $this->created_at;
    }

    private function latestAgentMessageForReadReceipt(): ?ConversationMessage
    {
        if ($this->relationLoaded('latestAgentMessage')) {
            return $this->latestAgentMessage;
        }

        return $this->messages()
            ->where('sender_type', User::class)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    private function activityPreviewLabel(ConversationMessage $message): string
    {
        return match ($message->sender_type) {
            Visitor::class => 'Latest visitor message',
            User::class => 'Latest agent reply',
            default => 'Latest message',
        };
    }

    private function activityPreviewSnippet(?string $body): string
    {
        $body = (string) Str::of((string) $body)->squish();

        return Str::limit($body, 150);
    }

    private function queueWaitLabel(?ConversationMessage $latestMessage): string
    {
        if ($this->status === 'closed') {
            return 'Closed '.($this->closed_at ?? $this->updated_at)->diffForHumans();
        }

        if ($latestMessage?->created_at) {
            $elapsed = $this->elapsedQueueTime($latestMessage->created_at);

            return match ($latestMessage->sender_type) {
                Visitor::class => 'Waiting on reply for '.$elapsed,
                User::class => 'Waiting on visitor for '.$elapsed,
                default => 'Waiting on update for '.$elapsed,
            };
        }

        return 'No messages yet';
    }

    private function elapsedQueueTime(CarbonInterface $since): string
    {
        return $since->diffForHumans([
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 1,
        ]);
    }

    private function visitorTypingAt(): ?CarbonInterface
    {
        $value = $this->metadata['visitor_typing_at'] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function agentTypingAt(): ?CarbonInterface
    {
        $typingSignals = $this->metadata['agent_typing'] ?? [];

        if (! is_array($typingSignals)) {
            return null;
        }

        return collect($typingSignals)
            ->map(function (mixed $typingSignal): ?CarbonInterface {
                if (! is_array($typingSignal)) {
                    return null;
                }

                $value = $typingSignal['at'] ?? null;

                if (! is_string($value) || $value === '') {
                    return null;
                }

                try {
                    return Carbon::parse($value);
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->sortByDesc(fn (CarbonInterface $typingAt): int => $typingAt->getTimestamp())
            ->first();
    }
}
