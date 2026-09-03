<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\BreakGlass\BreakGlassGrants;
use App\Support\OperatorBreakGlassPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The operator side of break-glass (ADR 0008, slice 2): request scoped access,
 * self-approve when the install has no other eligible approver, close early.
 * All lifecycle rules live in the BreakGlassGrants service — this controller
 * only resolves form input into scope objects and renders state.
 */
class OperatorBreakGlassController extends Controller
{
    public function index(Request $request, BreakGlassGrants $grants): View
    {
        $operator = $request->user();

        // Open grants (pending + active) are never capped — a self-approve or
        // close action must not scroll out of existence behind newer history
        // rows. Only the terminal history takes a display limit.
        $openGrants = BreakGlassGrant::query()
            ->where('requester_id', $operator->id)
            ->whereIn('status', [BreakGlassGrant::STATUS_REQUESTED, BreakGlassGrant::STATUS_ACTIVE])
            ->with(['account', 'conversation', 'site', 'approver'])
            ->latest('id')
            ->get();

        $terminalGrants = BreakGlassGrant::query()
            ->where('requester_id', $operator->id)
            ->whereIn('status', [BreakGlassGrant::STATUS_DENIED, BreakGlassGrant::STATUS_CLOSED, BreakGlassGrant::STATUS_EXPIRED])
            ->with(['account', 'conversation', 'site', 'approver'])
            ->latest('id')
            ->limit(15)
            ->get();

        $ownGrants = $openGrants->concat($terminalGrants)->sortByDesc('id')->values();

        // A requested grant shows either a self-approve action or the names
        // of the people it is waiting on — never a button that will 403.
        $approvalHints = $ownGrants
            ->where('status', BreakGlassGrant::STATUS_REQUESTED)
            ->mapWithKeys(function (BreakGlassGrant $grant) use ($grants, $operator): array {
                $approvers = $grants->eligibleApprovers($grant);

                $canSelfApprove = $approvers->isEmpty()
                    && (int) $operator->account_id === (int) $grant->account_id
                    && $operator->isAdmin();

                return [$grant->id => [
                    'can_self_approve' => $canSelfApprove,
                    'waiting_on' => $approvers->pluck('name')->all(),
                ]];
            });

        return view('operator.break-glass', [
            'operator' => $operator,
            'ownGrants' => $ownGrants->map(fn (BreakGlassGrant $grant): array => OperatorBreakGlassPresenter::grant($grant)),
            'approvalHints' => $approvalHints,
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name']),
            'sites' => Site::query()->with('account:id,name')->orderBy('name')->get(['id', 'name', 'account_id']),
            'flashStatus' => OperatorBreakGlassPresenter::flash($request),
            'defaultMinutes' => BreakGlassGrant::DEFAULT_MINUTES,
            'durationChoices' => OperatorBreakGlassPresenter::durationChoices(),
        ]);
    }

    public function store(Request $request, BreakGlassGrants $grants): RedirectResponse
    {
        $data = $request->validate(
            [
                'scope_type' => 'required|in:conversation,site,account',
                'account_id' => 'required_if:scope_type,account|nullable|integer|exists:accounts,id',
                'site_id' => 'required_if:scope_type,site|nullable|integer|exists:sites,id',
                'support_code' => 'required_if:scope_type,conversation|nullable|string|max:32',
                'reason' => 'required|string|max:1000',
                'requested_minutes' => 'required|integer|min:1|max:'.BreakGlassGrant::MAX_MINUTES,
            ],
            [
                // The framework's required_if sentence exposes the machine
                // values `conversation`, `site`, and `account`. Whole custom
                // sentences keep those implementation tokens out of the UI.
                'account_id.required_if' => __('operator_break_glass.validation.account_required'),
                'site_id.required_if' => __('operator_break_glass.validation.site_required'),
                'support_code.required_if' => __('operator_break_glass.validation.support_code_required'),
            ],
        );

        $scope = $this->resolveScope($data);

        $grant = $grants->request($request->user(), $scope, $data['reason'], (int) $data['requested_minutes']);

        return redirect()
            ->route('operator.break-glass.index')
            ->with('status', 'operator_break_glass.flash.requested')
            ->with('operator_break_glass_status', [
                'scope_type' => $grant->scope_type,
                'scope_label' => $grant->scopeLabel(),
            ]);
    }

    public function approve(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        abort_unless((int) $grant->requester_id === (int) $request->user()->id, 404);

        $grant = $grants->approve($grant, $request->user());

        return redirect()
            ->route('operator.break-glass.index')
            ->with('status', 'operator_break_glass.flash.self_approved')
            ->with('operator_break_glass_status', [
                'scope_type' => $grant->scope_type,
                'scope_label' => $grant->scopeLabel(),
                'expires_at' => $grant->expires_at?->toJSON(),
            ]);
    }

    public function close(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        abort_unless((int) $grant->requester_id === (int) $request->user()->id, 404);

        $grant = $grants->close($grant, $request->user());

        return redirect()
            ->route('operator.break-glass.index')
            ->with('status', $grant->status === BreakGlassGrant::STATUS_EXPIRED
                ? 'operator_break_glass.flash.already_expired'
                : 'operator_break_glass.flash.closed');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveScope(array $data): Conversation|Site|Account
    {
        return match ($data['scope_type']) {
            'conversation' => $this->conversationBySupportCode((string) $data['support_code']),
            'site' => Site::query()->findOrFail((int) $data['site_id']),
            'account' => Account::query()->findOrFail((int) $data['account_id']),
        };
    }

    private function conversationBySupportCode(string $rawCode): Conversation
    {
        $code = Str::upper(trim($rawCode));

        if ($code !== '' && ! Str::startsWith($code, 'WF-')) {
            $code = 'WF-'.$code;
        }

        $conversation = Conversation::query()->where('support_code', $code)->first();

        if (! $conversation) {
            throw ValidationException::withMessages([
                'support_code' => __('operator_break_glass.validation.conversation_not_found'),
            ]);
        }

        return $conversation;
    }
}
