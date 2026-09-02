<?php

namespace App\Http\Controllers;

use App\Models\BreakGlassGrant;
use App\Models\User;
use App\Support\BreakGlass\BreakGlassGrants;
use App\Support\ReaderClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The account side of break-glass (ADR 0008, slice 2): owners/admins review,
 * approve, deny, and revoke platform-operator access requests that touch
 * their account. Lifecycle rules live in the BreakGlassGrants service.
 */
class AgentAccountBreakGlassController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->accountAdmin($request);
        $account = $agent->account()->firstOrFail();

        // Open grants (pending + active) are never capped — an approval queue
        // or revoke button must not scroll out of existence behind newer
        // history rows. Only the terminal history takes a display limit.
        $openGrants = BreakGlassGrant::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [BreakGlassGrant::STATUS_REQUESTED, BreakGlassGrant::STATUS_ACTIVE])
            ->with(['requester', 'approver', 'conversation', 'site'])
            ->latest('id')
            ->get();

        $terminalGrants = BreakGlassGrant::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [BreakGlassGrant::STATUS_DENIED, BreakGlassGrant::STATUS_CLOSED, BreakGlassGrant::STATUS_EXPIRED])
            ->with(['requester', 'approver', 'conversation', 'site'])
            ->latest('id')
            ->limit(15)
            ->get();

        // A status-active row past its expiry (the sweep gap) is already
        // history — it must not vanish between the buckets.
        $overdueGrants = $openGrants->filter(
            fn (BreakGlassGrant $grant): bool => $grant->status === BreakGlassGrant::STATUS_ACTIVE && ! $grant->isActive(),
        );

        return view('agent.account.break-glass', [
            'account' => $account,
            'agent' => $agent,
            'flashStatus' => $this->flashStatus($request),
            'pendingGrants' => $openGrants
                ->where('status', BreakGlassGrant::STATUS_REQUESTED)
                ->map(fn (BreakGlassGrant $grant): array => $this->grantItem($grant))
                ->values(),
            'activeGrants' => $openGrants
                ->filter(fn (BreakGlassGrant $grant): bool => $grant->isActive())
                ->map(fn (BreakGlassGrant $grant): array => $this->grantItem($grant))
                ->values(),
            'pastGrants' => $overdueGrants
                ->concat($terminalGrants)
                ->sortByDesc('id')
                ->take(15)
                ->map(fn (BreakGlassGrant $grant): array => $this->grantItem($grant))
                ->values(),
        ]);
    }

    public function approve(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        $agent = $this->accountGrant($request, $grant);

        $grant = $grants->approve($grant, $agent);

        return redirect()
            ->route('dashboard.account.break-glass.index')
            // A key and semantic context, not a sentence chosen before the
            // redirect. The GET request owns the reader's language and clock.
            ->with('status', 'operator_access.flash.approved')
            ->with('operator_access_status', [
                'scope_type' => $grant->scope_type,
                'scope_label' => $grant->scopeLabel(),
                'expires_at' => $grant->expires_at?->toJSON(),
            ]);
    }

    public function deny(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        $agent = $this->accountGrant($request, $grant);

        $grants->deny($grant, $agent);

        return redirect()
            ->route('dashboard.account.break-glass.index')
            ->with('status', 'operator_access.flash.denied');
    }

    public function close(Request $request, BreakGlassGrant $grant, BreakGlassGrants $grants): RedirectResponse
    {
        $agent = $this->accountGrant($request, $grant);

        $grant = $grants->close($grant, $agent);

        return redirect()
            ->route('dashboard.account.break-glass.index')
            ->with('status', $grant->status === BreakGlassGrant::STATUS_EXPIRED
                ? 'operator_access.flash.already_expired'
                : 'operator_access.flash.closed');
    }

    /**
     * Product copy and account data travel separately to the view. The grant
     * model deliberately keeps its English labels for unextracted consumers;
     * this request-bound presenter is where those states become reader copy.
     *
     * @return array{
     *     grant: BreakGlassGrant,
     *     scope: array{label: string, value: string|null},
     *     requester: string|null,
     *     reason: string,
     *     requested_minutes: int,
     *     requested_at: string,
     *     expires_at: string|null,
     *     approved_at: string|null,
     *     approver: string|null,
     *     self_approved: bool,
     *     status: array{label: string, language: string|null}
     * }
     */
    private function grantItem(BreakGlassGrant $grant): array
    {
        return [
            'grant' => $grant,
            'scope' => $this->scopeParts($grant->scope_type, $grant->scopeLabel()),
            'requester' => $grant->requester?->name,
            'reason' => $grant->reason,
            'requested_minutes' => $grant->requested_minutes,
            'requested_at' => $grant->created_at->diffForHumans(),
            'expires_at' => $grant->expires_at?->diffForHumans(),
            'approved_at' => $grant->approved_at?->diffForHumans(),
            'approver' => $grant->approver?->name,
            'self_approved' => $grant->self_approved,
            'status' => $this->statusParts($grant),
        ];
    }

    /** @return array{label: string, value: string|null} */
    private function scopeParts(string $type, string $storedLabel): array
    {
        if ($type === BreakGlassGrant::SCOPE_ACCOUNT) {
            return ['label' => __('operator_access.scopes.account'), 'value' => null];
        }

        [$sourcePrefix, $key] = match ($type) {
            BreakGlassGrant::SCOPE_CONVERSATION => ['Conversation', 'conversation'],
            BreakGlassGrant::SCOPE_SITE => ['Site', 'site'],
            default => [null, null],
        };

        if ($sourcePrefix === null || $key === null) {
            return [
                'label' => __('operator_access.scopes.other'),
                'value' => $storedLabel !== '' ? $storedLabel : $type,
            ];
        }

        $value = str_starts_with($storedLabel, $sourcePrefix.' ')
            ? substr($storedLabel, strlen($sourcePrefix) + 1)
            : $storedLabel;

        if ($value === '(deleted)' || $value === '(out of scope)') {
            $state = $value === '(deleted)' ? 'deleted' : 'out_of_scope';

            return [
                'label' => __('operator_access.scopes.'.$key.'_'.$state),
                'value' => null,
            ];
        }

        return [
            'label' => __('operator_access.scopes.'.$key),
            'value' => $value !== '' ? $value : null,
        ];
    }

    /** @return array{label: string, language: string|null} */
    private function statusParts(BreakGlassGrant $grant): array
    {
        $status = $grant->status === BreakGlassGrant::STATUS_ACTIVE && ! $grant->isActive()
            ? BreakGlassGrant::STATUS_EXPIRED
            : $grant->status;

        $key = match ($status) {
            BreakGlassGrant::STATUS_REQUESTED => 'awaiting_approval',
            BreakGlassGrant::STATUS_ACTIVE => 'active',
            BreakGlassGrant::STATUS_DENIED => 'denied',
            BreakGlassGrant::STATUS_CLOSED => 'closed_early',
            BreakGlassGrant::STATUS_EXPIRED => 'expired',
            default => null,
        };

        return $key === null
            ? ['label' => $status, 'language' => '']
            : ['label' => __('operator_access.statuses.'.$key), 'language' => null];
    }

    /**
     * The POST flashes a key plus raw scope/time context. This GET turns that
     * context into the current request's language and the current reader's
     * clock, so redirects cannot freeze the previous request's presentation.
     *
     * @return array{key: string, scope: array{label: string, value: string|null}|null, until: string|null}|null
     */
    private function flashStatus(Request $request): ?array
    {
        $key = $request->session()->get('status');
        $simpleKeys = [
            'operator_access.flash.denied',
            'operator_access.flash.already_expired',
            'operator_access.flash.closed',
        ];

        if (is_string($key) && in_array($key, $simpleKeys, true)) {
            return ['key' => $key, 'scope' => null, 'until' => null];
        }

        if ($key !== 'operator_access.flash.approved') {
            return null;
        }

        $context = $request->session()->get('operator_access_status');
        $scopeType = is_array($context) ? ($context['scope_type'] ?? null) : null;
        $scopeLabel = is_array($context) ? ($context['scope_label'] ?? null) : null;
        $expiresAt = is_array($context) ? ($context['expires_at'] ?? null) : null;

        if (! is_string($scopeType) || ! is_string($scopeLabel) || ! is_string($expiresAt)) {
            return ['key' => 'operator_access.flash.approved_generic', 'scope' => null, 'until' => null];
        }

        try {
            $until = ReaderClock::timeWithZone(CarbonImmutable::parse($expiresAt));
        } catch (Throwable) {
            return ['key' => 'operator_access.flash.approved_generic', 'scope' => null, 'until' => null];
        }

        return [
            'key' => $key,
            'scope' => $this->scopeParts($scopeType, $scopeLabel),
            'until' => $until,
        ];
    }

    private function accountAdmin(Request $request): User
    {
        $agent = $request->user();

        abort_unless($agent?->account_id && $agent->isAdmin(), 403);

        return $agent;
    }

    private function accountGrant(Request $request, BreakGlassGrant $grant): User
    {
        $agent = $this->accountAdmin($request);

        abort_unless((int) $grant->account_id === (int) $agent->account_id, 404);

        return $agent;
    }
}
