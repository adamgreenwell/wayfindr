<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AgentAccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);

        $account = $agent->account()->firstOrFail();
        $visibleSiteIds = $account->sites()
            ->visibleToAgent($agent)
            ->pluck('sites.id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->all();

        $agents = $account->agents()
            ->withCount([
                'assignedConversations as visible_open_conversations_count' => fn ($query) => $query
                    ->where('status', 'open')
                    ->whereIn('site_id', $visibleSiteIds),
                'assignedTickets as visible_open_tickets_count' => fn ($query) => $query
                    ->where('account_id', $account->id)
                    ->where('status', 'open')
                    ->whereIn('site_id', $visibleSiteIds),
            ])
            ->orderByRaw(
                'case account_role when ? then 0 when ? then 1 else 2 end',
                [AccountRole::Owner->value, AccountRole::Admin->value],
            )
            ->orderBy('name')
            ->orderBy('email')
            ->get();

        $visibleSites = $account->sites()
            ->visibleToAgent($agent)
            ->with(['supportAgents' => fn ($query) => $query
                ->where('users.account_id', $account->id)
                ->whereNull('users.deactivated_at')
                ->orderByRaw(
                    'case account_role when ? then 0 when ? then 1 else 2 end',
                    [AccountRole::Owner->value, AccountRole::Admin->value],
                )
                ->orderBy('name')
                ->orderBy('email')])
            ->orderBy('name')
            ->get();

        $fallbackSites = $visibleSites
            ->filter(fn ($site): bool => $site->supportAgents->isEmpty())
            ->values();

        $agentSupportScopes = $agents->mapWithKeys(fn ($accountAgent): array => [
            $accountAgent->id => [
                'explicitSites' => $visibleSites
                    ->filter(fn ($site): bool => $site->supportAgents->contains('id', $accountAgent->id))
                    ->values(),
                'fallbackSites' => $accountAgent->isDeactivated() ? collect() : $fallbackSites,
            ],
        ]);

        return view('agent.account.show', [
            'account' => $account,
            'accountActivity' => $this->accountActivityItems($account, $visibleSiteIds),
            'agent' => $agent,
            'agents' => $agents,
            'agentSupportScopes' => $agentSupportScopes,
            'activeAgentCount' => $agents->reject->isDeactivated()->count(),
            'canCreateAgents' => $agent->isAdmin(),
            'canViewAlertDelivery' => $agent->isAdmin(),
            'canManageAgentAccess' => $agent->isAdmin(),
            'canManageRoles' => $agent->isOwner(),
            'canViewAudit' => $agent->isAdmin(),
            'roleLabels' => $this->roleLabels(),
            'roleOptions' => $this->roleLabels(),
            'siteCount' => $account->sites()->count(),
            'supportAssignmentCount' => $agentSupportScopes
                ->sum(fn (array $scope): int => $scope['explicitSites']->count()),
            'visibleSites' => $visibleSites,
            'visibleSiteCount' => count($visibleSiteIds),
        ]);
    }

    /**
     * @return Collection<int, array{label: string, actor: string, subject: string, body: string, occurred_at: Carbon|null}>
     */
    private function accountActivityItems(Account $account, array $visibleSiteIds): Collection
    {
        return $account->auditEvents()
            ->with(['actor', 'subject'])
            ->whereIn('action', $this->accountActivityActions())
            ->where(function ($query) use ($visibleSiteIds): void {
                $query->where('action', '!=', 'site_access.updated');

                if ($visibleSiteIds !== []) {
                    $query->orWhere(function ($siteAccessQuery) use ($visibleSiteIds): void {
                        $siteAccessQuery
                            ->where('action', 'site_access.updated')
                            ->whereIn('site_id', $visibleSiteIds);
                    });
                }
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'label' => $this->accountActivityLabel($event),
                'actor' => $this->accountActivityActor($event),
                'subject' => $this->accountActivitySubject($event),
                'body' => $this->accountActivityBody($event),
                'occurred_at' => $event->occurred_at,
            ]);
    }

    /**
     * @return array<int, string>
     */
    private function accountActivityActions(): array
    {
        return [
            'agent.created',
            'agent.deactivated',
            'agent.password_updated',
            'agent.reactivated',
            'agent.role_changed',
            'site_access.updated',
        ];
    }

    private function accountActivityLabel(AuditEvent $event): string
    {
        return match ($event->action) {
            'agent.created' => 'Agent created',
            'agent.deactivated' => 'Agent deactivated',
            'agent.password_updated' => 'Password changed',
            'agent.reactivated' => 'Agent reactivated',
            'agent.role_changed' => 'Agent role changed',
            'site_access.updated' => 'Site access updated',
            default => 'Account activity',
        };
    }

    private function accountActivityActor(AuditEvent $event): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        return 'System';
    }

    private function accountActivitySubject(AuditEvent $event): string
    {
        if ($event->subject instanceof User) {
            return $event->subject->name;
        }

        if ($event->subject instanceof Site) {
            return $event->subject->name;
        }

        return 'Account';
    }

    private function accountActivityBody(AuditEvent $event): string
    {
        return match ($event->action) {
            'agent.created' => 'Created agent account',
            'agent.deactivated' => 'Suspended agent access',
            'agent.password_updated' => 'Changed password',
            'agent.reactivated' => 'Restored agent access',
            'agent.role_changed' => $this->accountRoleChangeBody($event),
            'site_access.updated' => 'Updated support access',
            default => 'Recorded account activity',
        };
    }

    private function accountRoleChangeBody(AuditEvent $event): string
    {
        $oldRole = data_get($event->metadata, 'old_role');
        $newRole = data_get($event->metadata, 'new_role');
        $roleLabels = $this->roleLabels();

        if (is_string($oldRole) && is_string($newRole)) {
            return sprintf(
                'Changed role from %s to %s',
                $roleLabels[$oldRole] ?? str($oldRole)->headline()->toString(),
                $roleLabels[$newRole] ?? str($newRole)->headline()->toString(),
            );
        }

        return 'Changed account role';
    }

    /**
     * @return array<string, string>
     */
    private function roleLabels(): array
    {
        return [
            AccountRole::Owner->value => 'Owner',
            AccountRole::Admin->value => 'Admin',
            AccountRole::Agent->value => 'Agent',
        ];
    }
}
