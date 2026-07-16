<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Models\Site;
use App\Support\BreakGlass\BreakGlassGrants;
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
            'ownGrants' => $ownGrants,
            'approvalHints' => $approvalHints,
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name']),
            'sites' => Site::query()->with('account:id,name')->orderBy('name')->get(['id', 'name', 'account_id']),
            'defaultMinutes' => BreakGlassGrant::DEFAULT_MINUTES,
            'durationChoices' => $this->durationChoices(),
        ]);
    }

    public function store(Request $request, BreakGlassGrants $grants): RedirectResponse
    {
        $data = $request->validate([
            'scope_type' => 'required|in:conversation,site,account',
            'account_id' => 'required_if:scope_type,account|nullable|integer|exists:accounts,id',
            'site_id' => 'required_if:scope_type,site|nullable|integer|exists:sites,id',
            'support_code' => 'required_if:scope_type,conversation|nullable|string|max:32',
            'reason' => 'required|string|max:1000',
            'requested_minutes' => 'required|integer|min:1|max:'.BreakGlassGrant::MAX_MINUTES,
        ]);

        $scope = $this->resolveScope($data);

        $grant = $grants->request($request->user(), $scope, $data['reason'], (int) $data['requested_minutes']);

        return redirect()
            ->route('operator.break-glass.index')
            ->with('status', sprintf('Break-glass access requested for %s.', $grant->scopeLabel()));
    }

    public function approve(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        abort_unless((int) $grant->requester_id === (int) $request->user()->id, 404);

        $grant = $grants->approve($grant, $request->user());

        return redirect()
            ->route('operator.break-glass.index')
            ->with('status', sprintf('Self-approved — access to %s until %s.', $grant->scopeLabel(), $grant->expires_at->format('H:i T')));
    }

    public function close(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        abort_unless((int) $grant->requester_id === (int) $request->user()->id, 404);

        $grant = $grants->close($grant, $request->user());

        return redirect()
            ->route('operator.break-glass.index')
            ->with('status', $grant->status === BreakGlassGrant::STATUS_EXPIRED
                ? 'That grant had already expired; it is recorded as expired.'
                : 'Grant closed. Access is revoked.');
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
                'support_code' => sprintf('No conversation found for support code %s.', $code),
            ]);
        }

        return $conversation;
    }

    /**
     * @return array<int, string>
     */
    private function durationChoices(): array
    {
        return [
            15 => '15 minutes',
            BreakGlassGrant::DEFAULT_MINUTES => '1 hour',
            240 => '4 hours',
            BreakGlassGrant::MAX_MINUTES => '24 hours (maximum)',
        ];
    }
}
