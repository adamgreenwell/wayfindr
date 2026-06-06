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
    public function storeFromDashboard(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);
        abort_unless($agent->isAdmin(), 403);

        return $this->store($request, route('dashboard.readiness.show'));
    }

    public function storeFromOperator(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isPlatformOperator(), 403);

        return $this->store($request, route('operator.dashboard'));
    }

    private function store(Request $request, string $redirectTo): RedirectResponse
    {
        $agent = $request->user();
        $validated = $request->validate([
            'key' => ['required', 'string', Rule::in(OperatorReadiness::confirmableKeys())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $note = trim((string) ($validated['note'] ?? ''));

        $confirmation = OperatorReadinessConfirmation::query()->updateOrCreate(
            ['key' => $validated['key']],
            [
                'confirmed_by_id' => $agent->id,
                'confirmed_at' => now(),
                'note' => $note !== '' ? $note : null,
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
                'note' => $note !== '' ? $note : null,
            ],
            'occurred_at' => now(),
        ]);

        return redirect($redirectTo)
            ->with('status', 'Readiness confirmation saved.');
    }
}
