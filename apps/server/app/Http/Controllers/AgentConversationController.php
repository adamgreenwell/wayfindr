<?php

namespace App\Http\Controllers;

use App\Events\CobrowseStateUpdated;
use App\Events\ConversationMessageCreated;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ConversationNeedsReply;
use App\Support\Attachments\AttachmentBinder;
use App\Support\CobrowseAuditTrail;
use App\Support\CobrowseConsentState;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\Conversations\ConversationLifecycleLog;
use App\Support\Conversations\ConversationQueueQuery;
use App\Support\ReplyTemplateOptions;
use App\Support\TicketCategory;
use App\Support\TicketPriority;
use App\Support\VisitorContextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentConversationController extends Controller
{
    /** How many conversations either side of the current one the menu lists. */
    private const SWITCHER_MENU_WINDOW = 25;

    public function show(Request $request, string $supportCode, CobrowseConsentState $cobrowseConsentState, VisitorContextSanitizer $visitorContextSanitizer, ReplyTemplateOptions $replyTemplateOptions, CobrowseAuditTrail $cobrowseAuditTrail): View
    {
        $agent = $request->user();

        $conversation = $this->conversationForAgent($agent, $supportCode, 'view')
            ->load(['assignedAgent', 'latestAgentMessage', 'latestMessage', 'site', 'visitor']);

        $conversationReturnQuery = $this->conversationQueueReturnQuery($request);

        // Computed BEFORE the read state is mutated. The new-activity lane is
        // defined by withNewActivityFor(), so marking this conversation read
        // first removes it from its own sibling list -- and the "not in this
        // queue" branch below then swallows that as if it were intended,
        // hiding the switcher entirely from the one lane an agent most often
        // works through.
        $conversationSiblings = $this->conversationSiblings($agent, $conversation, $conversationReturnQuery, $cobrowseConsentState);

        $this->markConversationNotificationsRead($agent, $conversation);
        $conversation->markReadFor($agent);

        $cobrowseConsent = $cobrowseConsentState->forConversation($conversation);
        $this->recordCobrowsePreviewView($conversation, $agent, $cobrowseAuditTrail, $cobrowseConsent, 'page_view');

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $tickets = $conversation->tickets()
            ->with(['assignee', 'conversation.latestAgentMessage', 'conversation.latestMessage'])
            ->latest()
            ->get();

        return view('agent.conversations.show', [
            'account' => $agent->account()->firstOrFail(),
            'accountAgents' => $this->supportAgentsForSite($conversation->site),
            'agent' => $agent,
            'cobrowseConsent' => $cobrowseConsent,
            'conversation' => $conversation,
            'conversationBackUrl' => route('dashboard.conversations.index', $conversationReturnQuery),
            'conversationReturnQuery' => $conversationReturnQuery,
            'conversationSiblings' => $conversationSiblings,
            'messages' => $messages,
            'priorConversations' => $this->priorConversations($conversation),
            'realtime' => $this->realtimeConfig($conversation),
            'replyTemplates' => $replyTemplateOptions->forAgent($agent),
            'tickets' => $tickets,
            'ticketCategories' => TicketCategory::options(),
            'ticketPriorities' => TicketPriority::options(),
            'ticketCategoryGuidance' => TicketCategory::options(),
            'ticketPriorityGuidance' => TicketPriority::guidanceOptions(),
            'visitorContext' => $this->visitorContext($conversation, $visitorContextSanitizer),
        ]);
    }

    /**
     * Render just the message transcript partial for live refresh. The agent
     * page listens for conversation.message.created and refetches this so new
     * visitor messages append without a reload, and a reconnecting socket
     * catches up on anything missed while it was down. Kept a pure read (no
     * read-receipt writes or audit events) so it is safe to call frequently.
     */
    public function messages(Request $request, string $supportCode): Response
    {
        $agent = $request->user();

        $conversation = $this->conversationForAgent($agent, $supportCode, 'view');

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->view('agent.conversations.partials.message-list', [
            'emptyMessage' => 'No messages yet.',
            'transcriptMessages' => $messages,
            'supportCode' => $conversation->support_code,
            // The realtime refresh replaces the rendered transcript wholesale.
            // Omitting this dropped the site rail the moment realtime connected.
            'transcriptSiteColor' => $conversation->site->resolvedColor()->cssVariable(),
        ]);
    }

    /**
     * @return array<string, string|int>
     */
    /**
     * The conversations either side of this one, in the queue the agent came
     * from (ADR 0014).
     *
     * Must agree with the queue exactly -- same filters, same order, same
     * post-filtering -- which is why both read ConversationQueueQuery instead
     * of each stating the rules.
     *
     * @param  array<string, mixed>  $returnQuery
     * @return array{items: Collection<int, array{support_code: string, subject: string, current: bool}>, previous: ?string, next: ?string, position: ?int, total: int}
     */
    private function conversationSiblings(
        User $agent,
        Conversation $conversation,
        array $returnQuery,
        CobrowseConsentState $cobrowseConsentState,
    ): array {
        $empty = ['items' => collect(), 'previous' => null, 'next' => null, 'position' => null, 'total' => 0];

        // An empty query is NOT the all-open queue. A conversation opened from
        // a notification, a ticket, the visitor page or a support-code lookup
        // carries no queue parameters, and treating that as queue context would
        // offer neighbours from a list the agent never navigated.
        if (($returnQuery['from_queue'] ?? null) !== '1') {
            return $empty;
        }

        $lane = (string) ($returnQuery['conversation_filter'] ?? 'all');
        $status = $lane === 'closed' ? 'closed' : 'open';
        $site = $returnQuery['conversation_site'] ?? null;
        $search = (string) ($returnQuery['conversation_search'] ?? '');
        $presence = (string) ($returnQuery['conversation_presence'] ?? 'all');

        $query = ConversationQueueQuery::visibleTo($agent, $status, $site ? (int) $site : null, $search);

        if ($presence !== 'all') {
            ConversationQueueQuery::applyPresence($query, $presence);
        }

        ConversationQueueQuery::applyLane($query, $lane, $agent);
        ConversationQueueQuery::ordered($query);

        $siblings = $query->get(['id', 'support_code', 'subject']);

        // The cobrowse lane is narrowed AFTER the query in the queue itself,
        // against live transport state. Without the same pass the switcher
        // lists conversations the queue does not show.
        if ($lane === 'cobrowse_attention') {
            $siblings = $siblings
                ->filter(fn (Conversation $candidate): bool => $cobrowseConsentState->transportNeedsAttention(
                    $cobrowseConsentState->queueTransportForConversation($candidate)
                ))
                ->values();
        }

        $index = $siblings->search(fn (Conversation $candidate): bool => $candidate->id === $conversation->id);

        if ($index === false) {
            return $empty;
        }

        // Position and neighbours come from the WHOLE queue; only the rendered
        // menu is bounded. Capping the query instead made the total wrong, cut
        // the next link at item 50, and removed the control entirely for
        // anything ranked below that.
        $windowStart = max(0, $index - self::SWITCHER_MENU_WINDOW);

        return [
            'items' => $siblings
                ->slice($windowStart, self::SWITCHER_MENU_WINDOW * 2 + 1)
                ->map(fn (Conversation $candidate): array => [
                    'support_code' => $candidate->support_code,
                    'subject' => $candidate->subject ?? 'Untitled conversation',
                    'current' => $candidate->id === $conversation->id,
                ])
                ->values(),
            'previous' => $siblings->get($index - 1)?->support_code,
            'next' => $siblings->get($index + 1)?->support_code,
            'position' => $index + 1,
            'total' => $siblings->count(),
        ];
    }

    private function conversationQueueReturnQuery(Request $request): array
    {
        $params = [];

        // Explicit, so an absent query is never mistaken for "the all-open
        // queue". Only links rendered BY a queue carry it.
        if ($request->input('from_queue') === '1' || $request->input('from_queue') === 1) {
            $params['from_queue'] = '1';
        }
        $conversationFilters = [
            'new_activity',
            'needs_reply',
            'assigned_to_me',
            'unassigned',
            'cobrowse_attention',
            'closed',
        ];

        $conversationFilter = $request->input('conversation_filter');

        if (is_string($conversationFilter) && in_array($conversationFilter, $conversationFilters, true)) {
            $params['conversation_filter'] = $conversationFilter;
        }

        $conversationSearch = $request->input('conversation_search');
        $conversationSearch = is_string($conversationSearch)
            ? mb_substr(trim($conversationSearch), 0, 120)
            : '';

        if ($conversationSearch !== '') {
            $params['conversation_search'] = $conversationSearch;
        }

        $conversationSite = $request->input('conversation_site');

        if (is_int($conversationSite) && $conversationSite > 0) {
            $params['conversation_site'] = $conversationSite;
        } elseif (is_string($conversationSite) && ctype_digit($conversationSite)) {
            $params['conversation_site'] = (int) $conversationSite;
        }

        $conversationPresenceFilters = [
            'active',
            'recent',
            'quiet',
            'not_reported',
        ];
        $conversationPresence = $request->input('conversation_presence');

        if (is_string($conversationPresence) && in_array($conversationPresence, $conversationPresenceFilters, true)) {
            $params['conversation_presence'] = $conversationPresence;
        }

        return $params;
    }

    /**
     * @return array<string, string|int>
     */
    private function conversationShowRouteParams(Conversation $conversation, Request $request): array
    {
        return ['supportCode' => $conversation->support_code] + $this->conversationQueueReturnQuery($request);
    }

    public function storeMessage(Request $request, string $supportCode, ReplyTemplateOptions $replyTemplateOptions, AttachmentBinder $binder): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'reply');

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'reply_template' => ['nullable', 'string', 'max:120'],
            'attachment_ids' => ['nullable', 'array'],
            'attachment_ids.*' => ['integer', 'min:1'],
        ]);

        $selectedTemplate = $validated['reply_template'] ?? null;
        $resolvedTemplate = null;
        $body = trim((string) ($validated['body'] ?? ''));
        $attachmentIds = $validated['attachment_ids'] ?? [];

        if ($selectedTemplate) {
            $resolvedTemplate = $replyTemplateOptions->resolve($agent, $selectedTemplate);

            if (! $resolvedTemplate) {
                throw ValidationException::withMessages([
                    'reply_template' => 'Choose an available reply helper.',
                ]);
            }
        }

        if ($body === '' && $resolvedTemplate) {
            $body = trim($resolvedTemplate['body']);
        }

        if ($body === '' && $attachmentIds === []) {
            throw ValidationException::withMessages([
                'body' => 'Please enter a reply or attach a file.',
            ]);
        }

        $message = DB::transaction(function () use ($conversation, $agent, $body, $resolvedTemplate, $attachmentIds, $binder) {
            $message = $conversation->messages()->create([
                'sender_type' => User::class,
                'sender_id' => $agent->id,
                'type' => 'text',
                'body' => $body === '' ? null : $body,
                'metadata' => $resolvedTemplate
                    ? $this->replyTemplateMetadata($resolvedTemplate)
                    : [],
            ]);

            // Bind the agent's own pending uploads to this reply. A bad
            // reference throws and rolls the whole send back.
            $binder->bind($conversation, $message, $attachmentIds, $agent);

            $previousStatus = (string) $conversation->status;

            $conversation->forceFill([
                'assigned_agent_id' => $conversation->assigned_agent_id ?: $agent->id,
                'status' => 'open',
                'closed_at' => null,
                'last_message_at' => $message->created_at,
                'metadata' => $this->metadataWithoutAgentTypingSignal($conversation, $agent),
            ])->save();

            // Replying to a closed conversation reopens it. That was silent
            // before -- indistinguishable from any other message.
            app(ConversationLifecycleLog::class)
                ->replyReopenedIfClosed($conversation, $agent, $previousStatus);

            return $message;
        });

        $this->markConversationNotificationsRead($agent, $conversation);
        $conversation->markReadFor($agent);

        event(new ConversationMessageCreated($message));

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Reply sent.');
    }

    /**
     * @param  array{key: string, label: string, body: string, managed_id?: int}  $resolvedTemplate
     * @return array<string, mixed>
     */
    private function replyTemplateMetadata(array $resolvedTemplate): array
    {
        if (array_key_exists('managed_id', $resolvedTemplate)) {
            return [
                'reply_template_id' => $resolvedTemplate['managed_id'],
                'reply_template_name' => $resolvedTemplate['label'],
            ];
        }

        return [
            'reply_template' => $resolvedTemplate['key'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataWithoutAgentTypingSignal(Conversation $conversation, User $agent): array
    {
        $metadata = $conversation->metadata ?? [];
        $typingSignals = $metadata['agent_typing'] ?? [];

        if (! is_array($typingSignals)) {
            unset($metadata['agent_typing']);

            return $metadata;
        }

        unset($typingSignals[(string) $agent->id]);

        if ($typingSignals === []) {
            unset($metadata['agent_typing']);
        } else {
            $metadata['agent_typing'] = $typingSignals;
        }

        return $metadata;
    }

    /**
     * Move a conversation to a status, and report what it was.
     *
     * The status is read from the locked row rather than from the instance the
     * request loaded, so concurrent transitions serialise: the second one sees
     * what the first actually did and records nothing.
     */
    private function transitionStatus(
        Conversation $conversation,
        string $status,
        ?CarbonInterface $closedAt,
        callable $record,
    ): void {
        DB::transaction(function () use ($conversation, $status, $closedAt, $record): void {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();
            $previousStatus = (string) ($locked?->status ?? $conversation->status);

            // Written through the LOCKED instance, not the one this request
            // loaded before it waited. Eloquent compares against the original
            // attributes it read: a reopen that loaded "open", then waited
            // behind a close, would find "open" unchanged and omit status from
            // the update -- leaving the row closed while the callback recorded
            // a reopen that never happened.
            $target = $locked ?? $conversation;

            $target->forceFill([
                'status' => $status,
                'closed_at' => $closedAt,
            ])->save();

            // Keep the caller's instance honest about what is now stored.
            $conversation->setRawAttributes($target->getAttributes(), true);

            // Written while the lock is still held. Committing the status first
            // and recording after lets a reopen that grabs the released lock
            // insert its event ahead of the close's -- a reopen->close sequence
            // for a conversation that ended up open, which is worse than no
            // history at all. It also means a failed insert cannot leave a
            // status change with nothing recording it.
            $record($previousStatus);
        });
    }

    public function close(Request $request, string $supportCode, ConversationLifecycleLog $lifecycle): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'updateStatus');

        // Read and write under one lock. The transition guard alone only stops
        // sequential retries: two concurrent closes both read "open" from their
        // own instance and both record a close, which is the duplicate the
        // guard exists to prevent.
        $this->transitionStatus(
            $conversation,
            'closed',
            now(),
            fn (string $previousStatus) => $lifecycle->closed($conversation, $agent, $previousStatus),
        );

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Conversation closed.');
    }

    public function reopen(Request $request, string $supportCode, ConversationLifecycleLog $lifecycle): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'updateStatus');

        $this->transitionStatus(
            $conversation,
            'open',
            null,
            fn (string $previousStatus) => $lifecycle->replyReopenedIfClosed($conversation, $agent, $previousStatus),
        );

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Conversation reopened.');
    }

    public function claim(Request $request, string $supportCode): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'view');

        abort_unless(Gate::forUser($agent)->allows('claim', $conversation), 403);

        $conversation->forceFill([
            'assigned_agent_id' => $agent->id,
        ])->save();

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Conversation claimed.');
    }

    public function release(Request $request, string $supportCode): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'view');

        abort_unless(Gate::forUser($agent)->allows('release', $conversation), 403);

        $conversation->forceFill([
            'assigned_agent_id' => null,
        ])->save();

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Conversation released.');
    }

    public function storeTicket(Request $request, string $supportCode, VisitorContextSanitizer $visitorContextSanitizer): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'createTicket')
            ->load(['site', 'visitor']);

        $validated = $request->validate([
            'category' => ['nullable', 'string', Rule::in(TicketCategory::values())],
            'priority' => ['nullable', 'string', Rule::in(TicketPriority::values())],
        ]);

        if ($conversation->tickets()->exists()) {
            return redirect()
                ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
                ->with('status', 'Ticket already exists.');
        }

        $ticket = $conversation->tickets()->create([
            'account_id' => $conversation->site->account_id,
            'site_id' => $conversation->site_id,
            'requester_id' => $conversation->visitor_id,
            'assignee_id' => $agent->id,
            'status' => 'open',
            'priority' => $validated['priority'] ?? 'normal',
            'category' => $validated['category'] ?? null,
            'subject' => $conversation->subject ?: 'Conversation '.$conversation->support_code,
            'description' => $this->ticketDescription($conversation),
            'metadata' => [
                'source' => 'conversation',
                'description_source' => 'conversation_transcript',
                'support_code' => $conversation->support_code,
                'visitor_context' => $this->ticketVisitorContext($conversation, $visitorContextSanitizer),
            ],
        ]);

        $ticket->auditEvents()->create([
            'account_id' => $ticket->account_id,
            'site_id' => $ticket->site_id,
            'actor_type' => User::class,
            'actor_id' => $agent->id,
            'action' => 'ticket.created',
            'metadata' => [
                'source' => 'conversation',
                'support_code' => $conversation->support_code,
            ],
            'occurred_at' => now(),
        ]);

        if (! $conversation->assigned_agent_id) {
            $conversation->forceFill([
                'assigned_agent_id' => $agent->id,
            ])->save();
        }

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Ticket created.');
    }

    /**
     * Return the latest server-sanitized cobrowse replay preview as JSON so the
     * agent dashboard can refresh it live in place without a full page reload.
     * This reuses the exact CobrowseConsentState shape the page renders, so the
     * broadcast path never carries raw page HTML — the sanitizer stays the
     * enforcement boundary on every refresh.
     */
    public function cobrowsePreview(Request $request, string $supportCode, CobrowseConsentState $cobrowseConsentState, CobrowseAuditTrail $cobrowseAuditTrail): JsonResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'view');

        $state = $cobrowseConsentState->forConversation($conversation);
        $this->recordCobrowsePreviewView($conversation, $agent, $cobrowseAuditTrail, $state, 'live_refresh');

        return response()->json([
            'data' => [
                'status' => $state['status'] ?? 'unavailable',
                'replay_preview' => $state['replay_preview'] ?? null,
                'snapshot' => [
                    'freshness' => $state['snapshot']['freshness'] ?? null,
                ],
            ],
        ]);
    }

    public function requestCobrowse(Request $request, string $supportCode): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'requestCobrowse')
            ->load(['site', 'visitor']);

        if ($this->activeCobrowseSession($conversation)) {
            return redirect()
                ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
                ->with('status', 'Cobrowse request already active.');
        }

        $cobrowseSession = $conversation->cobrowseSessions()->create([
            'site_id' => $conversation->site_id,
            'visitor_id' => $conversation->visitor_id,
            'requested_by_id' => $agent->id,
            'status' => 'requested',
            'metadata' => [],
            'consented_at' => null,
            'ended_at' => null,
        ]);

        event(new CobrowseStateUpdated($cobrowseSession, 'consent_requested'));

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Cobrowse requested.');
    }

    public function endCobrowse(Request $request, string $supportCode): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'endCobrowse');
        $cobrowseSession = $this->activeCobrowseSession($conversation);

        if (! $cobrowseSession) {
            return redirect()
                ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
                ->with('status', 'No active cobrowse session.');
        }

        $cobrowseSession = $cobrowseSession->updateAtomically(function (CobrowseSession $session) use ($agent): void {
            $metadata = $session->metadata ?? [];
            $metadata['ended_by_id'] = $agent->id;
            $metadata['ended_by_name'] = $agent->name;
            $metadata['ended_by_type'] = 'agent';

            $session->forceFill([
                'status' => 'ended',
                'metadata' => $metadata,
                'ended_at' => now(),
            ]);
        });

        event(new CobrowseStateUpdated($cobrowseSession, 'ended'));

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Cobrowse session ended.');
    }

    public function requestCobrowseResync(Request $request, string $supportCode, CobrowseResyncRequestPolicy $resyncRequestPolicy, CobrowseAuditTrail $cobrowseAuditTrail): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'requestCobrowse');
        $cobrowseSession = $this->activeCobrowseSession($conversation);

        if (! $cobrowseSession || $cobrowseSession->status !== 'granted') {
            return redirect()
                ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
                ->with('status', 'Cobrowse must be active before requesting a fresh snapshot.');
        }

        $isActive = true;
        $alreadyPending = false;
        $newRequest = null;
        $previousRequest = null;

        $cobrowseSession = $cobrowseSession->updateMetadataAtomically(function (array $metadata, CobrowseSession $session) use ($agent, $resyncRequestPolicy, &$isActive, &$alreadyPending, &$newRequest, &$previousRequest): array {
            if ($session->status !== 'granted' || $session->ended_at) {
                $isActive = false;

                return $metadata;
            }

            $currentRequest = $metadata['resync_request'] ?? null;

            if (is_array($currentRequest) && $resyncRequestPolicy->isFreshPending($currentRequest)) {
                $alreadyPending = true;

                return $metadata;
            }

            $previousRequest = is_array($currentRequest) ? $currentRequest : null;
            $newRequest = [
                'id' => 'resync_'.Str::lower((string) Str::ulid()),
                'requested_by_id' => $agent->id,
                'requested_by_name' => $agent->name,
                'requested_at' => now()->toJSON(),
                'fulfilled_at' => null,
            ];
            $metadata['resync_request'] = $newRequest;

            return $metadata;
        });

        if (! $isActive) {
            return redirect()
                ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
                ->with('status', 'Cobrowse must be active before requesting a fresh snapshot.');
        }

        if ($alreadyPending) {
            return redirect()
                ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
                ->with('status', 'Fresh cobrowse snapshot already requested.');
        }

        if (is_array($newRequest)) {
            $cobrowseAuditTrail->resyncRequested($cobrowseSession, $agent, $newRequest, $previousRequest);
        }

        event(new CobrowseStateUpdated($cobrowseSession, 'resync_requested'));

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Fresh cobrowse snapshot requested.');
    }

    private function conversationForAgent(User $agent, string $supportCode, string $ability): Conversation
    {
        abort_unless($agent->account_id, 403);

        $conversation = Conversation::query()
            ->where('support_code', $supportCode)
            ->firstOrFail();

        abort_unless(Gate::forUser($agent)->allows($ability, $conversation), 404);

        return $conversation;
    }

    private function supportAgentsForSite(Site $site): Collection
    {
        $supportAgents = $site->eligibleSupportAgents()
            ->orderBy('name')
            ->get();

        return $supportAgents->isNotEmpty()
            ? $supportAgents
            : $site->account->agents()
                ->whereNull('deactivated_at')
                ->orderBy('name')
                ->get();
    }

    /**
     * @return array{anonymous_id: string, external_id: string|null, last_seen_at: Carbon|null, presence: array{state: string, label: string, detail: string}, last_page_url: string|null, started_page_url: string|null, host_context: array<string, string>}
     */
    private function visitorContext(Conversation $conversation, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        $visitor = $conversation->visitor;
        $visitorMetadata = $visitor?->metadata ?? [];
        $conversationMetadata = $conversation->metadata ?? [];

        return [
            'anonymous_id' => $visitor?->anonymous_id ?? 'Unknown visitor',
            'external_id' => $visitorContextSanitizer->sanitizeIdentifier($visitor?->external_id),
            'last_seen_at' => $visitor?->last_seen_at,
            'presence' => [
                'state' => $visitor?->presenceState() ?? 'unknown',
                'label' => $visitor?->presenceLabel() ?? 'Not reported',
                'detail' => $visitor?->presenceDetail() ?? 'No visitor heartbeat yet.',
            ],
            'last_page_url' => $this->contextString($visitorMetadata['last_page_url'] ?? null),
            'started_page_url' => $this->contextString($conversationMetadata['started_page_url'] ?? null),
            'host_context' => $visitorContextSanitizer->sanitize($visitorMetadata['context'] ?? []),
        ];
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function priorConversations(Conversation $conversation): Collection
    {
        return Conversation::query()
            ->with(['assignedAgent', 'tickets'])
            ->where('site_id', $conversation->site_id)
            ->where('visitor_id', $conversation->visitor_id)
            ->whereKeyNot($conversation->id)
            ->latest('last_message_at')
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    private function contextString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 2048);
    }

    /**
     * @return array{last_page_url: string|null, started_page_url: string|null, host_context: array<string, string>}
     */
    private function ticketVisitorContext(Conversation $conversation, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        $visitorMetadata = $conversation->visitor?->metadata ?? [];
        $conversationMetadata = $conversation->metadata ?? [];

        return [
            'last_page_url' => $this->contextString($visitorMetadata['last_page_url'] ?? null),
            'started_page_url' => $this->contextString($conversationMetadata['started_page_url'] ?? null),
            'host_context' => $visitorContextSanitizer->sanitize($visitorMetadata['context'] ?? []),
        ];
    }

    private function activeCobrowseSession(Conversation $conversation): ?CobrowseSession
    {
        return $conversation->cobrowseSessions()
            ->whereNull('ended_at')
            ->whereIn('status', ['requested', 'granted'])
            ->latest('id')
            ->first();
    }

    /**
     * Audit that the agent saw a rendered replay preview. Only fires when a
     * preview actually exists — loading a conversation with no snapshot is not
     * "seeing the visitor's screen". Throttling lives in the audit trail.
     *
     * @param  array<string, mixed>  $cobrowseState
     */
    private function recordCobrowsePreviewView(Conversation $conversation, User $agent, CobrowseAuditTrail $cobrowseAuditTrail, array $cobrowseState, string $trigger): void
    {
        $preview = $cobrowseState['replay_preview'] ?? null;

        if (! is_array($preview)) {
            return;
        }

        $session = $conversation->cobrowseSessions()->latest('id')->first();

        if ($session) {
            $cobrowseAuditTrail->previewViewed($session, $agent, $trigger, $preview);
        }
    }

    private function markConversationNotificationsRead(User $agent, Conversation $conversation): void
    {
        $agent->unreadNotifications()
            ->where('type', ConversationNeedsReply::class)
            ->get()
            ->filter(fn ($notification): bool => (int) data_get($notification->data, 'conversation_id') === $conversation->id)
            ->each
            ->markAsRead();
    }

    private function ticketDescription(Conversation $conversation): string
    {
        $messages = $conversation->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(function ($message): ?string {
                $body = trim((string) $message->body);

                if ($body === '') {
                    return null;
                }

                $senderName = $message->sender_type === User::class
                    ? ($message->sender?->name ?? 'Agent')
                    : 'Visitor';

                return $senderName.': '.$body;
            })
            ->filter()
            ->implode(PHP_EOL.PHP_EOL);

        if ($messages === '') {
            return 'Created from conversation '.$conversation->support_code.'.';
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function realtimeConfig(Conversation $conversation): ?array
    {
        if ((string) config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $key = config('broadcasting.connections.reverb.key');
        // Browser-facing values: in containerized installs the server-side
        // host is an internal service address the browser cannot reach.
        // Single-endpoint deployments set no client_* values and fall back.
        $host = config('broadcasting.connections.reverb.options.client_host')
            ?? config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.client_port')
            ?? config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.client_scheme')
            ?? config('broadcasting.connections.reverb.options.scheme');

        if (! $this->hasConfigValue($key) || ! $this->hasConfigValue($host) || ! $this->hasConfigValue($port) || ! $this->hasConfigValue($scheme)) {
            return null;
        }

        return [
            'appKey' => (string) $key,
            'authEndpoint' => url('/broadcasting/auth'),
            'channelName' => 'private-conversations.'.$conversation->support_code,
            'eventName' => 'conversation.cobrowse.updated',
            'host' => (string) $host,
            'messageEventName' => 'conversation.message.created',
            'messagesUrl' => route('dashboard.conversations.messages.index', $conversation->support_code),
            'port' => (int) $port,
            'previewUrl' => route('dashboard.conversations.cobrowse.preview', $conversation->support_code),
            'readEventName' => 'conversation.read.updated',
            'scheme' => (string) $scheme,
            'presenceEventName' => 'conversation.presence.updated',
            'typingEventName' => 'conversation.typing.updated',
            'visitorTypingFreshMs' => Conversation::visitorTypingFreshMilliseconds(),
        ];
    }

    private function hasConfigValue(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null;
    }
}
