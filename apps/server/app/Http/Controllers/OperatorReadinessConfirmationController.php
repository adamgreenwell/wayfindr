<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\OperatorReadinessConfirmation;
use App\Support\OperatorReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperatorReadinessConfirmationController extends Controller
{
    public function storeFromOperator(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformOperator(), 403);

        // Return the operator to wherever they confirmed from — the guided
        // onboarding checklist keeps its place instead of ejecting to the
        // dashboard. Only known operator destinations are honored (no open
        // redirect from user input).
        $redirectTo = match ((string) $request->input('redirect_to')) {
            'onboarding' => route('operator.onboarding'),
            default => route('operator.dashboard'),
        };

        return $this->store($request, $redirectTo);
    }

    private function store(Request $request, string $redirectTo): RedirectResponse
    {
        $agent = $request->user();
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(OperatorReadiness::confirmableKeys())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $note = trim((string) ($validated['note'] ?? ''));
        $existingConfirmation = OperatorReadinessConfirmation::query()
            ->where('key', $validated['key'])
            ->first();
        $storedNote = $note !== '' ? $note : $existingConfirmation?->note;

        $confirmation = OperatorReadinessConfirmation::query()->updateOrCreate(
            ['key' => $validated['key']],
            [
                'confirmed_by_id' => $agent->id,
                'confirmed_at' => now(),
                'note' => $storedNote,
            ],
        );

        AuditEvent::query()->create([
            'account_id' => $agent->account_id,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $confirmation->getMorphClass(),
            'subject_id' => $confirmation->id,
            'action' => 'operator_readiness.confirmed',
            'metadata' => [
                'key' => $validated['key'],
                'note' => $storedNote,
            ],
            'occurred_at' => now(),
        ]);

        return redirect($redirectTo)
            ->with('status', 'Readiness confirmation saved.');
    }
}
