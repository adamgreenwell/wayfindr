<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\Visitor;
use App\Support\VisitorContextSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class AgentVisitorController extends Controller
{
    public function show(Request $request, Visitor $visitor, VisitorContextSanitizer $visitorContextSanitizer): View
    {
        $agent = $request->user();

        abort_unless(Gate::forUser($agent)->allows('view', $visitor), 404);

        $visitor->loadMissing('site.account');
        $conversations = $this->visitorConversations($visitor);

        return view('agent.visitors.show', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'conversations' => $conversations,
            'tickets' => $this->visitorTickets($visitor),
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
            ->with(['assignedAgent', 'tickets'])
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
            ->with(['assignee', 'conversation'])
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
