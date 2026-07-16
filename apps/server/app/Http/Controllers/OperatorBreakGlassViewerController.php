<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\BreakGlass\BreakGlassGrants;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The scoped read-only viewers (ADR 0008, slice 3): what an active grant
 * actually opens. Requester-only; every route re-derives coverage from the
 * RESOURCE side via the grant's covers* checks, so a guessed URL outside the
 * scope 404s. Read-only means read-only: transcripts and ticket fields render
 * as text, attachments appear as metadata with no way to reach the binary,
 * and nothing here can send, edit, or impersonate.
 */
class OperatorBreakGlassViewerController extends Controller
{
    public function show(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): View
    {
        $this->usableGrant($request, $grant);

        $grants->recordOpened($grant, $request->user());

        return view('operator.break-glass-grant', [
            'grant' => $grant->loadMissing(['account', 'conversation', 'site']),
            'coveredConversations' => $this->coveredConversations($grant),
            'coveredTickets' => $this->coveredTickets($grant),
        ]);
    }

    public function conversation(Request $request, BreakGlassGrant $grant, Conversation $conversation, BreakGlassGrants $grants): View
    {
        $this->usableGrant($request, $grant);

        abort_unless($grant->coversConversation($conversation), 404);

        $grants->recordResourceViewed(
            $grant,
            $request->user(),
            'conversation',
            (int) $conversation->id,
            'Conversation '.$conversation->support_code,
        );

        $messages = $conversation->messages()
            ->with(['sender', 'attachments'])
            ->orderBy('id')
            ->get();

        return view('operator.break-glass-conversation', [
            'grant' => $grant,
            'conversation' => $conversation->loadMissing('site'),
            'messages' => $messages,
            'senderLabels' => $messages->mapWithKeys(fn ($message): array => [
                $message->id => $this->senderLabel($message->sender),
            ]),
            'tickets' => $conversation->tickets()->orderByDesc('id')->get(),
        ]);
    }

    public function ticket(Request $request, BreakGlassGrant $grant, Ticket $ticket, BreakGlassGrants $grants): View
    {
        $this->usableGrant($request, $grant);

        abort_unless($grant->coversTicket($ticket), 404);

        $grants->recordResourceViewed(
            $grant,
            $request->user(),
            'ticket',
            (int) $ticket->id,
            sprintf('Ticket #%d — %s', $ticket->id, $ticket->subject),
        );

        return view('operator.break-glass-ticket', [
            'grant' => $grant,
            'ticket' => $ticket->loadMissing(['site', 'conversation']),
        ]);
    }

    /**
     * The viewers open only for the grant's own requester, and only while the
     * grant is active — expiry is checked live, so a stale tab dies with the
     * grant.
     */
    private function usableGrant(Request $request, BreakGlassGrant $grant): void
    {
        abort_unless((int) $grant->requester_id === (int) $request->user()->id, 404);
        abort_unless($grant->isActive(), 403, 'This grant is not active.');
    }

    /**
     * @return Collection<int, Conversation>
     */
    private function coveredConversations(BreakGlassGrant $grant): Collection
    {
        $query = Conversation::query()->with('site')->latest('id')->limit(50);

        return match ($grant->scope_type) {
            BreakGlassGrant::SCOPE_CONVERSATION => $grant->conversation_id
                ? $query->whereKey($grant->conversation_id)->get()
                : collect(),
            BreakGlassGrant::SCOPE_SITE => $grant->site_id
                ? $query->where('site_id', $grant->site_id)->get()
                : collect(),
            BreakGlassGrant::SCOPE_ACCOUNT => $query
                ->whereIn('site_id', Site::query()->where('account_id', $grant->account_id)->select('id'))
                ->get(),
            default => collect(),
        };
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function coveredTickets(BreakGlassGrant $grant): Collection
    {
        $query = Ticket::query()->latest('id')->limit(50);

        return match ($grant->scope_type) {
            BreakGlassGrant::SCOPE_CONVERSATION => $grant->conversation_id
                ? $query->where('conversation_id', $grant->conversation_id)->get()
                : collect(),
            BreakGlassGrant::SCOPE_SITE => $grant->site_id
                ? $query->where('site_id', $grant->site_id)->get()
                : collect(),
            BreakGlassGrant::SCOPE_ACCOUNT => $query
                ->where('account_id', $grant->account_id)
                ->whereIn('site_id', Site::query()->where('account_id', $grant->account_id)->select('id'))
                ->get(),
            default => collect(),
        };
    }

    private function senderLabel(?object $sender): string
    {
        return match (true) {
            $sender instanceof Visitor => 'Visitor',
            $sender instanceof User => $sender->name.' (agent)',
            default => 'System',
        };
    }
}
