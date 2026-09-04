<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\TicketPriority;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Support\Sla\SlaClockManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AgentAccountSlaPolicyController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $request->user();
        abort_unless($agent?->account_id && $agent->hasAccountPermission(AccountPermission::ManageSites), 403);
        $account = $agent->account()->firstOrFail();

        return view('agent.account.sla-policies', [
            'account' => $account,
            'agent' => $agent,
            'policies' => $account->slaPolicies()->get()->keyBy('priority'),
            'priorities' => TicketPriority::guidanceOptions(),
        ]);
    }

    public function update(Request $request, SlaClockManager $clocks): RedirectResponse
    {
        $agent = $request->user();
        abort_unless($agent?->account_id && $agent->hasAccountPermission(AccountPermission::ManageSites), 403);

        $priorities = TicketPriority::values();
        $rules = [
            'policies' => ['required', 'array:'.implode(',', $priorities)],
            'policies.*' => ['required', 'array:first_response_minutes,resolution_minutes'],
            'policies.*.first_response_minutes' => ['nullable', 'integer', 'min:5', 'max:43200'],
            'policies.*.resolution_minutes' => ['nullable', 'integer', 'min:5', 'max:43200'],
        ];

        foreach ($priorities as $priority) {
            $rules['policies.'.$priority] = ['required', 'array:first_response_minutes,resolution_minutes'];
        }

        $validated = $request->validate($rules);

        DB::transaction(function () use ($agent, $clocks, $priorities, $validated): void {
            $account = Account::query()->whereKey($agent->account_id)->lockForUpdate()->firstOrFail();
            $agent = User::query()
                ->whereKey($agent->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            // Role and deactivation writes share the account lock. Re-read
            // after it so a request already waiting cannot spend permission
            // its actor lost before the policy replacement begins.
            abort_unless($agent?->hasAccountPermission(AccountPermission::ManageSites), 403);
            $at = now();

            // Settle every active row and persist any crossed boundary under
            // the OLD targets first. The policy edit changes the future; it
            // does not erase a breach that happened before this request.
            $clocks->recordAccountBreaches($account, $at);
            $changed = [];

            foreach ($priorities as $priority) {
                $values = $validated['policies'][$priority] ?? [];
                $firstResponse = $this->minutes($values['first_response_minutes'] ?? null);
                $resolution = $this->minutes($values['resolution_minutes'] ?? null);
                $existing = $account->slaPolicies()->where('priority', $priority)->first();

                if ($firstResponse === null && $resolution === null) {
                    if ($existing) {
                        $existing->delete();
                        $changed[] = $priority;
                    }

                    continue;
                }

                $policy = $existing ?? new SlaPolicy(['account_id' => $account->id, 'priority' => $priority]);
                $policy->forceFill([
                    'first_response_minutes' => $firstResponse,
                    'resolution_minutes' => $resolution,
                    'effective_at' => $at,
                ]);

                if (! $policy->exists || $policy->isDirty(['first_response_minutes', 'resolution_minutes'])) {
                    $changed[] = $priority;
                }

                $policy->save();
            }

            $clocks->reconcileAccount($account, $at);

            if ($changed !== []) {
                AuditEvent::query()->create([
                    'account_id' => $account->id,
                    'site_id' => null,
                    'actor_type' => $agent->getMorphClass(),
                    'actor_id' => $agent->id,
                    'subject_type' => $account->getMorphClass(),
                    'subject_id' => $account->id,
                    'action' => 'account.sla_policies_updated',
                    // Names only. Targets are operational commitments and are
                    // visible on the policy page, but do not need duplicating
                    // into every account-audit export forever.
                    'metadata' => ['priorities' => array_values(array_unique($changed))],
                    'occurred_at' => $at,
                ]);
            }
        });

        return redirect()
            ->route('dashboard.account.sla-policies.index')
            ->with('status', 'sla.flash.saved');
    }

    private function minutes(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
