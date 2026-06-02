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

        $supportCode = Str::upper(trim($rawSupportCode));

        if ($supportCode === '') {
            return $this->missingCode();
        }

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
            ->with('support_code_lookup_status', 'Enter a support code to find a conversation or ticket.');
    }
}
