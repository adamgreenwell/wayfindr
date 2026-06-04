<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Ticket;
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

            return $this->notFound($lookupReference);
        }

        $supportCode = Str::upper($lookupReference);

        $conversation = Conversation::query()
            ->with('site')
            ->where('support_code', $supportCode)
            ->first();

        if (! $conversation || Gate::forUser($agent)->denies('view', $conversation)) {
            return $this->notFound($supportCode);
        }

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
            ->with('support_code_lookup_status', 'Enter a support code or ticket reference to find a conversation or ticket.');
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
}
