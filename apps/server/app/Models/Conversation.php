<?php

namespace App\Models;

use App\Models\Concerns\SanitisesStoredPageUrls;
use App\Support\Conversations\ConversationLifecycleLog;
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
    'priority',
    'subject',
    'metadata',
    'last_message_at',
    'closed_at',
])]
class Conversation extends Model
{
    use SanitisesStoredPageUrls;

    /**
     * @return array<int, string>
     */
    protected static function pageUrlPaths(): array
    {
        return ['started_page_url'];
    }

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

    /**
     * A fresh, unused support code.
     *
     * Was private to the widget's ConversationController until email needed to
     * open a conversation too. A second implementation of "what a support code
     * looks like" is how two channels come to mint codes in different shapes.
     */
    public static function generateSupportCode(): string
    {
        do {
            $supportCode = 'WF-'.Str::upper(Str::random(8));
        } while (self::query()->where('support_code', $supportCode)->exists());

        return $supportCode;
    }

    /**
     * The close a rating would be answering, or null if there is not one.
     *
     * Null is a refusal, not a default: a conversation that was never closed
     * has no stretch of work to rate, and accepting an answer about one would
     * put a score in the report that nobody was ever asked for.
     *
     * **A RECORDED close, never `closed_at`.** The fallback is tempting and
     * wrong: on an upgraded install a conversation closed before lifecycle
     * recording began still has `closed_at`, but `SupportReport::satisfaction()`
     * builds its denominator from lifecycle events alone. An answer about such
     * a close would be counted with no close to count against it -- the "1 of 0
     * closes answered" the cohort alignment exists to prevent, arriving through
     * a different door.
     *
     * An episode is only created from a close the denominator can count.
     */
    public function currentCloseEpisode(): ?AuditEvent
    {
        return AuditEvent::query()
            ->where('subject_type', $this->getMorphClass())
            ->where('subject_id', $this->id)
            ->where('action', ConversationLifecycleLog::CLOSED)
            // The event itself, not its timestamp: two closes a second apart
            // are two episodes, and only the row id says so.
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A stable, opaque handle for the current close, safe to hand the widget.
     *
     * Hashed rather than the raw row id, which would publish how many audit
     * events this install has written. The widget only ever compares it for
     * equality.
     */
    public function currentCloseEpisodeToken(): ?string
    {
        $episode = $this->currentCloseEpisode();

        return $episode === null ? null : substr(hash('sha256', 'rating-episode:'.$episode->id), 0, 16);
    }

    /**
     * Whether this conversation is waiting for the visitor to say how it went.
     *
     * True only where there is a recorded close AND it has not been answered,
     * so the two ways of answering no collapse into one -- a widget shown a
     * prompt the endpoint would refuse is worse than one that never asks.
     */
    public function isAwaitingRating(): bool
    {
        $episode = $this->currentCloseEpisode();

        return $episode !== null && ! $this->ratings()->where('episode_event_id', $episode->id)->exists();
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(ConversationRating::class);
    }

    public function slaClocks(): MorphMany
    {
        return $this->morphMany(SlaClock::class, 'subject');
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

    /**
     * The latest message that can decide who owes the next human reply.
     *
     * Integration messages remain real conversation activity, but they cannot
     * move that boundary. Senderless and future non-integration message types
     * still require human review, so only ApiToken is excluded.
     */
    public function latestNonIntegrationMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)
            ->ofMany([
                'created_at' => 'max',
                'id' => 'max',
            ], fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->where('sender_type', '!=', ApiToken::class)
                    ->orWhereNull('sender_type');
            }));
    }

    public function latestAgentMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)
            ->ofMany([
                'created_at' => 'max',
                'id' => 'max',
            ], fn (Builder $query) => $query->where('sender_type', User::class));
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

    /**
     * Which read state this conversation is in for one agent.
     *
     * A key, not a sentence: this class has no surface-scoped locale (see
     * `attentionLabel()`), and this method is the sharpest illustration of why.
     * It takes an AGENT and, translated here, would answer in whatever locale
     * the process last set -- so a job or a mail build would hand an English
     * agent German because a German agent's request happened to run first.
     */
    public function readStateKeyFor(User $agent): string
    {
        return $this->hasNewActivityFor($agent) ? 'read_new_activity' : 'read_seen';
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
            ->whereNull('ended_at')
            ->where('updated_at', '>', CobrowseSession::idleCutoff()));
    }

    public function attentionState(): string
    {
        $latestMessage = $this->latestMessageForHumanWork();

        return $latestMessage?->sender_type === User::class
            ? 'waiting_on_visitor'
            : 'needs_reply';
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsHumanReply(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                // No non-integration message means either an empty conversation
                // or integration-only activity. Neither is a human reply.
                ->whereDoesntHave('latestNonIntegrationMessage')
                ->orWhereHas('latestNonIntegrationMessage', fn (Builder $query) => $query
                    ->where(function (Builder $query): void {
                        $query->where('sender_type', '!=', User::class)
                            ->orWhereNull('sender_type');
                    }));
        });
    }

    /**
     * Deliberately NOT translated, and `attentionState()` is why.
     *
     * A view is only ever rendered inside a request, so a shared Blade component
     * may use the catalogue directly -- the locale is scoped per request to
     * surfaces that have been extracted. A MODEL is not: `attentionLabel()` can
     * be reached from a queued job, a console command or a mail build, where
     * the locale is whatever the process last set and nothing has scoped it to
     * a surface at all.
     *
     * So the model answers with `attentionState()` and each extracted surface
     * translates it at its own call site, which is where the request -- and
     * therefore the reader -- is actually known. This label goes away when its
     * last consumer is extracted.
     */
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

        $latestMessage = $this->latestMessageForHumanWork();

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

        if ($latestMessage->sender_type === Visitor::class) {
            return [
                'body' => 'Visitor replied last. Send a clear response or create a ticket when the request needs durable follow-up.',
                'cta' => 'Jump to reply',
                'href' => '#reply-heading',
                'title' => 'Reply to visitor',
            ];
        }

        if ($latestMessage->sender_type === ApiToken::class) {
            return [
                'body' => 'An integration update is visible, but a human reply is still needed before this conversation can wait on the visitor.',
                'cta' => 'Jump to reply',
                'href' => '#reply-heading',
                'title' => 'Reply to visitor',
            ];
        }

        return [
            'body' => 'New conversation activity needs human review before the conversation can wait on anyone.',
            'cta' => 'Review messages',
            'href' => '#messages-heading',
            'title' => 'Review new activity',
        ];
    }

    /**
     * The latest activity on this conversation, as data plus catalogue KEYS.
     *
     * `body` is the visitor's or agent's own words and is never translated.
     * `body_key` is set only when there are no words to show, and `label_key`
     * names who spoke -- both are keys rather than sentences, for the reason on
     * `attentionLabel()`. The English `label` and `body` fallbacks remain for
     * surfaces that have not been extracted.
     *
     * @return array{label: string, label_key: string, body: string, body_key: string|null, occurred_at: CarbonInterface|null}
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
            $snippet = $this->activityPreviewSnippet($latestMessage->body);

            return [
                'body' => $snippet ?: 'Message has no text preview.',
                'body_key' => $snippet ? null : 'preview_no_text',
                'label' => $this->activityPreviewLabel($latestMessage),
                'label_key' => $this->activityPreviewLabelKey($latestMessage),
                'occurred_at' => $latestMessage->created_at,
            ];
        }

        return [
            'body' => 'No messages have been sent yet.',
            'body_key' => 'preview_none_body',
            'label' => 'No activity preview yet',
            'label_key' => 'preview_none_label',
            'occurred_at' => null,
        ];
    }

    /**
     * When this conversation opened and how long it has been waiting.
     *
     * Timestamps rather than sentences, and a key for the waiting state --
     * `diffForHumans()` reads the ambient locale, so rendering it here would
     * put a job's locale into an agent's page. The surface formats both.
     *
     * @return array{opened_label: string, opened_at: CarbonInterface, wait_label: string, wait_key: string, wait_since: CarbonInterface|null}
     */
    public function queueTimingContext(): array
    {
        $latestMessage = $this->latestMessageForHumanWork();

        return [
            'opened_label' => 'Opened '.$this->created_at->diffForHumans(),
            'opened_at' => $this->created_at,
            'wait_label' => $this->queueWaitLabel($latestMessage),
            'wait_key' => $this->queueWaitKey($latestMessage),
            'wait_since' => $this->queueWaitSince($latestMessage),
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

    /**
     * When the visitor last saw an agent reply, as a KEY plus the moment.
     *
     * Keys rather than sentences, for the reason on `attentionLabel()`. The
     * English labels below stay for surfaces that are not extracted.
     *
     * @return array{key: string, detail_key: string, seen_at: CarbonInterface|null}
     */
    public function visitorReadCue(): array
    {
        $message = $this->latestAgentMessageForReadReceipt();

        if (! $message) {
            return ['key' => 'none', 'detail_key' => 'detail_none', 'seen_at' => null];
        }

        return $message->seen_at
            ? ['key' => 'seen', 'detail_key' => 'detail_seen', 'seen_at' => $message->seen_at]
            : ['key' => 'unseen', 'detail_key' => 'detail_unseen', 'seen_at' => null];
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
            // `state` and `detail_key` are what a consumer should read: this
            // payload is broadcast to every agent watching, and they do not all
            // read the same language. `label` and `detail` stay English for the
            // surfaces that have not been extracted.
            'state' => $visitor?->presenceState() ?? 'unknown',
            'detail_key' => $visitor?->presenceCue()['key'] ?? 'no_heartbeat',
            'label' => $visitor?->presenceLabel() ?? 'Not reported',
            'detail' => $visitor?->presenceDetail() ?? 'No visitor heartbeat yet.',
            // The WEBSITE sighting, matching the state above it. Serializing
            // the cross-channel one produced a payload that disagreed with
            // itself -- state `quiet`, moment two minutes ago -- and the agent
            // page interpolates that moment into the detail line, so a visitor
            // who had emailed read as having just been on the site.
            'last_seen_at' => $visitor?->last_web_seen_at?->toJSON(),
            // The same timestamp as `last_seen_at` above it, which is the
            // website sighting. Computed from the cross-channel one, this
            // caption disagreed with the state beside it: `quiet`, "2 minutes
            // ago" -- the state describing the website and the words
            // describing an email.
            'last_seen_label' => $visitor?->last_web_seen_at?->diffForHumans() ?? 'Not reported',
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

    private function activityPreviewLabelKey(ConversationMessage $message): string
    {
        return match ($message->sender_type) {
            Visitor::class => 'preview_visitor',
            User::class => 'preview_agent',
            default => 'preview_message',
        };
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

    /**
     * Which waiting state this row is in -- a key, formatted by the surface.
     */
    private function queueWaitKey(?ConversationMessage $latestMessage): string
    {
        if ($this->status === 'closed') {
            return 'closed';
        }

        if ($latestMessage?->created_at === null) {
            return 'no_messages';
        }

        return $latestMessage->sender_type === User::class
            ? 'waiting_on_visitor'
            : 'waiting_on_reply';
    }

    /**
     * The moment the waiting has been measured from, or null when nothing is.
     */
    private function queueWaitSince(?ConversationMessage $latestMessage): ?CarbonInterface
    {
        if ($this->status === 'closed') {
            return $this->closed_at ?? $this->updated_at;
        }

        return $latestMessage?->created_at;
    }

    private function queueWaitLabel(?ConversationMessage $latestMessage): string
    {
        if ($this->status === 'closed') {
            return 'Closed '.($this->closed_at ?? $this->updated_at)->diffForHumans();
        }

        if ($latestMessage?->created_at) {
            $elapsed = $this->elapsedQueueTime($latestMessage->created_at);

            return $latestMessage->sender_type === User::class
                ? 'Waiting on visitor for '.$elapsed
                : 'Waiting on reply for '.$elapsed;
        }

        return 'No messages yet';
    }

    /**
     * The elapsed rendering the surface uses for a waiting state.
     */
    public function elapsedWaitFrom(CarbonInterface $since): string
    {
        return $this->elapsedQueueTime($since);
    }

    private function elapsedQueueTime(CarbonInterface $since): string
    {
        return $since->diffForHumans([
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 1,
        ]);
    }

    /**
     * Activity and human-work boundaries are deliberately different. Prefer
     * the latest non-integration message; only fall back to an integration
     * when no other message type has ever supplied a work boundary.
     */
    public function latestMessageForHumanWork(): ?ConversationMessage
    {
        $boundary = $this->relationLoaded('latestNonIntegrationMessage')
            ? $this->latestNonIntegrationMessage
            : $this->latestNonIntegrationMessage()->first();

        if ($boundary) {
            return $boundary;
        }

        return $this->relationLoaded('latestMessage')
            ? $this->latestMessage
            : $this->latestMessage()->first();
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
