<?php

namespace App\Http\Controllers;

use App\Events\CobrowseStateUpdated;
use App\Events\ConversationMessageCreated;
use App\Models\CobrowseSession;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\User;
use App\Notifications\ConversationNeedsReply;
use App\Support\CobrowseAuditTrail;
use App\Support\CobrowseConsentState;
use App\Support\CobrowseResyncRequestPolicy;
use App\Support\ReplyTemplateOptions;
use App\Support\TicketCategory;
use App\Support\TicketPriority;
use App\Support\VisitorContextSanitizer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgentConversationController extends Controller
{
    public function show(Request $request, string $supportCode, CobrowseConsentState $cobrowseConsentState, VisitorContextSanitizer $visitorContextSanitizer, ReplyTemplateOptions $replyTemplateOptions, CobrowseAuditTrail $cobrowseAuditTrail): View
    {
        $agent = $request->user();

        $conversation = $this->conversationForAgent($agent, $supportCode, 'view')
            ->load(['assignedAgent', 'latestAgentMessage', 'latestMessage', 'site', 'visitor']);

        $this->markConversationNotificationsRead($agent, $conversation);
        $conversation->markReadFor($agent);

        $cobrowseConsent = $cobrowseConsentState->forConversation($conversation);
        $this->recordCobrowsePreviewView($conversation, $agent, $cobrowseAuditTrail, $cobrowseConsent, 'page_view');

        $messages = $conversation->messages()
            ->with('sender')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $tickets = $conversation->tickets()
            ->with(['assignee', 'conversation.latestAgentMessage', 'conversation.latestMessage'])
            ->latest()
            ->get();
        $conversationReturnQuery = $this->conversationQueueReturnQuery($request);

        return view('agent.conversations.show', [
            'account' => $agent->account()->firstOrFail(),
            'accountAgents' => $this->supportAgentsForSite($conversation->site),
            'agent' => $agent,
            'cobrowseConsent' => $cobrowseConsent,
            'conversation' => $conversation,
            'conversationBackUrl' => route('dashboard.conversations.index', $conversationReturnQuery),
            'conversationReturnQuery' => $conversationReturnQuery,
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
            ->with('sender')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->view('agent.conversations.partials.message-list', [
            'emptyMessage' => 'No messages yet.',
            'transcriptMessages' => $messages,
        ]);
    }

    /**
     * @return array<string, string|int>
     */
    private function conversationQueueReturnQuery(Request $request): array
    {
        $params = [];
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

    public function storeMessage(Request $request, string $supportCode, ReplyTemplateOptions $replyTemplateOptions): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'reply');

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'reply_template' => ['nullable', 'string', 'max:120'],
        ]);

        $selectedTemplate = $validated['reply_template'] ?? null;
        $resolvedTemplate = null;
        $body = trim((string) ($validated['body'] ?? ''));

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

        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Please enter a reply.',
            ]);
        }

        $message = $conversation->messages()->create([
            'sender_type' => User::class,
            'sender_id' => $agent->id,
            'type' => 'text',
            'body' => $body,
            'metadata' => $resolvedTemplate
                ? $this->replyTemplateMetadata($resolvedTemplate)
                : [],
        ]);

        $conversation->forceFill([
            'assigned_agent_id' => $conversation->assigned_agent_id ?: $agent->id,
            'status' => 'open',
            'closed_at' => null,
            'last_message_at' => $message->created_at,
            'metadata' => $this->metadataWithoutAgentTypingSignal($conversation, $agent),
        ])->save();

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

    public function close(Request $request, string $supportCode): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'updateStatus');

        $conversation->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
        ])->save();

        return redirect()
            ->route('dashboard.conversations.show', $this->conversationShowRouteParams($conversation, $request))
            ->with('status', 'Conversation closed.');
    }

    public function reopen(Request $request, string $supportCode): RedirectResponse
    {
        $agent = $request->user();
        $conversation = $this->conversationForAgent($agent, $supportCode, 'updateStatus');

        $conversation->forceFill([
            'status' => 'open',
            'closed_at' => null,
        ])->save();

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
        $host = config('broadcasting.connections.reverb.options.host');
        $port = config('broadcasting.connections.reverb.options.port');
        $scheme = config('broadcasting.connections.reverb.options.scheme');

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
