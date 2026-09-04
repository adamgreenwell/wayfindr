<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AutomationRuleActionType;
use App\Models\TicketLabel;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AgentTicketLabelController extends Controller
{
    public function __construct(private readonly SiteManagerCoverage $siteManagerCoverage) {}

    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageKnowledge), 403);

        $account = $agent->account()->firstOrFail();
        $canManageTickets = $agent->hasAccountPermission(AccountPermission::ManageTickets);
        $ticketLabels = $account->ticketLabels();

        if ($canManageTickets) {
            $ticketLabels->withCount([
                'tickets',
                'tickets as visible_tickets_count' => fn ($query) => $query
                    ->whereHas('site', fn ($query) => $query->visibleToAgent($agent)),
            ]);
        }

        return view('agent.ticket-labels.index', [
            'account' => $account,
            'agent' => $agent,
            'canManageTickets' => $canManageTickets,
            'ticketLabels' => $ticketLabels
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();

        abort_unless($agent?->hasAccountPermission(AccountPermission::ManageKnowledge), 403);

        $account = $agent->account()->firstOrFail();
        $label = $this->validatedLabelInput($request);

        DB::transaction(function () use ($agent, $account, $label): void {
            $this->lockedKnowledgeManager($agent, (int) $account->id, 403);

            if ($this->accountLabelSlugExists((int) $account->id, $label['slug'])) {
                throw ValidationException::withMessages([
                    'label_name' => __('ticket_labels.validation.duplicate'),
                ]);
            }

            $account->ticketLabels()->create($label);
        });

        return redirect()
            ->route('dashboard.account.labels.index')
            ->with('status', 'ticket_labels.flash.created');
    }

    public function update(Request $request, TicketLabel $ticketLabel): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageLabel($agent, $ticketLabel);
        $label = $this->validatedLabelInput($request);

        DB::transaction(function () use ($agent, $ticketLabel, $label): void {
            $lockedAgent = $this->lockedKnowledgeManager($agent, (int) $ticketLabel->account_id);
            $ticketLabel = $this->lockedTicketLabel($ticketLabel);
            $this->authorizeManageLabel($lockedAgent, $ticketLabel);

            if ($this->labelSlugExists($ticketLabel, $label['slug'])) {
                throw ValidationException::withMessages([
                    'label_name' => __('ticket_labels.validation.duplicate'),
                ]);
            }

            $ticketLabel->forceFill($label)->save();
        });

        return redirect()
            ->route('dashboard.account.labels.index')
            ->with('status', 'ticket_labels.flash.renamed');
    }

    public function destroy(Request $request, TicketLabel $ticketLabel): RedirectResponse
    {
        $agent = $request->user();

        $this->authorizeManageLabel($agent, $ticketLabel);

        DB::transaction(function () use ($agent, $ticketLabel): void {
            $lockedAgent = $this->lockedKnowledgeManager($agent, (int) $ticketLabel->account_id);
            $ticketLabel = $this->lockedTicketLabel($ticketLabel);
            $this->authorizeManageLabel($lockedAgent, $ticketLabel);

            $usedByAutomation = $ticketLabel->account->automationRules()
                ->get(['actions'])
                ->concat($ticketLabel->account->automationMacros()->get(['actions']))
                ->contains(fn ($rule): bool => collect($rule->actions)->contains(
                    fn (array $action): bool => $action['type'] === AutomationRuleActionType::AddLabel->value
                        && (int) $action['value'] === (int) $ticketLabel->id,
                ));

            if ($usedByAutomation) {
                throw ValidationException::withMessages([
                    'label' => __('ticket_labels.validation.in_use_automation'),
                ]);
            }

            if ($ticketLabel->tickets()->exists()) {
                throw ValidationException::withMessages([
                    'label' => __('ticket_labels.validation.in_use'),
                ]);
            }

            $ticketLabel->delete();
        });

        return redirect()
            ->route('dashboard.account.labels.index')
            ->with('status', 'ticket_labels.flash.deleted');
    }

    private function authorizeManageLabel(mixed $agent, TicketLabel $ticketLabel): void
    {
        abort_unless(
            $agent?->hasAccountPermission(AccountPermission::ManageKnowledge)
            && $agent->account_id !== null
            && (int) $agent->account_id === (int) $ticketLabel->account_id,
            404,
        );
    }

    private function lockedKnowledgeManager(User $agent, int $accountId, int $failureStatus = 404): User
    {
        $this->siteManagerCoverage->lockAccount($accountId);
        $lockedAgent = User::query()
            ->whereKey($agent->id)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        abort_unless(
            $lockedAgent?->hasAccountPermission(AccountPermission::ManageKnowledge),
            $failureStatus,
        );

        return $lockedAgent;
    }

    private function lockedTicketLabel(TicketLabel $ticketLabel): TicketLabel
    {
        return TicketLabel::query()
            ->whereKey($ticketLabel->id)
            ->where('account_id', $ticketLabel->account_id)
            ->lockForUpdate()
            ->firstOrFail();
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
                'label_name' => __('ticket_labels.validation.empty'),
            ]);
        }

        if (TicketLabel::isReservedSlug($slug)) {
            throw ValidationException::withMessages([
                'label_name' => __('ticket_labels.validation.reserved'),
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
