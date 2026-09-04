<?php

namespace App\Models;

use App\Models\Concerns\SanitisesStoredPageUrls;
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
use Illuminate\Support\Carbon;
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
    /**
     * How many ordered ticket rows one queue response renders.
     *
     * Counts remain uncapped, so a busy desk still reports the real lane size.
     * This only bounds the Eloquent graphs and HTML built for one page (#847).
     */
    public const QUEUE_DISPLAY_LIMIT = 200;

    use SanitisesStoredPageUrls;

    /**
     * @return array<int, string>
     */
    protected static function pageUrlPaths(): array
    {
        return ['visitor_context.last_page_url', 'visitor_context.started_page_url'];
    }

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    public function hasConversationDerivedDescription(): bool
    {
        if (trim((string) $this->description) === '') {
            return false;
        }

        $descriptionSource = data_get($this->metadata, 'description_source');

        if ($descriptionSource === 'agent_summary') {
            return false;
        }

        return $descriptionSource === 'conversation_transcript'
            || data_get($this->metadata, 'source') === 'conversation';
    }

    /**
     * Match the stored half of hasConversationDerivedDescription() so a
     * ticket-only search cannot reveal text that its result page would hide.
     */
    public function scopeWhereDescriptionIsNotConversationDerived(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('metadata->description_source', 'agent_summary')
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('metadata->description_source')
                                ->orWhere('metadata->description_source', '!=', 'conversation_transcript');
                        })
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('metadata->source')
                                ->orWhere('metadata->source', '!=', 'conversation');
                        });
                });
        });
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

    /**
     * The attention-state CASE, and the bindings it needs.
     *
     * One source for both the selected column and the ordering. PostgreSQL will
     * not accept a select alias inside an expression in `ORDER BY` -- only a
     * bare reference -- so the ordering has to repeat the expression, and
     * repeating it by hand is how the two would drift apart.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function attentionStateSql(): array
    {
        // The latest non-integration message on the ticket's conversation,
        // matching `Conversation::latestMessageForHumanWork()`. Integration
        // activity is real, but cannot move the human reply boundary. When no
        // other sender has spoken, fall back to the latest message so an
        // integration-only conversation still enters the human-work lane.
        //
        // The difference is null `created_at`, which the column allows. A MAX
        // skips nulls, so `ofMany` never returns a null-dated message; a
        // descending sort puts one FIRST on PostgreSQL and last on SQLite. The
        // `is not null` is what makes this a MAX rather than a sort, and it has
        // to be on the existence check too: with every message null-dated,
        // `latestMessage` is null and the ticket has no latest message at all.
        $datedMessage = 'from conversation_messages m'
            .' where m.conversation_id = tickets.conversation_id'
            .' and m.created_at is not null';

        $latestMessageSender = "(select m.sender_type {$datedMessage}"
            .' order by m.created_at desc, m.id desc limit 1)';
        $nonIntegrationMessage = $datedMessage
            .' and (m.sender_type <> ? or m.sender_type is null)';
        $hasNonIntegrationMessage = "exists (select 1 {$nonIntegrationMessage})";
        $latestNonIntegrationSender = "(select m.sender_type {$nonIntegrationMessage}"
            .' order by m.created_at desc, m.id desc limit 1)';
        // CASE rather than COALESCE: null is a supported sender value. A real
        // senderless message must remain the boundary instead of falling back
        // to a newer integration message.
        $latestWorkSender = "(case when {$hasNonIntegrationMessage}"
            ." then {$latestNonIntegrationSender} else {$latestMessageSender} end)";

        // Whether the conversation has a message AT ALL, asked separately from
        // who sent it. `conversation_messages.sender` is a nullable morph, so
        // the sender of a real message can be null -- and reading the selected
        // sender as the existence check cannot tell that message apart from no
        // message. `attentionState()` branches on the message OBJECT and then
        // on its sender, so a null-sender message is `needs_reply` there while
        // the collapsed version answered `needs_agent`.
        $hasMessage = "exists (select 1 {$datedMessage})";

        // Escalated within the last day and not closed, matching
        // `latestRecentEscalationEvent()`, which returns null for a closed
        // ticket before it looks at the clock.
        $recentEscalation = 'exists (select 1 from audit_events e'
            .' where e.subject_id = tickets.id and e.subject_type = ?'
            ." and e.action = 'ticket.escalated'"
            .' and e.occurred_at >= ?)';

        // The `pending` branch below deliberately does NOT need $hasMessage.
        // `attentionState()` treats a null-sender message and no message alike,
        // so collapsing both to '' is what that rule actually says. A visitor,
        // or integration-only activity, falls through: neither can stand in
        // for the human reply that would make the ticket wait on the customer.
        $case = "case
            when tickets.status <> 'closed' and {$recentEscalation} then 'escalated'
            when tickets.status = 'closed' then 'resolved'
            when tickets.status = 'pending' and coalesce({$latestWorkSender}, '') not in (?, ?) then 'waiting_on_customer'
            when tickets.assignee_id is null then 'needs_owner'
            when {$latestWorkSender} = ? then 'waiting_on_customer'
            when {$hasMessage} then 'needs_reply'
            else 'needs_agent'
        end";

        return [$case, [
            (new self)->getMorphClass(),
            Carbon::now()->subDay(),
            (new ApiToken)->getMorphClass(),
            (new ApiToken)->getMorphClass(),
            (new Visitor)->getMorphClass(),
            (new ApiToken)->getMorphClass(),
            (new ApiToken)->getMorphClass(),
            (new ApiToken)->getMorphClass(),
            (new User)->getMorphClass(),
        ]];
    }

    /**
     * The dashboard attention state, decided in SQL.
     *
     * The same cascade `attentionState()` walks in PHP, plus the escalation the
     * queue layers on top of it -- expressed so the queue can filter, order and
     * CAP in the database instead of hydrating every matching ticket to sort
     * them (#847).
     *
     * Two implementations of one rule is a drift waiting to happen, so
     * `TicketAttentionStateParityTest` asserts they agree ticket by ticket over
     * a seeded desk. This exists because the PHP one cannot be reached from a
     * `where`, not because the rule changed.
     */
    public function scopeSelectAttentionState(Builder $query, string $as = 'attention_state'): Builder
    {
        [$case, $bindings] = self::attentionStateSql();

        // The alias is quoted by the grammar rather than dropped in raw. It is
        // a developer-supplied string today, but this is a public scope and a
        // raw interpolation is the shape of every injection that started out
        // "only ever called with a constant".
        $alias = $query->getQuery()->getGrammar()->wrap($as);

        return $query->selectRaw("{$case} as {$alias}", $bindings);
    }

    /**
     * How many tickets sit in each attention state, in one grouped query.
     *
     * The queue's summary needs every state's count to build its filter chips,
     * so it cannot read them off a list already narrowed to one state. It used
     * to count them in PHP over the whole hydrated result set, which is part of
     * why this page cannot be capped (#847).
     *
     * @return array<string, int>
     */
    public function scopeAttentionStateCounts(Builder $query): array
    {
        [$case, $bindings] = self::attentionStateSql();

        return $query
            ->getQuery()
            ->selectRaw("{$case} as attention_state, count(*) as aggregate", $bindings)
            // Grouped by the ALIAS, not by a second copy of the expression:
            // both drivers resolve it here, and passing the same bindings to
            // two clauses put them out of step.
            ->groupBy('attention_state')
            ->pluck('aggregate', 'attention_state')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Narrow to one attention state, in SQL.
     *
     * A `where` rather than a `having` on the selected alias: PostgreSQL will
     * not resolve a select alias in either `having` or an expression in
     * `order by`, so the CASE is repeated -- from the one place that builds it,
     * which is the point of `attentionStateSql()`.
     */
    public function scopeWhereAttentionState(Builder $query, string $state): Builder
    {
        [$case, $bindings] = self::attentionStateSql();

        return $query->whereRaw("{$case} = ?", [...$bindings, $state]);
    }

    /**
     * The queue's ordering, in SQL.
     *
     * The ranks are `attentionSortRank()`, plus the 5 the queue gives an
     * escalated ticket. Built from the same CASE as the state, so the order
     * cannot disagree with what it is ordering by.
     */
    public function scopeOrderByAttention(Builder $query): Builder
    {
        [$case, $bindings] = self::attentionStateSql();

        return $query
            ->orderByRaw(
                "case {$case}
                    when 'escalated' then 5
                    when 'needs_reply' then 10
                    when 'needs_owner' then 20
                    when 'needs_agent' then 30
                    when 'waiting_on_customer' then 70
                    when 'resolved' then 90
                    else 50
                end",
                $bindings
            )
            ->orderByDesc('tickets.updated_at')
            ->orderByDesc('tickets.created_at')
            // Stable boundary for the row cap: timestamps only have
            // second-level precision in the supported schema, so bulk-created
            // tickets routinely tie on every key above.
            ->orderByDesc('tickets.id');
    }

    public function attentionState(): string
    {
        if ($this->status === 'closed') {
            return 'resolved';
        }

        $latestMessage = $this->latestConversationMessageForHumanWork();

        if ($this->status === 'pending'
            && ! in_array($latestMessage?->sender_type, [Visitor::class, ApiToken::class], true)) {
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

    /**
     * The attention state as a catalogue KEY.
     *
     * A model has no surface-scoped locale -- it can be reached from a job, a
     * command or a mail build, where the locale is whatever the process last
     * set. So it answers with keys and each extracted surface translates them.
     * The English labels below stay for surfaces that have not been extracted.
     * See Conversation::attentionLabel() for the same rule.
     */
    public function attentionLabelKey(): string
    {
        return 'attention_'.$this->attentionState();
    }

    public function attentionDescriptionKey(): string
    {
        return match ($this->attentionState()) {
            'waiting_on_customer' => $this->status === 'pending'
                ? 'description_waiting_marked_pending'
                : 'description_waiting_agent_replied',
            'needs_reply', 'needs_owner', 'resolved' => 'description_'.$this->attentionState(),
            default => 'description_needs_agent',
        };
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
     * @return array{label: string, label_key: string, body: string, body_key: string|null, occurred_at: CarbonInterface|null, reply_visibility: array{label: string, detail: string, tone: string}|null}
     */
    public function queueActivityPreview(): array
    {
        $latestMessage = $this->latestConversationMessage();

        if ($latestMessage) {
            return [
                'body' => $this->activityPreviewSnippet($latestMessage->body) ?: 'Message has no text preview.',
                'body_key' => $this->activityPreviewSnippet($latestMessage->body) ? null : 'preview_no_text',
                'label' => $this->activityPreviewLabel($latestMessage),
                'label_key' => $this->activityPreviewLabelKey($latestMessage),
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
                'body_key' => null,
                'label' => 'Ticket summary',
                'label_key' => 'preview_summary',
                'occurred_at' => $this->created_at,
                'reply_visibility' => null,
            ];
        }

        return [
            'body' => 'Open the ticket to add context or send the next update.',
            'body_key' => 'preview_none_body',
            'label' => 'No activity preview yet',
            'label_key' => 'preview_none_label',
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
                'cue' => null,
                'detail' => 'Reply visibility starts once this ticket is connected to a conversation.',
                'label' => 'No linked conversation',
                'tone' => 'manual',
            ];
        }

        return [
            'cue' => $conversation->visitorReadCue(),
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
    /**
     * The next-action state as a catalogue key -- see `attentionLabelKey()`.
     * `nextAction()` keeps its English for surfaces not yet extracted.
     */
    public function nextActionKey(): string
    {
        return in_array($this->attentionState(), ['needs_reply', 'needs_owner', 'waiting_on_customer', 'resolved'], true)
            ? $this->attentionState()
            : 'needs_agent';
    }

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
    /**
     * Which status-readiness cue applies, as a catalogue key.
     * `statusActionReadiness()` keeps its English for unextracted surfaces.
     */
    public function statusActionReadinessKey(): string
    {
        $latestMessage = $this->latestConversationMessage();

        if ($this->status !== 'closed' && $latestMessage?->sender_type === Visitor::class) {
            return 'reply_before_closing';
        }

        return match ($this->attentionState()) {
            'needs_reply' => 'reply_before_closing',
            'needs_owner' => 'assign_first',
            'waiting_on_customer' => $this->status === 'pending' ? 'pending' : 'calm',
            'resolved' => 'closed',
            default => 'default',
        };
    }

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

    /**
     * @return array{label: string, body: string, actor: string, occurred_at: CarbonInterface}|null
     */
    public function latestLifecycleNote(): ?array
    {
        $event = $this->relationLoaded('latestLifecycleEvent')
            ? $this->latestLifecycleEvent
            : $this->latestLifecycleEvent()->with('actor')->first();

        if (! $event?->occurred_at || $this->lifecycleNoteBody($event) === '') {
            return null;
        }

        $actorName = $event->actor_type === Visitor::class ? null : $event->actor?->name;

        return [
            // The English answer STAYS, for the surfaces that have not been
            // extracted -- the ticket detail page reads this directly. Adding
            // a key must never take the old answer away; that is the rule this
            // whole epic runs on, and removing it here blanked the actor on a
            // page nothing in this PR touches.
            'actor' => $actorName ?? ($event->actor_type === Visitor::class ? 'Visitor' : 'System'),
            // A NAME is data and is never translated, so there is no key for
            // it -- only for the two fallbacks.
            'actor_key' => $actorName !== null
                ? null
                : ($event->actor_type === Visitor::class ? 'actor_visitor' : 'actor_system'),
            'body' => $this->lifecycleNoteBody($event),
            'label' => $this->lifecycleNoteLabel($event),
            'label_key' => $this->lifecycleNoteLabelKey($event),
            'occurred_at' => $event->occurred_at,
        ];
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
        $latestMessage = $this->latestConversationMessageForHumanWork();

        return [
            'opened_label' => 'Opened '.$this->created_at->diffForHumans(),
            'opened_at' => $this->created_at,
            'wait_label' => $this->queueWaitLabel($latestMessage),
            'wait_key' => $this->queueWaitKey($latestMessage),
            'wait_since' => $this->queueWaitSince($latestMessage),
        ];
    }

    /**
     * Which waiting state this row is in -- a key, formatted by the surface.
     */
    private function queueWaitKey(?ConversationMessage $latestMessage): string
    {
        $attentionState = $this->attentionState();

        if ($attentionState === 'resolved') {
            return 'closed';
        }

        if ($attentionState === 'needs_owner') {
            return 'waiting_on_owner';
        }

        if ($latestMessage?->created_at) {
            return match ($attentionState) {
                'needs_reply' => 'waiting_on_reply',
                'waiting_on_customer' => 'waiting_on_customer',
                default => 'waiting_on_update',
            };
        }

        return $attentionState === 'waiting_on_customer'
            ? 'waiting_customer_since_open'
            : 'waiting_agent_since_open';
    }

    /**
     * The moment the waiting is measured from, or null when it is not measured.
     */
    private function queueWaitSince(?ConversationMessage $latestMessage): ?CarbonInterface
    {
        $attentionState = $this->attentionState();

        if ($attentionState === 'resolved') {
            return $this->closed_at ?? $this->updated_at;
        }

        if ($attentionState === 'needs_owner') {
            return $latestMessage?->created_at ?? $this->created_at;
        }

        return $latestMessage?->created_at;
    }

    /**
     * The elapsed rendering the surface uses for a waiting state.
     */
    public function elapsedWaitFrom(CarbonInterface $since): string
    {
        return $this->elapsedQueueTime($since);
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

            return match ($attentionState) {
                'needs_reply' => 'Waiting on reply for '.$elapsed,
                'waiting_on_customer' => 'Waiting on customer for '.$elapsed,
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
        // One definition, reached two ways. The fallback used to be its own
        // `latest('created_at')->latest('id')` query, which is NOT what
        // `latestMessage()` means: that relation is `ofMany(max)`, and a MAX
        // ignores null `created_at` while an `order by ... desc` sorts it to
        // the front on PostgreSQL. So a null-dated message was the latest
        // message on any page that had not eager-loaded the relation, and was
        // invisible on the queue, which had. Two answers from one method.
        return $this->conversation?->relationLoaded('latestMessage')
            ? $this->conversation->latestMessage
            : $this->conversation?->latestMessage()->first();
    }

    private function latestConversationMessageForHumanWork(): ?ConversationMessage
    {
        return $this->conversation?->latestMessageForHumanWork();
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

    private function lifecycleNoteBody(AuditEvent $event): string
    {
        $body = match ($event->action) {
            'ticket.pending' => data_get($event->metadata, 'pending_note'),
            'ticket.closed' => data_get($event->metadata, 'resolution_note'),
            'ticket.reopened' => data_get($event->metadata, 'reopen_note'),
            'ticket.unheld' => data_get($event->metadata, 'reopen_note'),
            'ticket.escalated' => data_get($event->metadata, 'reason'),
            default => null,
        };

        return (string) Str::of((string) $body)->squish();
    }

    private function lifecycleNoteLabelKey(AuditEvent $event): string
    {
        // Without the `ticket.` prefix: a catalogue key containing a dot is
        // read as nesting by Laravel's dot notation and never resolves.
        return match ($event->action) {
            'ticket.pending' => 'pending',
            'ticket.closed' => 'closed',
            'ticket.reopened' => 'reopened',
            'ticket.unheld' => 'unheld',
            'ticket.escalated' => 'escalated',
            default => 'default',
        };
    }

    private function lifecycleNoteLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            'ticket.pending' => 'Ticket marked pending',
            'ticket.closed' => 'Ticket closed',
            'ticket.reopened' => 'Ticket reopened',
            'ticket.unheld' => 'Ticket taken off hold',
            'ticket.escalated' => 'Ticket escalated',
            default => 'Lifecycle update',
        };
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

    public function latestLifecycleEvent(): MorphOne
    {
        return $this->morphOne(AuditEvent::class, 'subject')
            ->ofMany([
                'occurred_at' => 'max',
                'id' => 'max',
            ], fn (Builder $query) => $query->whereIn('action', [
                'ticket.pending',
                'ticket.closed',
                'ticket.reopened',
                'ticket.unheld',
                'ticket.escalated',
            ]));
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

    /**
     * The escalation audience as a catalogue key -- see `attentionLabelKey()`.
     */
    public function escalationAudienceKeyFor(User $agent): string
    {
        $targetAgentId = data_get($this->latestRecentEscalationEvent()?->metadata, 'target_agent_id');

        return (int) $targetAgentId === (int) $agent->id
            ? 'escalated_to_you'
            : 'escalated_recent';
    }

    public function externalLinks(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class);
    }
}
