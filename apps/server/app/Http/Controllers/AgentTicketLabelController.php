<?php

namespace App\Http\Controllers;

use App\Models\TicketLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentTicketLabelController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->isAdmin(), 403);

        $account = $agent->account()->firstOrFail();

        return view('agent.ticket-labels.index', [
            'account' => $account,
            'agent' => $agent,
            'ticketLabels' => $account->ticketLabels()
                ->withCount('tickets')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->isAdmin(), 403);

        $account = $agent->account()->firstOrFail();
        $label = $this->validatedLabelInput($request);

        if ($this->accountLabelSlugExists((int) $account->id, $label['slug'])) {
            throw ValidationException::withMessages([
                'label_name' => 'That label already exists for this account.',
            ]);
        }

        $account->ticketLabels()->create($label);

        return redirect()
            ->route('dashboard.account.labels.index')
            ->with('status', 'Ticket label created.');
    }

    public function update(Request $request, TicketLabel $ticketLabel): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageLabel($agent, $ticketLabel);
        $label = $this->validatedLabelInput($request);

        if ($this->labelSlugExists($ticketLabel, $label['slug'])) {
            throw ValidationException::withMessages([
                'label_name' => 'That label already exists for this account.',
            ]);
        }

        $ticketLabel->forceFill($label)->save();

        return redirect()
            ->route('dashboard.account.labels.index')
            ->with('status', 'Ticket label renamed.');
    }

    public function destroy(Request $request, TicketLabel $ticketLabel): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageLabel($agent, $ticketLabel);

        if ($ticketLabel->tickets()->exists()) {
            throw ValidationException::withMessages([
                'label' => 'Remove this label from tickets before deleting it.',
            ]);
        }

        $ticketLabel->delete();

        return redirect()
            ->route('dashboard.account.labels.index')
            ->with('status', 'Unused ticket label deleted.');
    }

    private function authorizeManageLabel(mixed $agent, TicketLabel $ticketLabel): void
    {
        abort_unless(
            $agent?->isAdmin()
            && $agent->account_id !== null
            && (int) $agent->account_id === (int) $ticketLabel->account_id,
            404,
        );
    }

    /**
     * @return array{name: string, slug: string}
     */
    private function validatedLabelInput(Request $request): array
    {
        $validated = $request->validate([
            'label_name' => ['required', 'string', 'max:64'],
        ]);

        $name = TicketLabel::normalizeName($validated['label_name']);
        $slug = TicketLabel::slugForName($name);

        if ($name === '' || $slug === '') {
            throw ValidationException::withMessages([
                'label_name' => 'Use at least one letter or number for the label.',
            ]);
        }

        if (TicketLabel::isReservedSlug($slug)) {
            throw ValidationException::withMessages([
                'label_name' => 'That label name is reserved for ticket filtering.',
            ]);
        }

        return [
            'name' => $name,
            'slug' => $slug,
        ];
    }

    private function accountLabelSlugExists(int $accountId, string $slug): bool
    {
        return TicketLabel::query()
            ->where('account_id', $accountId)
            ->where('slug', $slug)
            ->exists();
    }

    private function labelSlugExists(TicketLabel $ticketLabel, string $slug): bool
    {
        return TicketLabel::query()
            ->where('account_id', $ticketLabel->account_id)
            ->where('slug', $slug)
            ->whereKeyNot($ticketLabel->id)
            ->exists();
    }
}
