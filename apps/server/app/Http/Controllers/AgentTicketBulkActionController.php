<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\TicketBulkAction;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Account;
use App\Models\Ticket;
use App\Models\TicketBulkActionRun;
use App\Models\TicketLabel;
use App\Models\User;
use App\Support\Sites\SiteManagerCoverage;
use App\Support\Tickets\TicketBulkActionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AgentTicketBulkActionController extends Controller
{
    private const PREVIEW_SESSION_PREFIX = 'ticket_bulk_previews.';

    private const PREVIEW_TTL_MINUTES = 15;

    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
        private readonly TicketBulkActionService $bulkActions,
    ) {}

    public function preview(Request $request): View
    {
        $agent = $request->user();
        $account = $this->accountFor($agent);
        $data = $request->validate($this->previewRules());
        $action = TicketBulkAction::from($data['action']);
        $ids = $this->ticketIds($data['ticket_ids']);
        $tickets = $this->ticketsFor($agent, (int) $account->id, $ids);
        $value = $this->resolveValue($action, $data['value'] ?? null, $account, $tickets, $agent);
        $returnQuery = $this->returnQuery($data['return_query'] ?? []);
        $items = $tickets->map(function (Ticket $ticket) use ($action, $value): array {
            $changed = $this->bulkActions->wouldChange($ticket, $action, $value['value']);

            return [
                'ticket_id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'site' => (string) $ticket->site->name,
                'before' => $this->displayedValue($ticket, $action, $value, false),
                'after' => $this->displayedValue($ticket, $action, $value, true),
                'changed' => $changed,
                'snapshot' => $this->bulkActions->state($ticket, $action, $value['value']),
            ];
        })->values();
        $changedCount = $items->where('changed', true)->count();
        $token = Str::random(48);

        $request->session()->put(self::PREVIEW_SESSION_PREFIX.$token, [
            'account_id' => (int) $account->id,
            'agent_id' => (int) $agent->id,
            'action' => $action->value,
            'value' => $value,
            'ticket_ids' => $ids,
            'snapshots' => $items->mapWithKeys(fn (array $item): array => [
                $item['ticket_id'] => $item['snapshot'],
            ])->all(),
            'item_count' => $items->count(),
            'changed_count' => $changedCount,
            'return_query' => $returnQuery,
            'expires_at' => now()->addMinutes(self::PREVIEW_TTL_MINUTES)->getTimestamp(),
        ]);

        return view('agent.tickets.bulk-confirm', [
            'account' => $account,
            'actionLabel' => __('tickets.bulk.actions.'.$action->value),
            'agent' => $agent,
            'changedCount' => $changedCount,
            'items' => $items,
            'returnQuery' => $returnQuery,
            'token' => $token,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agent = $request->user();
        $account = $this->accountFor($agent);
        $data = $request->validate(['preview_token' => ['required', 'string', 'size:48']]);
        $preview = $request->session()->pull(self::PREVIEW_SESSION_PREFIX.$data['preview_token']);
        $returnQuery = $this->returnQuery(is_array($preview) ? ($preview['return_query'] ?? []) : []);

        if (! is_array($preview)
            || (int) ($preview['account_id'] ?? 0) !== (int) $account->id
            || (int) ($preview['agent_id'] ?? 0) !== (int) $agent->id
            || (int) ($preview['expires_at'] ?? 0) < now()->getTimestamp()) {
            return $this->queueError($returnQuery, 'tickets.bulk.errors.preview_expired');
        }

        if ((int) ($preview['changed_count'] ?? 0) < 1) {
            return $this->queueError($returnQuery, 'tickets.bulk.errors.nothing_to_change');
        }

        try {
            $run = DB::transaction(function () use ($account, $agent, $preview): TicketBulkActionRun {
                $accountId = (int) $account->id;
                $this->siteManagerCoverage->lockAccount($accountId);
                $lockedAgent = User::query()
                    ->with('customRole')
                    ->whereKey($agent->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $action = TicketBulkAction::from((string) $preview['action']);
                $ids = $this->ticketIds($preview['ticket_ids'] ?? []);
                $tickets = $this->ticketsFor($lockedAgent, $accountId, $ids, true);
                $value = $this->resolveValue(
                    $action,
                    data_get($preview, 'value.value'),
                    $account,
                    $tickets,
                    $lockedAgent,
                );

                if ($value !== ($preview['value'] ?? null)) {
                    throw ValidationException::withMessages([
                        'preview_token' => __('tickets.bulk.errors.preview_stale'),
                    ]);
                }

                foreach ($tickets as $ticket) {
                    $expected = data_get($preview, 'snapshots.'.$ticket->id);

                    if ($this->bulkActions->state($ticket, $action, $value['value']) !== $expected) {
                        throw ValidationException::withMessages([
                            'preview_token' => __('tickets.bulk.errors.preview_stale'),
                        ]);
                    }
                }

                $run = TicketBulkActionRun::query()->create([
                    'account_id' => $accountId,
                    'triggered_by_user_id' => $lockedAgent->id,
                    'action' => $action,
                    'value' => $value,
                    'item_count' => (int) $preview['item_count'],
                    'changed_count' => 0,
                    'changes' => [],
                    'return_query' => $this->returnQuery($preview['return_query'] ?? []),
                ]);
                $changes = $this->bulkActions->apply($lockedAgent, $run, $tickets, $value['value']);
                $run->forceFill([
                    'changed_count' => count($changes),
                    'changes' => $changes,
                ])->save();

                return $run;
            });
        } catch (ValidationException) {
            return $this->queueError($returnQuery, 'tickets.bulk.errors.preview_stale');
        }

        return redirect()
            ->route('dashboard.tickets.index', $returnQuery)
            ->with('ticket_bulk_status', [
                'key' => 'tickets.bulk.flash.applied',
                'changed' => (int) $run->changed_count,
                'selected' => (int) $run->item_count,
                'run_id' => (int) $run->id,
            ]);
    }

    public function undo(Request $request, TicketBulkActionRun $ticketBulkActionRun): RedirectResponse
    {
        $agent = $request->user();
        $account = $this->accountFor($agent);
        abort_unless((int) $ticketBulkActionRun->account_id === (int) $account->id, 404);

        try {
            [$run, $result] = DB::transaction(function () use ($account, $agent, $ticketBulkActionRun): array {
                $accountId = (int) $account->id;
                $this->siteManagerCoverage->lockAccount($accountId);
                $lockedAgent = User::query()
                    ->with('customRole')
                    ->whereKey($agent->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $run = TicketBulkActionRun::query()
                    ->whereKey($ticketBulkActionRun->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($run->undone_at !== null) {
                    throw ValidationException::withMessages([
                        'undo' => __('tickets.bulk.errors.already_undone'),
                    ]);
                }

                if ($run->actionEnum() === TicketBulkAction::AssignAgent
                    && ! $lockedAgent->hasAccountPermission(AccountPermission::AssignTickets)) {
                    abort(403);
                }

                $ids = collect($run->changes)
                    ->pluck('ticket_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $tickets = Ticket::query()
                    ->with('site')
                    ->where('account_id', $accountId)
                    ->whereKey($ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->filter(fn (Ticket $ticket): bool => Gate::forUser($lockedAgent)->allows('view', $ticket))
                    ->values();
                $result = $this->bulkActions->undo($lockedAgent, $run, $tickets);
                $run->forceFill([
                    'undone_at' => now(),
                    'undone_by_user_id' => $lockedAgent->id,
                    'undo_result' => $result,
                ])->save();

                return [$run, $result];
            });
        } catch (ValidationException) {
            return $this->queueError(
                $this->returnQuery($ticketBulkActionRun->return_query ?? []),
                'tickets.bulk.errors.already_undone',
            );
        }

        return redirect()
            ->route('dashboard.tickets.index', $this->returnQuery($run->return_query ?? []))
            ->with('ticket_bulk_status', [
                'key' => 'tickets.bulk.flash.undone',
                'reverted' => $result['reverted'],
                'skipped' => $result['skipped'],
            ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function previewRules(): array
    {
        return [
            'ticket_ids' => ['required', 'array', 'min:1', 'max:'.Ticket::QUEUE_DISPLAY_LIMIT],
            'ticket_ids.*' => ['required', 'integer', 'distinct'],
            'action' => ['required', Rule::enum(TicketBulkAction::class)],
            'value' => ['nullable', 'string', 'max:80'],
            'return_query' => ['nullable', 'array'],
        ];
    }

    private function accountFor(User $agent): Account
    {
        abort_unless(
            $agent->account_id && $agent->hasAccountPermission(AccountPermission::ManageTickets),
            403,
        );

        return $agent->account()->firstOrFail();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Ticket>
     */
    private function ticketsFor(User $agent, int $accountId, array $ids, bool $lock = false): Collection
    {
        $query = Ticket::query()
            ->with(['assignee', 'site'])
            ->where('account_id', $accountId)
            ->whereKey($ids)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $tickets = $query->get();
        abort_unless($tickets->count() === count($ids), 404);

        foreach ($tickets as $ticket) {
            abort_unless(Gate::forUser($agent)->allows('view', $ticket), 404);
        }

        return $tickets;
    }

    /** @return list<int> */
    private function ticketIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array{value: int|string, label: string}
     */
    private function resolveValue(
        TicketBulkAction $action,
        mixed $rawValue,
        Account $account,
        Collection $tickets,
        User $agent,
    ): array {
        if ($action === TicketBulkAction::Close) {
            return [
                'value' => TicketStatus::Closed->value,
                'label' => __('tickets.statuses.closed'),
            ];
        }

        if ($rawValue === null || $rawValue === '') {
            throw ValidationException::withMessages(['value' => __('tickets.bulk.errors.value_required')]);
        }

        if ($action === TicketBulkAction::AssignAgent) {
            abort_unless($agent->hasAccountPermission(AccountPermission::AssignTickets), 403);
            $target = $account->agents()
                ->with('customRole')
                ->whereNull('deactivated_at')
                ->whereKey((int) $rawValue)
                ->first();

            if (! $target instanceof User
                || ! $target->hasAccountPermission(AccountPermission::ManageTickets)
                || $tickets->contains(fn (Ticket $ticket): bool => ! $ticket->site->supportsAgent($target))) {
                throw ValidationException::withMessages(['value' => __('tickets.bulk.errors.assignee_unavailable')]);
            }

            return ['value' => (int) $target->id, 'label' => (string) $target->name];
        }

        if ($action === TicketBulkAction::AddLabel) {
            $label = $account->ticketLabels()->whereKey((int) $rawValue)->first();

            if (! $label instanceof TicketLabel) {
                throw ValidationException::withMessages(['value' => __('tickets.bulk.errors.label_unavailable')]);
            }

            return ['value' => (int) $label->id, 'label' => (string) $label->name];
        }

        if ($action === TicketBulkAction::SetPriority
            && in_array((string) $rawValue, TicketPriority::values(), true)) {
            return [
                'value' => (string) $rawValue,
                'label' => __('tickets.priorities.'.(string) $rawValue),
            ];
        }

        if ($action === TicketBulkAction::SetStatus
            && in_array((string) $rawValue, [TicketStatus::Open->value, TicketStatus::Pending->value], true)) {
            return [
                'value' => (string) $rawValue,
                'label' => __('tickets.statuses.'.(string) $rawValue),
            ];
        }

        throw ValidationException::withMessages(['value' => __('tickets.bulk.errors.value_invalid')]);
    }

    /** @param array{value: int|string, label: string} $value */
    private function displayedValue(Ticket $ticket, TicketBulkAction $action, array $value, bool $after): string
    {
        if ($after) {
            return $value['label'];
        }

        return match ($action) {
            TicketBulkAction::AssignAgent => $ticket->assignee?->name ?? __('tickets.row.unassigned'),
            TicketBulkAction::AddLabel => $this->bulkActions->state($ticket, $action, $value['value'])['attached']
                ? $value['label']
                : __('tickets.bulk.values.not_applied'),
            TicketBulkAction::SetPriority => __('tickets.priorities.'.$ticket->priority),
            TicketBulkAction::SetStatus, TicketBulkAction::Close => __('tickets.statuses.'.$ticket->status),
        };
    }

    /** @return array<string, string> */
    private function returnQuery(mixed $query): array
    {
        $allowed = [
            'ticket_status',
            'ticket_filter',
            'ticket_site',
            'ticket_priority',
            'ticket_category',
            'ticket_label',
            'ticket_attention',
            'ticket_external',
            'ticket_search',
        ];

        return collect(is_array($query) ? $query : [])
            ->only($allowed)
            ->filter(fn (mixed $value): bool => is_scalar($value) && mb_strlen((string) $value) <= 120)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }

    /** @param array<string, string> $returnQuery */
    private function queueError(array $returnQuery, string $key): RedirectResponse
    {
        return redirect()
            ->route('dashboard.tickets.index', $returnQuery)
            ->with('ticket_bulk_error', $key);
    }
}
