<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\ConversationBulkAction;
use App\Enums\ConversationStatus;
use App\Enums\TicketPriority;
use App\Models\Account;
use App\Models\Conversation;
use App\Models\ConversationBulkActionRun;
use App\Models\Site;
use App\Models\User;
use App\Support\Conversations\ConversationBulkActionService;
use App\Support\Conversations\ConversationQueueQuery;
use App\Support\Sites\SiteManagerCoverage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AgentConversationBulkActionController extends Controller
{
    private const PREVIEW_SESSION_PREFIX = 'conversation_bulk_previews.';

    private const PREVIEW_TTL_MINUTES = 15;

    public function __construct(
        private readonly SiteManagerCoverage $siteManagerCoverage,
        private readonly ConversationBulkActionService $bulkActions,
    ) {}

    public function preview(Request $request): View
    {
        $agent = $request->user();
        $account = $this->accountFor($agent);
        $data = $request->validate($this->previewRules());
        $action = ConversationBulkAction::from($data['action']);
        $ids = $this->conversationIds($data['conversation_ids']);
        $conversations = $this->conversationsFor($agent, (int) $account->id, $ids);
        $value = $this->resolveValue($action, $data['value'] ?? null, $account, $conversations);
        $returnQuery = $this->returnQuery($data['return_query'] ?? []);
        $items = $conversations->map(function (Conversation $conversation) use ($action, $value): array {
            $changed = $this->bulkActions->wouldChange($conversation, $action, $value['value']);

            return [
                'conversation_id' => (int) $conversation->id,
                'support_code' => (string) $conversation->support_code,
                'subject' => filled($conversation->subject)
                    ? (string) $conversation->subject
                    : __('conversations.row.untitled'),
                'site' => (string) $conversation->site->name,
                'before' => $this->displayedValue($conversation, $action, $value, false),
                'after' => $this->displayedValue($conversation, $action, $value, true),
                'changed' => $changed,
                'snapshot' => $this->bulkActions->state($conversation, $action),
            ];
        })->values();
        $changedCount = $items->where('changed', true)->count();
        $token = Str::random(48);

        $request->session()->put(self::PREVIEW_SESSION_PREFIX.$token, [
            'account_id' => (int) $account->id,
            'agent_id' => (int) $agent->id,
            'action' => $action->value,
            'value' => $this->storedValue($value),
            'conversation_ids' => $ids,
            'snapshots' => $items->mapWithKeys(fn (array $item): array => [
                $item['conversation_id'] => $item['snapshot'],
            ])->all(),
            'item_count' => $items->count(),
            'changed_count' => $changedCount,
            'return_query' => $returnQuery,
            'expires_at' => now()->addMinutes(self::PREVIEW_TTL_MINUTES)->getTimestamp(),
        ]);

        return view('agent.conversations.bulk-confirm', [
            'account' => $account,
            'actionLabel' => __('conversations.bulk.actions.'.$action->value),
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
            return $this->queueError($returnQuery, 'conversations.bulk.errors.preview_expired');
        }

        if ((int) ($preview['changed_count'] ?? 0) < 1) {
            return $this->queueError($returnQuery, 'conversations.bulk.errors.nothing_to_change');
        }

        try {
            $run = DB::transaction(function () use ($account, $agent, $preview): ConversationBulkActionRun {
                $accountId = (int) $account->id;
                $this->siteManagerCoverage->lockAccount($accountId);
                $lockedAgent = User::query()
                    ->with('customRole')
                    ->whereKey($agent->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $action = ConversationBulkAction::from((string) $preview['action']);
                $ids = $this->conversationIds($preview['conversation_ids'] ?? []);
                $conversations = $this->conversationsFor($lockedAgent, $accountId, $ids, true);
                $value = $this->resolveValue(
                    $action,
                    data_get($preview, 'value.value'),
                    $account,
                    $conversations,
                );

                if ($this->storedValue($value) !== ($preview['value'] ?? null)) {
                    throw ValidationException::withMessages([
                        'preview_token' => __('conversations.bulk.errors.preview_stale'),
                    ]);
                }

                foreach ($conversations as $conversation) {
                    $expected = data_get($preview, 'snapshots.'.$conversation->id);

                    if ($this->bulkActions->state($conversation, $action) !== $expected) {
                        throw ValidationException::withMessages([
                            'preview_token' => __('conversations.bulk.errors.preview_stale'),
                        ]);
                    }
                }

                $run = ConversationBulkActionRun::query()->create([
                    'account_id' => $accountId,
                    'triggered_by_user_id' => $lockedAgent->id,
                    'action' => $action,
                    'value' => $this->storedValue($value),
                    'item_count' => (int) $preview['item_count'],
                    'changed_count' => 0,
                    'changes' => [],
                    'return_query' => $this->returnQuery($preview['return_query'] ?? []),
                ]);
                $changes = $this->bulkActions->apply(
                    $lockedAgent,
                    $run,
                    $conversations,
                    $value['value'],
                    $value['target'] ?? null,
                );
                $run->forceFill([
                    'changed_count' => count($changes),
                    'changes' => $changes,
                ])->save();

                return $run;
            });
        } catch (ValidationException) {
            return $this->queueError($returnQuery, 'conversations.bulk.errors.preview_stale');
        }

        return redirect()
            ->route('dashboard.conversations.index', $returnQuery)
            ->with('conversation_bulk_status', [
                'key' => 'conversations.bulk.flash.applied',
                'changed' => (int) $run->changed_count,
                'selected' => (int) $run->item_count,
                'run_id' => (int) $run->id,
            ]);
    }

    public function undo(Request $request, ConversationBulkActionRun $conversationBulkActionRun): RedirectResponse
    {
        $agent = $request->user();
        $account = $this->accountFor($agent);
        abort_unless((int) $conversationBulkActionRun->account_id === (int) $account->id, 404);

        try {
            [$run, $result] = DB::transaction(function () use ($account, $agent, $conversationBulkActionRun): array {
                $accountId = (int) $account->id;
                $this->siteManagerCoverage->lockAccount($accountId);
                $lockedAgent = User::query()
                    ->with('customRole')
                    ->whereKey($agent->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $run = ConversationBulkActionRun::query()
                    ->whereKey($conversationBulkActionRun->id)
                    ->where('account_id', $accountId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($run->undone_at !== null) {
                    throw ValidationException::withMessages([
                        'undo' => __('conversations.bulk.errors.already_undone'),
                    ]);
                }

                $ids = collect($run->changes)
                    ->pluck('conversation_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $conversations = Conversation::query()
                    ->with($run->actionEnum() === ConversationBulkAction::AssignAgent
                        ? ['assignedAgent', 'site.supportAgents.customRole']
                        : ['assignedAgent', 'site'])
                    ->whereHas('site', fn ($query) => $query->where('account_id', $accountId))
                    ->whereKey($ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $this->authorizeConversations($lockedAgent, $accountId, $conversations, $ids, true, true);
                $result = $this->bulkActions->undo($lockedAgent, $run, $conversations);
                $run->forceFill([
                    'undone_at' => now(),
                    'undone_by_user_id' => $lockedAgent->id,
                    'undo_result' => $result,
                ])->save();

                return [$run, $result];
            });
        } catch (ValidationException) {
            return $this->queueError(
                $this->returnQuery($conversationBulkActionRun->return_query ?? []),
                'conversations.bulk.errors.already_undone',
            );
        }

        return redirect()
            ->route('dashboard.conversations.index', $this->returnQuery($run->return_query ?? []))
            ->with('conversation_bulk_status', [
                'key' => 'conversations.bulk.flash.undone',
                'reverted' => $result['reverted'],
                'skipped' => $result['skipped'],
            ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function previewRules(): array
    {
        return [
            'conversation_ids' => ['required', 'array', 'min:1', 'max:'.ConversationQueueQuery::DISPLAY_LIMIT],
            'conversation_ids.*' => ['required', 'integer', 'distinct'],
            'action' => ['required', Rule::enum(ConversationBulkAction::class)],
            'value' => ['nullable', 'string', 'max:80'],
            'return_query' => ['nullable', 'array'],
        ];
    }

    private function accountFor(User $agent): Account
    {
        abort_unless(
            $agent->account_id
            && $agent->hasAccountPermission(AccountPermission::ViewConversations)
            && $agent->hasAccountPermission(AccountPermission::ManageConversations),
            403,
        );

        return $agent->account()->firstOrFail();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Conversation>
     */
    private function conversationsFor(
        User $agent,
        int $accountId,
        array $ids,
        bool $lock = false,
    ): Collection {
        $query = Conversation::query()
            ->with(['assignedAgent', 'site'])
            ->whereHas('site', fn ($query) => $query->where('account_id', $accountId))
            ->whereKey($ids)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $conversations = $query->get();
        $this->authorizeConversations($agent, $accountId, $conversations, $ids);

        return $conversations;
    }

    /**
     * @param  Collection<int, Conversation>  $conversations
     * @param  list<int>  $ids
     */
    private function authorizeConversations(
        User $agent,
        int $accountId,
        Collection $conversations,
        array $ids,
        bool $allowMissing = false,
        bool $includeArchived = false,
    ): void {
        abort_unless(
            (int) $agent->account_id === $accountId
            && $agent->hasAccountPermission(AccountPermission::ViewConversations)
            && $agent->hasAccountPermission(AccountPermission::ManageConversations),
            403,
        );
        abort_unless($allowMissing || $conversations->count() === count($ids), 404);

        $siteIds = $conversations->pluck('site_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $visibleSites = Site::query();
        $visibleSites = $includeArchived
            ? $visibleSites->visibleToAgentIncludingArchived($agent)
            : $visibleSites->visibleToAgent($agent);
        $visibleSiteCount = $visibleSites->whereKey($siteIds->all())->count();

        abort_unless($visibleSiteCount === $siteIds->count(), 404);
    }

    /** @return list<int> */
    private function conversationIds(mixed $ids): array
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
     * @param  Collection<int, Conversation>  $conversations
     * @return array{value: int|string, label: string, target?: User}
     */
    private function resolveValue(
        ConversationBulkAction $action,
        mixed $rawValue,
        Account $account,
        Collection $conversations,
    ): array {
        if ($action === ConversationBulkAction::Close) {
            return [
                'value' => ConversationStatus::Closed->value,
                'label' => __('conversations.detail.statuses.closed'),
            ];
        }

        if ($rawValue === null || $rawValue === '') {
            throw ValidationException::withMessages(['value' => __('conversations.bulk.errors.value_required')]);
        }

        if ($action === ConversationBulkAction::AssignAgent) {
            $target = $account->agents()
                ->with('customRole')
                ->whereNull('deactivated_at')
                ->whereKey((int) $rawValue)
                ->first();
            $siteIds = $conversations->pluck('site_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();
            $coveredSiteCount = $target instanceof User
                ? Site::query()
                    ->visibleToAgent($target)
                    ->whereKey($siteIds->all())
                    ->count()
                : 0;

            if (! $target instanceof User
                || ! $target->hasAccountPermission(AccountPermission::ViewConversations)
                || $coveredSiteCount !== $siteIds->count()) {
                throw ValidationException::withMessages(['value' => __('conversations.bulk.errors.assignee_unavailable')]);
            }

            return [
                'value' => (int) $target->id,
                'label' => (string) $target->name,
                'target' => $target,
            ];
        }

        if ($action === ConversationBulkAction::SetPriority
            && in_array((string) $rawValue, TicketPriority::values(), true)) {
            return [
                'value' => (string) $rawValue,
                'label' => __('tickets.priorities.'.(string) $rawValue),
            ];
        }

        if ($action === ConversationBulkAction::SetStatus
            && (string) $rawValue === ConversationStatus::Open->value) {
            return [
                'value' => ConversationStatus::Open->value,
                'label' => __('conversations.detail.statuses.open'),
            ];
        }

        throw ValidationException::withMessages(['value' => __('conversations.bulk.errors.value_invalid')]);
    }

    /** @param array{value: int|string, label: string} $value */
    private function displayedValue(
        Conversation $conversation,
        ConversationBulkAction $action,
        array $value,
        bool $after,
    ): string {
        if ($after) {
            return $value['label'];
        }

        return match ($action) {
            ConversationBulkAction::AssignAgent => $conversation->assignedAgent?->name
                ?? __('conversations.row.unassigned_agent'),
            ConversationBulkAction::SetPriority => __('tickets.priorities.'.$conversation->priority),
            ConversationBulkAction::SetStatus, ConversationBulkAction::Close => __('conversations.detail.statuses.'.$conversation->status),
        };
    }

    /**
     * @param  array{value: int|string, label: string, target?: User}  $value
     * @return array{value: int|string, label: string}
     */
    private function storedValue(array $value): array
    {
        return [
            'value' => $value['value'],
            'label' => $value['label'],
        ];
    }

    /** @return array<string, string> */
    private function returnQuery(mixed $query): array
    {
        return collect(is_array($query) ? $query : [])
            ->only([
                'conversation_filter',
                'conversation_search',
                'conversation_site',
                'conversation_presence',
            ])
            ->filter(fn (mixed $value): bool => is_scalar($value) && mb_strlen((string) $value) <= 120)
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }

    /** @param array<string, string> $returnQuery */
    private function queueError(array $returnQuery, string $key): RedirectResponse
    {
        return redirect()
            ->route('dashboard.conversations.index', $returnQuery)
            ->with('conversation_bulk_error', $key);
    }
}
