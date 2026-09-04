<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AutomationMacroSubjectType;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationMacro;
use App\Models\User;
use App\Support\Automation\AutomationMacroForm;
use App\Support\Automation\AutomationRuleForm;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AgentAutomationMacroController extends Controller
{
    public function __construct(
        private readonly AutomationMacroForm $form,
        private readonly SiteManagerCoverage $siteManagerCoverage,
    ) {}

    public function create(Request $request): View
    {
        $agent = $this->automationManager($request);
        $account = $agent->account()->firstOrFail();

        return view('agent.automation-macros.form', [
            ...$this->formViewData($agent, $account),
            'automationMacro' => null,
            'actionRows' => [$this->form->blankAction()],
            'defaultPosition' => min(
                AutomationRuleForm::MAX_POSITION,
                ((int) $account->automationMacros()->max('position')) + 10,
            ),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $this->automationManager($request);

        $macro = DB::transaction(function () use ($agent, $request): AutomationMacro {
            [$agent, $account] = $this->lockedAutomationManager($agent, 403);
            $attributes = $this->form->validated($request, $account);
            $this->ensureUniqueName($account, $attributes['name']);
            $macro = $account->automationMacros()->create($attributes);
            $this->audit($agent, $macro, 'automation_macro.created', [
                'name' => $macro->name,
                'subject_type' => $macro->subject_type,
                'is_enabled' => $macro->is_enabled,
                'action_count' => count($macro->actions),
            ]);

            return $macro;
        });

        return redirect()
            ->route('dashboard.account.automation-macros.edit', $macro)
            ->with('status', 'automation_macros.flash.created');
    }

    public function edit(Request $request, AutomationMacro $automationMacro): View
    {
        $agent = $this->automationManager($request);
        $this->authorizeMacro($agent, $automationMacro);
        $account = $agent->account()->firstOrFail();

        return view('agent.automation-macros.form', [
            ...$this->formViewData($agent, $account),
            'automationMacro' => $automationMacro,
            'actionRows' => $this->form->actionRows($automationMacro->actions),
            'defaultPosition' => $automationMacro->position,
        ]);
    }

    public function update(Request $request, AutomationMacro $automationMacro): RedirectResponse
    {
        $agent = $this->automationManager($request);
        $this->authorizeMacro($agent, $automationMacro);

        DB::transaction(function () use ($agent, $automationMacro, $request): void {
            [$agent, $account] = $this->lockedAutomationManager($agent);
            $automationMacro = $this->lockedMacro($automationMacro, $account);
            $attributes = $this->form->validated($request, $account);
            $this->ensureUniqueName($account, $attributes['name'], (int) $automationMacro->id);
            $automationMacro->fill($attributes);
            $changed = array_values(array_keys($automationMacro->getDirty()));
            $automationMacro->save();

            if ($changed !== []) {
                $this->audit($agent, $automationMacro, 'automation_macro.updated', [
                    'name' => $automationMacro->name,
                    'subject_type' => $automationMacro->subject_type,
                    'is_enabled' => $automationMacro->is_enabled,
                    'changed' => $changed,
                    'action_count' => count($automationMacro->actions),
                ]);
            }
        });

        return redirect()
            ->route('dashboard.account.automation-macros.edit', $automationMacro)
            ->with('status', 'automation_macros.flash.updated');
    }

    public function destroy(Request $request, AutomationMacro $automationMacro): RedirectResponse
    {
        $agent = $this->automationManager($request);
        $this->authorizeMacro($agent, $automationMacro);

        DB::transaction(function () use ($agent, $automationMacro): void {
            [$agent, $account] = $this->lockedAutomationManager($agent);
            $automationMacro = $this->lockedMacro($automationMacro, $account);
            $this->audit($agent, $automationMacro, 'automation_macro.deleted', [
                'name' => $automationMacro->name,
                'subject_type' => $automationMacro->subject_type,
                'is_enabled' => $automationMacro->is_enabled,
            ]);
            $automationMacro->delete();
        });

        return redirect()
            ->route('dashboard.account.automation-rules.index')
            ->with('status', 'automation_macros.flash.deleted');
    }

    /** @return array<string, mixed> */
    private function formViewData(User $agent, Account $account): array
    {
        return [
            'account' => $account,
            'agent' => $agent,
            'agents' => $account->agents()
                ->with('customRole')
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
            'form' => $this->form,
            'labels' => $account->ticketLabels()->orderBy('name')->get(),
            'subjectTypes' => AutomationMacroSubjectType::cases(),
        ];
    }

    private function automationManager(Request $request): User
    {
        $agent = $request->user();

        abort_unless(
            $agent instanceof User
            && $agent->account_id !== null
            && $agent->hasAccountPermission(AccountPermission::ManageAutomations),
            403,
        );

        return $agent;
    }

    private function authorizeMacro(User $agent, AutomationMacro $macro): void
    {
        abort_unless((int) $macro->account_id === (int) $agent->account_id, 404);
    }

    /** @return array{User, Account} */
    private function lockedAutomationManager(User $agent, int $failureStatus = 404): array
    {
        $accountId = (int) $agent->account_id;
        $this->siteManagerCoverage->lockAccount($accountId);
        $account = Account::query()->whereKey($accountId)->firstOrFail();
        $agent = User::query()
            ->with('customRole')
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $agent?->hasAccountPermission(AccountPermission::ManageAutomations),
            $failureStatus,
        );

        return [$agent, $account];
    }

    private function lockedMacro(AutomationMacro $macro, Account $account): AutomationMacro
    {
        return AutomationMacro::query()
            ->whereKey($macro->id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureUniqueName(Account $account, string $name, ?int $exceptId = null): void
    {
        $query = $account->automationMacros()->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('automation_macros.validation.duplicate'),
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $agent, AutomationMacro $macro, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $agent->account_id,
            'site_id' => null,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $macro->getMorphClass(),
            'subject_id' => $macro->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }
}
