<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AgentSupportCodeLookupController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $rawSupportCode = $request->query('support_code');

        if (! is_string($rawSupportCode)) {
            return $this->missingCode();
        }

        $lookupReference = trim($rawSupportCode);

        if ($lookupReference === '') {
            return $this->missingCode();
        }

        if ($request->query('reference_type') === 'visitor') {
            $visitor = $this->visibleVisitor($lookupReference, $agent);

            if ($visitor) {
                return redirect()->route('dashboard.visitors.show', $visitor);
            }

            return $this->notFound($lookupReference);
        }

        $ticketReferenceId = $this->ticketReferenceId($lookupReference);

        if ($ticketReferenceId) {
            $ticket = Ticket::query()
                ->with('site')
                ->where('account_id', $agent->account_id)
                ->whereKey($ticketReferenceId)
                ->first();

            if ($ticket && Gate::forUser($agent)->allows('view', $ticket)) {
                return redirect()->route('dashboard.tickets.show', $ticket);
            }

            if (! ctype_digit($lookupReference)) {
                return $this->notFound($lookupReference);
            }
        }

        $supportCode = Str::upper($lookupReference);

        $conversation = Conversation::query()
            ->with('site')
            ->where('support_code', $supportCode)
            ->first();

        if ($conversation && Gate::forUser($agent)->denies('view', $conversation)) {
            return $this->notFound($supportCode);
        }

        if ($conversation) {
            $ticket = $conversation->tickets()
                ->with('site')
                ->where('account_id', $agent->account_id)
                ->latest('updated_at')
                ->latest('id')
                ->get()
                ->first(fn (Ticket $ticket): bool => Gate::forUser($agent)->allows('view', $ticket));

            if ($ticket) {
                return redirect()->route('dashboard.tickets.show', $ticket);
            }

            return redirect()->route('dashboard.conversations.show', $conversation->support_code);
        }

        $visitor = $this->visibleVisitor($lookupReference, $agent);

        if ($visitor && Gate::forUser($agent)->allows('view', $visitor)) {
            return redirect()->route('dashboard.visitors.show', $visitor);
        }

        return $this->notFound($this->displayReference($lookupReference, $supportCode));
    }

    private function notFound(string $supportCode): RedirectResponse
    {
        return redirect()
            ->route('dashboard')
            ->with('support_code_lookup_status', 'No visible support record found for '.$supportCode.'.');
    }

    private function missingCode(): RedirectResponse
    {
        return redirect()
            ->route('dashboard')
            ->with('support_code_lookup_status', 'Enter a support code, ticket reference, or visitor ID to find a support trail.');
    }

    private function ticketReferenceId(string $lookupReference): ?int
    {
        $lookupReference = trim($lookupReference);

        if ($lookupReference === '') {
            return null;
        }

        if (ctype_digit($lookupReference)) {
            return $this->validTicketId((int) $lookupReference);
        }

        if (preg_match('/^(?:ticket\s*)?#\s*(\d+)$/i', $lookupReference, $matches)) {
            return $this->validTicketId((int) $matches[1]);
        }

        if (preg_match('/^ticket\s+(\d+)$/i', $lookupReference, $matches)) {
            return $this->validTicketId((int) $matches[1]);
        }

        return null;
    }

    private function validTicketId(int $ticketId): ?int
    {
        return $ticketId > 0 ? $ticketId : null;
    }

    private function visibleVisitor(string $lookupReference, User $agent): ?Visitor
    {
        return Visitor::query()
            ->with('site')
            ->where(function (Builder $query) use ($lookupReference): void {
                $query
                    ->where('anonymous_id', $lookupReference)
                    ->orWhere('external_id', $lookupReference);
            })
            ->whereHas('site', fn (Builder $query) => $query->visibleToAgent($agent))
            ->latest('last_seen_at')
            ->latest('id')
            ->first();
    }

    private function displayReference(string $lookupReference, string $supportCode): string
    {
        if (preg_match('/^wf-/i', $lookupReference)) {
            return $supportCode;
        }

        return $lookupReference;
    }
}
