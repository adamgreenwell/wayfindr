<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AutomationRuleEvent;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\AutomationRule;
use App\Models\AutomationRuleExecution;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Automation\AutomationRuleEvaluator;
use App\Support\Automation\AutomationRuleForm;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AgentAutomationRuleController extends Controller
{
    public function __construct(
        private readonly AutomationRuleForm $form,
        private readonly SiteManagerCoverage $siteManagerCoverage,
    ) {}

    public function index(Request $request): View
    {
        $agent = $this->automationManager($request);
        $account = $agent->account()->firstOrFail();
        $executions = $account->automationRuleExecutions()
            ->with('subject')
            ->latest('started_at')
            ->latest('id')
            ->paginate(25);

        return view('agent.automation-rules.index', [
            'account' => $account,
            'agent' => $agent,
            'executionLinks' => $executions->getCollection()
                ->mapWithKeys(fn (AutomationRuleExecution $execution): array => [
                    $execution->id => $this->subjectLink($agent, $execution),
                ]),
            'executions' => $executions,
            'referenceLabels' => $this->referenceLabels($account),
            'rules' => $account->automationRules()->inEvaluationOrder()->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $agent = $this->automationManager($request);
        $account = $agent->account()->firstOrFail();

        return view('agent.automation-rules.form', [
            ...$this->formViewData($agent, $account),
            'automationRule' => null,
            'conditionRows' => [],
            'actionRows' => [$this->form->blankAction()],
            'defaultPosition' => min(
                AutomationRuleForm::MAX_POSITION,
                ((int) $account->automationRules()->max('position')) + 10,
            ),
            'preview' => null,
            'previewOptions' => collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $this->automationManager($request);

        $rule = DB::transaction(function () use ($agent, $request): AutomationRule {
            [$agent, $account] = $this->lockedAutomationManager($agent, 403);
            $attributes = $this->form->validated($request, $account);
            $this->ensureUniqueName($account, $attributes['name']);
            $rule = $account->automationRules()->create($attributes);
            $this->audit($agent, $rule, 'automation_rule.created', [
                'name' => $rule->name,
                'event' => $rule->event,
                'is_enabled' => $rule->is_enabled,
                'condition_count' => count($rule->conditions),
                'action_count' => count($rule->actions),
            ]);

            return $rule;
        });

        return redirect()
            ->route('dashboard.account.automation-rules.edit', $rule)
            ->with('status', 'automation_rules.flash.created');
    }

    public function edit(Request $request, AutomationRule $automationRule): View
    {
        $agent = $this->automationManager($request);
        $this->authorizeRule($agent, $automationRule);
        $account = $agent->account()->firstOrFail();

        return view('agent.automation-rules.form', [
            ...$this->formViewData($agent, $account),
            'automationRule' => $automationRule,
            'conditionRows' => $this->form->conditionRows($automationRule->conditions),
            'actionRows' => $this->form->actionRows($automationRule->actions),
            'defaultPosition' => $automationRule->position,
            'preview' => session('automation_preview'),
            'previewOptions' => $this->previewOptions($agent, $automationRule),
        ]);
    }

    public function update(Request $request, AutomationRule $automationRule): RedirectResponse
    {
        $agent = $this->automationManager($request);
        $this->authorizeRule($agent, $automationRule);

        DB::transaction(function () use ($agent, $automationRule, $request): void {
            [$agent, $account] = $this->lockedAutomationManager($agent);
            $automationRule = $this->lockedRule($automationRule, $account);
            $attributes = $this->form->validated($request, $account);
            $this->ensureUniqueName($account, $attributes['name'], (int) $automationRule->id);
            $automationRule->fill($attributes);
            $changed = array_values(array_keys($automationRule->getDirty()));
            $automationRule->save();

            if ($changed !== []) {
                $this->audit($agent, $automationRule, 'automation_rule.updated', [
                    'name' => $automationRule->name,
                    'event' => $automationRule->event,
                    'is_enabled' => $automationRule->is_enabled,
                    'changed' => $changed,
                    'condition_count' => count($automationRule->conditions),
                    'action_count' => count($automationRule->actions),
                ]);
            }
        });

        return redirect()
            ->route('dashboard.account.automation-rules.edit', $automationRule)
            ->with('status', 'automation_rules.flash.updated');
    }

    public function destroy(Request $request, AutomationRule $automationRule): RedirectResponse
    {
        $agent = $this->automationManager($request);
        $this->authorizeRule($agent, $automationRule);

        DB::transaction(function () use ($agent, $automationRule): void {
            [$agent, $account] = $this->lockedAutomationManager($agent);
            $automationRule = $this->lockedRule($automationRule, $account);
            $this->audit($agent, $automationRule, 'automation_rule.deleted', [
                'name' => $automationRule->name,
                'event' => $automationRule->event,
                'is_enabled' => $automationRule->is_enabled,
            ]);
            $automationRule->delete();
        });

        return redirect()
            ->route('dashboard.account.automation-rules.index')
            ->with('status', 'automation_rules.flash.deleted');
    }

    public function preview(
        Request $request,
        AutomationRule $automationRule,
        AutomationRuleEvaluator $evaluator,
    ): RedirectResponse {
        $agent = $this->automationManager($request);
        $this->authorizeRule($agent, $automationRule);
        $validated = $request->validate([
            'preview_subject' => ['required', 'string', 'max:80'],
        ]);
        [$subject, $message] = $this->previewSubject($agent, $automationRule, $validated['preview_subject']);
        $preview = $evaluator->preview($automationRule, $subject, $message);
        $preview['subject_label'] = $this->subjectLabel($subject, $message);

        return redirect()
            ->route('dashboard.account.automation-rules.edit', $automationRule)
            ->with('automation_preview', $preview)
            ->with('status', 'automation_rules.flash.previewed');
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
            'events' => AutomationRuleEvent::cases(),
            'form' => $this->form,
            'labels' => $account->ticketLabels()->orderBy('name')->get(),
            'referenceLabels' => $this->referenceLabels($account),
            'sites' => $account->sites()->orderBy('name')->get(),
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

    private function authorizeRule(User $agent, AutomationRule $automationRule): void
    {
        abort_unless((int) $automationRule->account_id === (int) $agent->account_id, 404);
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

    private function lockedRule(AutomationRule $automationRule, Account $account): AutomationRule
    {
        return AutomationRule::query()
            ->whereKey($automationRule->id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureUniqueName(Account $account, string $name, ?int $exceptId = null): void
    {
        $query = $account->automationRules()->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('automation_rules.validation.duplicate'),
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $agent, AutomationRule $rule, string $action, array $metadata): void
    {
        AuditEvent::query()->create([
            'account_id' => $agent->account_id,
            'site_id' => null,
            'actor_type' => $agent->getMorphClass(),
            'actor_id' => $agent->id,
            'subject_type' => $rule->getMorphClass(),
            'subject_id' => $rule->id,
            'action' => $action,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function previewOptions(User $agent, AutomationRule $rule): Collection
    {
        if ($rule->eventEnum()->isTicketEvent()) {
            if (! $agent->hasAccountPermission(AccountPermission::ManageTickets)) {
                return collect();
            }

            return Ticket::query()
                ->where('account_id', $agent->account_id)
                ->whereHas('site', fn ($query) => $query->visibleToAgent($agent))
                ->with('site')
                ->latest('updated_at')
                ->limit(30)
                ->get()
                ->map(fn (Ticket $ticket): array => [
                    'value' => 'ticket:'.$ticket->id,
                    'label' => $this->subjectLabel($ticket),
                ]);
        }

        if (! $agent->hasAccountPermission(AccountPermission::ViewConversations)) {
            return collect();
        }

        if ($rule->eventEnum() === AutomationRuleEvent::VisitorMessageCreated) {
            return ConversationMessage::query()
                ->where('sender_type', Visitor::class)
                ->whereHas('conversation.site', fn ($query) => $query
                    ->where('account_id', $agent->account_id)
                    ->visibleToAgent($agent))
                ->with('conversation.site')
                ->latest('created_at')
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (ConversationMessage $message): array => [
                    'value' => 'message:'.$message->id,
                    'label' => $this->subjectLabel($message->conversation, $message),
                ]);
        }

        return Conversation::query()
            ->whereHas('site', fn ($query) => $query
                ->where('account_id', $agent->account_id)
                ->visibleToAgent($agent))
            ->with('site')
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'value' => 'conversation:'.$conversation->id,
                'label' => $this->subjectLabel($conversation),
            ]);
    }

    /** @return array{Ticket|Conversation, ConversationMessage|null} */
    private function previewSubject(User $agent, AutomationRule $rule, string $selection): array
    {
        [$kind, $rawId] = array_pad(explode(':', $selection, 2), 2, null);

        abort_unless(is_string($rawId) && ctype_digit($rawId) && (int) $rawId > 0, 404);

        if ($rule->eventEnum()->isTicketEvent()) {
            abort_unless($kind === 'ticket', 404);
            $ticket = Ticket::query()
                ->whereKey((int) $rawId)
                ->where('account_id', $agent->account_id)
                ->with('site')
                ->firstOrFail();
            Gate::forUser($agent)->authorize('view', $ticket);

            return [$ticket, null];
        }

        if ($rule->eventEnum() === AutomationRuleEvent::VisitorMessageCreated) {
            abort_unless($kind === 'message', 404);
            $message = ConversationMessage::query()
                ->whereKey((int) $rawId)
                ->where('sender_type', Visitor::class)
                ->with('conversation.site')
                ->firstOrFail();
            Gate::forUser($agent)->authorize('view', $message->conversation);

            return [$message->conversation, $message];
        }

        abort_unless($kind === 'conversation', 404);
        $conversation = Conversation::query()
            ->whereKey((int) $rawId)
            ->with('site')
            ->firstOrFail();
        Gate::forUser($agent)->authorize('view', $conversation);

        return [$conversation, null];
    }

    private function subjectLink(User $agent, AutomationRuleExecution $execution): ?string
    {
        $subject = $execution->subject;

        if (! $subject instanceof Ticket && ! $subject instanceof Conversation) {
            return null;
        }

        $subject->loadMissing('site');

        if (! Gate::forUser($agent)->allows('view', $subject)) {
            return null;
        }

        return $subject instanceof Ticket
            ? route('dashboard.tickets.show', $subject)
            : route('dashboard.conversations.show', $subject->support_code);
    }

    private function subjectLabel(Ticket|Conversation $subject, ?ConversationMessage $message = null): string
    {
        $label = $subject instanceof Ticket
            ? __('automation_rules.subjects.ticket', ['id' => $subject->id, 'subject' => $subject->subject])
            : __('automation_rules.subjects.conversation', ['code' => $subject->support_code, 'subject' => $subject->subject]);

        if ($message !== null) {
            return __('automation_rules.subjects.message', [
                'subject' => $label,
                'excerpt' => str((string) $message->body)->squish()->limit(70),
            ]);
        }

        return $label;
    }

    /** @return array<string, string> */
    private function referenceLabels(Account $account): array
    {
        return [
            ...$account->sites()->get()->mapWithKeys(fn ($site): array => ['site:'.$site->id => $site->name])->all(),
            ...$account->agents()->get()->mapWithKeys(fn ($agent): array => ['agent:'.$agent->id => $agent->name])->all(),
            ...$account->ticketLabels()->get()->mapWithKeys(fn ($label): array => ['label:'.$label->id => $label->name])->all(),
        ];
    }
}
