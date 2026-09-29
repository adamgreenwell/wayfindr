<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\AccountAlertReadiness;
use App\Support\ReaderNumber;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The account's people: who is on it, what each of them may do, which sites
 * they answer, and the recent changes to all three. It was the lower
 * two-thirds of the account overview until the overview had become the
 * roster's page with a summary on top.
 */
class AgentAccountTeamController extends Controller
{
    public function __invoke(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent?->account_id, 403);
        $agent->loadMissing('customRole');

        $account = $agent->account()->firstOrFail();
        $visibleSiteIds = $account->sites()
            ->visibleToAgent($agent)
            ->pluck('sites.id')
            ->map(fn (int|string $siteId): int => (int) $siteId)
            ->all();
        $canViewConversations = $agent->hasAccountPermission(AccountPermission::ViewConversations);
        $canManageTickets = $agent->hasAccountPermission(AccountPermission::ManageTickets);
        $workloadCounts = [];

        if ($canViewConversations) {
            $workloadCounts['assignedConversations as visible_open_conversations_count'] = fn ($query) => $query
                ->where('status', 'open')
                ->whereIn('site_id', $visibleSiteIds);
        }

        if ($canManageTickets) {
            $workloadCounts['assignedTickets as visible_open_tickets_count'] = fn ($query) => $query
                ->where('account_id', $account->id)
                ->where('status', 'open')
                ->whereIn('site_id', $visibleSiteIds);
        }

        $agentsQuery = $account->agents()->with('customRole');

        if ($workloadCounts !== []) {
            $agentsQuery->withCount($workloadCounts);
        }

        $agents = $agentsQuery
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
                ->with('customRole')
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

