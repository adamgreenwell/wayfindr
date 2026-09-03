<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Models\Account;
use App\Models\BreakGlassGrant;
use App\Models\User;
use App\Support\OperatorBreakGlassPresenter;
use App\Support\ReaderClock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

final class ActiveBreakGlassBanner extends Component
{
    /**
     * @var Collection<int, array{
     *     elapsed: string,
     *     requester: string|null,
     *     scope: array{label: string, value: string|null},
     *     self_approved: bool,
     *     until: string
     * }>
     */
    public readonly Collection $grants;

    public function __construct(
        public readonly User $agent,
        public readonly Account $account,
    ) {
        $this->grants = BreakGlassGrant::query()
            ->where('account_id', $account->id)
            ->active()
            ->with(['requester', 'conversation.site', 'site'])
            ->orderBy('expires_at')
            ->get()
            ->map(fn (BreakGlassGrant $grant): array => [
                'elapsed' => $grant->expires_at->diffForHumans(),
                'requester' => $grant->requester?->name,
                'scope' => OperatorBreakGlassPresenter::scope($grant->scope_type, $grant->scopeLabel()),
                'self_approved' => $grant->self_approved,
                'until' => ReaderClock::timeWithZone($grant->expires_at),
            ]);
    }

    public function shouldRender(): bool
    {
        return $this->grants->isNotEmpty();
    }

    public function render(): View
    {
        return view('components.active-break-glass-banner');
    }
}
