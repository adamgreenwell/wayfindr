<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;
use App\Support\Visitors\VisitorPresence;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AgentVisitorController extends Controller
{
    /**
     * Everyone this desk has heard from, most recently seen first.
     *
     * A profile page existed with no way to reach it except from a conversation
     * or a support-code lookup, so an agent could answer "tell me about this
     * visitor" and not "who has been here".
     *
     * Deliberately scoped to visitors who made contact. Wayfindr records a
     * visitor when the widget is opened, a conversation starts, a message moves,
     * or somebody types -- never on page load -- so this lists people who
     * reached out, not people who were watched. Whether it should ever mean the
     * latter is ADR 0016, and undecided.
     */
    public function index(Request $request): View
    {
        $agent = $request->user();
        $account = $agent->account()->firstOrFail();

        // Read before narrowing, exactly as the search and site filters below
        // do. Casting first meant `?presence[]=active` raised an "Array to
        // string conversion" warning, which Laravel turns into an
        // ErrorException -- a 500 from a query string anybody can type.
        $presence = $request->query('presence', 'all');
        $presence = is_string($presence) && in_array($presence, VisitorPresence::states(), true)
            ? $presence
            : 'all';

        $search = $request->query('search', '');
        $search = is_string($search) ? mb_substr(trim($search), 0, 120) : '';

        $siteId = $request->query('site', '');
        $visibleSites = $account->sites()->visibleToAgent($agent)->orderBy('name')->get();
        $siteIds = $visibleSites->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // A site id from the query string can never widen the scope: it is
        // checked against what this agent may already see.
        $siteId = is_string($siteId) && ctype_digit($siteId) && in_array((int) $siteId, $siteIds, true)
            ? (int) $siteId
            : null;

        $query = Visitor::query()
            ->with('site')
            ->whereIn('site_id', $siteIds)
            // The hosted tester page creates real visitor rows. Without this an
            // agent watches themselves browse, which Site::latestVisitor()
            // already learned to exclude.
            ->where('anonymous_id', 'not like', 'tester-site-%');

        if ($siteId !== null) {
            $query->where('site_id', $siteId);
        }

        if ($presence !== 'all') {
            VisitorPresence::constrain($query, $presence);
        }

        if ($search !== '') {
            $pattern = '%'.$search.'%';

            $query->where(fn (Builder $inner) => $inner
                ->whereLike('name', $pattern)
                ->orWhereLike('email', $pattern)
                ->orWhereLike('external_id', $pattern)
                ->orWhereLike('anonymous_id', $pattern));
        }

        $visitors = $query
            ->withCount('conversations')
            ->orderByRaw('last_seen_at is null')
            ->latest('last_seen_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('agent.visitors.index', [
            'account' => $account,
            'agent' => $agent,
            'presence' => $presence,
            'search' => $search,
            'siteId' => $siteId,
            'sites' => $visibleSites,
            'visitors' => $visitors,
        ]);
    }

    public function show(Request $request, Visitor $visitor, VisitorContextSanitizer $visitorContextSanitizer): View
    {
        $agent = $request->user();

        abort_unless(Gate::forUser($agent)->allows('view', $visitor), 404);

        $visitor->loadMissing('site.account');
        $conversations = $this->visitorConversations($visitor);
        $tickets = $this->visitorTickets($visitor);

        return view('agent.visitors.show', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'conversations' => $conversations,
            'supportSnapshot' => $this->supportSnapshot($visitor),
            'supportReferences' => $this->supportReferences($visitor, $tickets, $visitorContextSanitizer),
            'tickets' => $tickets,
            'visitor' => $visitor,
            'visitorContext' => $this->visitorContext($visitor, $visitorContextSanitizer),
        ]);
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function visitorConversations(Visitor $visitor): Collection
    {
        return Conversation::query()
            ->with(['assignedAgent', 'latestMessage', 'tickets'])
            ->where('site_id', $visitor->site_id)
            ->where('visitor_id', $visitor->id)
            ->latest('last_message_at')
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function visitorTickets(Visitor $visitor): Collection
    {
        return Ticket::query()
            ->with(['assignee', 'conversation.latestMessage'])
            ->where('account_id', $visitor->site->account_id)
            ->where('site_id', $visitor->site_id)
            ->where('requester_id', $visitor->id)
            ->latest('updated_at')
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * @return array{anonymous_id: string, external_id: string|null, last_seen_at: CarbonInterface|null, last_page_url: string|null, first_started_page_url: string|null, host_context: array<string, string>}
     */
    private function visitorContext(Visitor $visitor, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        $visitorMetadata = $visitor->metadata ?? [];

        return [
            'anonymous_id' => $visitor->anonymous_id,
            'external_id' => $visitorContextSanitizer->sanitizeIdentifier($visitor->external_id),
            'last_seen_at' => $visitor->last_seen_at,
            'last_page_url' => $this->contextString($visitorMetadata['last_page_url'] ?? null),
            'first_started_page_url' => $this->firstStartedPageUrl($visitor),
            'host_context' => $visitorContextSanitizer->sanitize($visitorMetadata['context'] ?? []),
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array{visitor_reference: string, host_visitor_id: string|null, latest_conversation: Conversation|null, latest_ticket: Ticket|null}
     */
    private function supportReferences(Visitor $visitor, Collection $tickets, VisitorContextSanitizer $visitorContextSanitizer): array
    {
        return [
            'visitor_reference' => $visitor->anonymous_id,
            'host_visitor_id' => $visitorContextSanitizer->sanitizeIdentifier($visitor->external_id),
            'latest_conversation' => $this->latestConversationReference($visitor),
            'latest_ticket' => $tickets->first(),
        ];
    }

    /**
     * @return array{active_conversation_label: string, active_ticket_label: string, next_action: array{body: string, cta: string|null, href: string|null, title: string}, status_label: string, tone: string}
     */
    private function supportSnapshot(Visitor $visitor): array
    {
        $activeConversations = $this->activeConversationCandidates($visitor);
        $activeTickets = $this->activeTicketCandidates($visitor);

        return [
            'active_conversation_label' => $this->countLabel($activeConversations->count(), 'active conversation', 'active conversations'),
            'active_ticket_label' => $this->countLabel($activeTickets->count(), 'active ticket', 'active tickets'),
            ...$this->supportSnapshotAction($activeConversations, $activeTickets),
        ];
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @param  Collection<int, Ticket>  $tickets
     * @return array{next_action: array{body: string, cta: string|null, href: string|null, title: string}, status_label: string, tone: string}
     */
    private function supportSnapshotAction(Collection $conversations, Collection $tickets): array
    {
        $conversationNeedingReply = $conversations->first(fn (Conversation $conversation): bool => $conversation->latestMessage !== null
            && $conversation->attentionState() === 'needs_reply');

        if ($conversationNeedingReply) {
            return [
                'next_action' => [
                    'body' => 'Visitor replied last. Open the latest support item before scanning older history.',
                    'cta' => 'Reply to visitor',
                    'href' => route('dashboard.conversations.show', $conversationNeedingReply->support_code).'#reply-heading',
                    'title' => 'Reply to visitor',
                ],
                'status_label' => 'Needs reply',
                'tone' => 'attention',
            ];
        }

        $ticketNeedingAction = $tickets
            ->filter(fn (Ticket $ticket): bool => in_array($ticket->attentionState(), ['needs_reply', 'needs_owner', 'needs_agent'], true))
            ->sortBy(fn (Ticket $ticket): int => $ticket->attentionSortRank())
            ->first();

        if ($ticketNeedingAction) {
            $nextAction = $ticketNeedingAction->nextAction();

            return [
                'next_action' => [
                    'body' => $nextAction['body'],
                    'cta' => $nextAction['cta'],
                    'href' => route('dashboard.tickets.show', $ticketNeedingAction).$nextAction['href'],
                    'title' => $nextAction['title'],
                ],
                'status_label' => $ticketNeedingAction->attentionLabel(),
                'tone' => $ticketNeedingAction->attentionState() === 'needs_reply' ? 'attention' : 'manual',
            ];
        }

        $emptyConversation = $conversations->first(fn (Conversation $conversation): bool => $conversation->latestMessage === null);

        if ($emptyConversation) {
            $nextAction = $emptyConversation->nextAction();

            return [
                'next_action' => [
                    'body' => $nextAction['body'],
                    'cta' => $nextAction['cta'],
                    'href' => route('dashboard.conversations.show', $emptyConversation->support_code).$nextAction['href'],
                    'title' => $nextAction['title'],
                ],
                'status_label' => 'Review context',
                'tone' => 'manual',
            ];
        }

        $waitingConversation = $conversations->first();

        if ($waitingConversation) {
            return [
                'next_action' => [
                    'body' => 'No visitor reply is waiting right now. Keep the thread visible and respond when the visitor comes back.',
                    'cta' => 'Review conversation',
                    'href' => route('dashboard.conversations.show', $waitingConversation->support_code),
                    'title' => 'Waiting on visitor',
                ],
                'status_label' => 'Waiting',
                'tone' => 'ready',
            ];
        }

        $waitingTicket = $tickets->first();

        if ($waitingTicket) {
            return [
                'next_action' => [
                    'body' => 'No visitor reply is waiting right now. Review the active ticket when follow-up is due.',
                    'cta' => 'Review ticket',
                    'href' => route('dashboard.tickets.show', $waitingTicket),
                    'title' => 'Ticket in progress',
                ],
                'status_label' => 'In progress',
                'tone' => 'ready',
            ];
        }

        return [
            'next_action' => [
                'body' => 'No active support work is attached to this visitor.',
                'cta' => null,
                'href' => null,
                'title' => 'No active work',
            ],
            'status_label' => 'Clear',
            'tone' => 'ready',
        ];
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function activeConversationCandidates(Visitor $visitor): Collection
    {
        return Conversation::query()
            ->with('latestMessage')
            ->where('site_id', $visitor->site_id)
            ->where('visitor_id', $visitor->id)
            ->where('status', '!=', 'closed')
            ->latest('last_message_at')
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function activeTicketCandidates(Visitor $visitor): Collection
    {
        return Ticket::query()
            ->with('conversation.latestMessage')
            ->where('account_id', $visitor->site->account_id)
            ->where('site_id', $visitor->site_id)
            ->where('requester_id', $visitor->id)
            ->where('status', '!=', 'closed')
            ->latest('updated_at')
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    private function countLabel(int $count, string $singular, string $plural): string
    {
        return $count.' '.($count === 1 ? $singular : $plural);
    }

    private function latestConversationReference(Visitor $visitor): ?Conversation
    {
        return Conversation::query()
            ->where('site_id', $visitor->site_id)
            ->where('visitor_id', $visitor->id)
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->latest('id')
            ->first();
    }

    private function firstStartedPageUrl(Visitor $visitor): ?string
    {
        return Conversation::query()
            ->where('site_id', $visitor->site_id)
            ->where('visitor_id', $visitor->id)
            ->oldest('created_at')
            ->oldest('id')
            ->cursor()
            ->map(fn (Conversation $conversation): ?string => $this->contextString(data_get($conversation->metadata, 'started_page_url')))
            ->first(fn (?string $url): bool => $url !== null);
    }

    private function contextString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 2048);
    }
}