        return view('agent.account.team', [
            'accountActivity' => $this->accountActivityItems($account, $visibleSiteIds),
            'agent' => $agent,
            'agentAlertReadinessSummary' => $agent->hasAccountPermission(AccountPermission::ManageAgents)
                ? app(AccountAlertReadiness::class)->summarize($agents)
                : null,
            'agentAlertDeliverySummaries' => $agents->mapWithKeys(fn (User $accountAgent): array => [
                $accountAgent->id => $this->agentAlertDeliverySummary($accountAgent),
            ]),
            'agents' => $agents,
            'agentSupportScopes' => $agentSupportScopes,
            'activeAgentCount' => $agents->reject->isDeactivated()->count(),
            'canCreateAgents' => $agent->hasAccountPermission(AccountPermission::ManageAgents),
            'newAgentRoleLabel' => $agent->custom_role_id !== null
                ? ($agent->customRole?->name ?? __('profile.roles.agent'))
                : __('profile.roles.agent'),
            'canViewAlertDelivery' => $agent->hasAccountPermission(AccountPermission::ManageAgents),
            'canManageAgentAccess' => $agent->hasAccountPermission(AccountPermission::ManageAgents),
            'canManageRoles' => $agent->hasAccountPermission(AccountPermission::ManageRoles),
            'canManageTickets' => $canManageTickets,
            'canViewConversations' => $canViewConversations,
            'canViewAudit' => $agent->hasAccountPermission(AccountPermission::ViewAudit),
            'roleLabels' => AccountRole::labels(),
            'roleOptions' => [
                ...AccountRole::labels(),
                ...$account->customRoles()
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn ($role): array => ['custom:'.$role->id => $role->name])
                    ->all(),
            ],
            'visibleSites' => $visibleSites,
        ]);
    }

    /**
     * @return array{primary: string, lines: array<int, array{text: string, tone?: string}>}
     */
    private function agentAlertDeliverySummary(User $accountAgent): array
    {
        if ($accountAgent->isDeactivated()) {
            return [
                'primary' => __('account.agents.alert_delivery.deactivated'),
                'lines' => [
                    ['text' => __('account.agents.alert_delivery.deactivated_detail')],
                ],
            ];
        }

        if ($accountAgent->alertMode() === User::ALERT_MODE_QUIET) {
            return [
                'primary' => __('account.agents.alert_delivery.quiet_mode'),
                'lines' => [
                    ['text' => __('account.agents.alert_delivery.quiet_detail')],
                ],
            ];
        }

        [$scopeLabel, $scopeDetail] = $this->agentAlertScopeSummary($accountAgent);

        if (! $accountAgent->alertEmailEnabled()) {
            return [
                'primary' => __('account.agents.alert_delivery.email_off'),
                'lines' => [
                    ['text' => $scopeLabel, 'tone' => 'manual'],
                    ['text' => $scopeDetail],
                ],
            ];
        }

        if ($accountAgent->alertCadence() === User::ALERT_CADENCE_DIGEST) {
            $digestDeliveryStatus = $accountAgent->alertDigestDeliveryStatus();
            $digestDeliveryTone = match ($digestDeliveryStatus['status']) {
                User::ALERT_DIGEST_DELIVERY_FAILED => 'attention',
                User::ALERT_DIGEST_DELIVERY_NOT_RUN => 'manual',
                default => 'ready',
            };
            $lines = [
                ['text' => $scopeLabel, 'tone' => 'ready'],
                ['text' => $digestDeliveryStatus['label'], 'tone' => $digestDeliveryTone],
                ['text' => $digestDeliveryStatus['message']],
            ];

            if ($digestDeliveryStatus['last_attempted_at']) {
                $lines[] = ['text' => __('account.agents.alert_delivery.last_attempt', [
                    'elapsed' => $digestDeliveryStatus['last_attempted_at']->diffForHumans(),
                ])];
            }

            return [
                'primary' => __('account.agents.alert_delivery.digest_delivery'),
                'lines' => $lines,
            ];
        }

        if ($accountAgent->alertCadence() === User::ALERT_CADENCE_UNATTENDED) {
            return [
                'primary' => __('account.agents.alert_delivery.unattended'),
                'lines' => [
                    ['text' => $scopeLabel, 'tone' => 'ready'],
                    ['text' => __('account.agents.alert_delivery.unattended_detail', [
                        'minutes' => ReaderNumber::count(UnattendedConversationAlertCollector::THRESHOLD_MINUTES),
                    ])],
                    ['text' => $scopeDetail],
                ],
            ];
        }

        return [
            'primary' => __('account.agents.alert_delivery.immediate'),
            'lines' => [
                ['text' => $scopeLabel, 'tone' => 'ready'],
                ['text' => __('account.agents.alert_delivery.immediate_detail')],
                ['text' => $scopeDetail],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function agentAlertScopeSummary(User $accountAgent): array
    {
        if ($accountAgent->alertMode() === User::ALERT_MODE_ASSIGNED) {
            return [
                __('account.agents.alert_delivery.assigned_only'),
                __('account.agents.alert_delivery.assigned_detail'),
            ];
        }

        return [
            __('account.agents.alert_delivery.all'),
            __('account.agents.alert_delivery.all_detail'),
        ];
    }

    /**
     * @return Collection<int, array{label: string, actor: string, actor_language: string|null, subject: string, subject_language: string|null, body: string, occurred_at: Carbon|null}>
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
                'actor_language' => $event->actor instanceof User ? '' : null,
                'subject' => $this->accountActivitySubject($event),
                'subject_language' => $event->subject instanceof User || $event->subject instanceof Site ? '' : null,
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
            'agent.created' => __('account.activity.labels.agent_created'),
            'agent.deactivated' => __('account.activity.labels.agent_deactivated'),
            'agent.password_updated' => __('account.activity.labels.password_changed'),
            'agent.reactivated' => __('account.activity.labels.agent_reactivated'),
            'agent.role_changed' => __('account.activity.labels.role_changed'),
            'site_access.updated' => __('account.activity.labels.site_access'),
            default => __('account.activity.labels.default'),
        };
    }

    private function accountActivityActor(AuditEvent $event): string
    {
        if ($event->actor instanceof User) {
            return $event->actor->name;
        }

        return __('account.activity.system');
    }

    private function accountActivitySubject(AuditEvent $event): string
    {
        if ($event->subject instanceof User) {
            return $event->subject->name;
        }

        if ($event->subject instanceof Site) {
            return $event->subject->name;
        }

        return __('account.activity.account');
    }

    private function accountActivityBody(AuditEvent $event): string
    {
        return match ($event->action) {
            'agent.created' => __('account.activity.bodies.agent_created'),
            'agent.deactivated' => __('account.activity.bodies.agent_deactivated'),
            'agent.password_updated' => __('account.activity.bodies.password_changed'),
            'agent.reactivated' => __('account.activity.bodies.agent_reactivated'),
            'agent.role_changed' => $this->accountRoleChangeBody($event),
            'site_access.updated' => __('account.activity.bodies.site_access'),
            default => __('account.activity.bodies.default'),
        };
    }

    private function accountRoleChangeBody(AuditEvent $event): string
    {
        $oldRole = data_get($event->metadata, 'old_role');
        $newRole = data_get($event->metadata, 'new_role');
        $oldRoleName = data_get($event->metadata, 'old_role_name');
        $newRoleName = data_get($event->metadata, 'new_role_name');
        $roleLabels = AccountRole::labels();

        if (is_string($oldRoleName) && is_string($newRoleName)) {
            return __('account.activity.bodies.role_changed', [
                'old' => $roleLabels[$oldRoleName] ?? $oldRoleName,
                'new' => $roleLabels[$newRoleName] ?? $newRoleName,
            ]);
        }

        if (is_string($oldRole) && is_string($newRole)
            && isset($roleLabels[$oldRole], $roleLabels[$newRole])) {
            return __('account.activity.bodies.role_changed', [
                'old' => $roleLabels[$oldRole],
                'new' => $roleLabels[$newRole],
            ]);
        }

        return __('account.activity.bodies.role_changed_unknown');
    }
}
