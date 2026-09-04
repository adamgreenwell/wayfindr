<?php

namespace App\Http\Controllers;

use App\Enums\AccountPermission;
use App\Models\SlaClock;
use App\Models\User;
use App\Support\UnattendedConversationAlertCollector;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AgentAlertController extends Controller
{
    private const ALERT_KINDS = [
        'conversation' => 'conversation_needs_reply',
        'ticket' => 'ticket_assigned',
        'sla' => 'sla_deadline',
    ];

    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id && $agent->hasAccountPermission(AccountPermission::ViewAlerts), 403);

        $alertFilter = $request->query('alert_filter') === 'unread' ? 'unread' : 'all';
        $alertKind = $this->normalizedAlertKind($request->query('alert_kind'));
        $alertSearch = $this->normalizedAlertSearch($request->query('alert_search'));
        $visibleUnreadNotifications = $this->visibleUnreadNotifications($agent);
        $filteredUnreadNotifications = $this->filterNotifications($visibleUnreadNotifications, $alertKind, $alertSearch);
        // Computed whichever lane is showing. Deriving the "All alerts" badge
        // from the rendered collection made it report the UNREAD count while
        // viewing unread, and cap at 30 -- a badge that described the current
        // view rather than the destination it links to.
        $filteredAllNotifications = $this->filterNotifications(
            $this->visibleRecentNotifications($agent, $visibleUnreadNotifications),
            $alertKind,
            $alertSearch,
        );
        $filteredNotifications = $alertFilter === 'unread'
            ? $filteredUnreadNotifications
            : $filteredAllNotifications;
        $matchingNotificationCount = $filteredNotifications->count();
        $notifications = $filteredNotifications->take(30)->values();

        return view('agent.alerts.index', [
            'account' => $agent->account()->firstOrFail(),
            'agent' => $agent,
            'alertFilter' => $alertFilter,
            'alertKind' => $alertKind,
            'alertSearch' => $alertSearch,
            'alertCountSummary' => $this->alertCountSummary(
                $notifications->count(),
                $matchingNotificationCount,
                $alertFilter,
                $alertKind,
                $alertSearch,
            ),
            'alertEmptyState' => $this->alertEmptyState($alertFilter, $alertKind, $alertSearch),
            'alertDeliveryContext' => $this->alertDeliveryContext($agent),
            'alertSnapshot' => $this->alertSnapshot($notifications, $filteredUnreadNotifications->count()),
            'activeAlertFilters' => $this->activeAlertFilters($alertFilter, $alertKind, $alertSearch),
            'notifications' => $notifications,
            'notificationCount' => $notifications->count(),
            'unreadNotificationCount' => $filteredUnreadNotifications->count(),
            'alertLaneCounts' => [
                'all' => $filteredAllNotifications->count(),
                'unread' => $filteredUnreadNotifications->count(),
            ],
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $agent = $request->user();

        abort_unless(Gate::forUser($agent)->allows('markRead', $notification), 404);

        $notification->markAsRead();

        return $this->redirectAfterAlertAction($request);
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $agent = $request->user();
        abort_unless($agent->hasAccountPermission(AccountPermission::ViewAlerts), 403);
        $alertKind = $this->normalizedAlertKind($request->input('alert_kind'));
        $alertSearch = $this->normalizedAlertSearch($request->input('alert_search'));

        $agent
            ->unreadNotifications()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('markRead', $notification))
            ->filter(fn (DatabaseNotification $notification): bool => $this->notificationMatchesFilters($notification, $alertKind, $alertSearch))
            ->each
            ->markAsRead();

        return $this->redirectAfterAlertAction($request);
    }

    /**
     * @return array{
     *     source_detail: string,
     *     profile_href: string,
     *     items: list<array{label: string, value: string, detail: string}>
     * }
     */
    private function alertDeliveryContext(User $agent): array
    {
        $mode = $agent->alertMode();
        $cadence = $agent->alertCadence();
        $emailEnabled = $agent->alertEmailEnabled();

        return [
            'source_detail' => __('alerts.delivery.source_detail'),
            'profile_href' => route('dashboard.profile.show'),
            'items' => [
                [
                    'label' => __('alerts.delivery.mode.label'),
                    'value' => User::alertModeOptions()[$mode],
                    'detail' => match ($mode) {
                        User::ALERT_MODE_ASSIGNED => __('alerts.delivery.mode.assigned_detail'),
                        User::ALERT_MODE_QUIET => __('alerts.delivery.mode.quiet_detail'),
                        default => __('alerts.delivery.mode.all_detail'),
                    },
                ],
                [
                    'label' => __('alerts.delivery.email.label'),
                    'value' => match (true) {
                        ! $emailEnabled => __('alerts.delivery.email.off'),
                        $cadence === User::ALERT_CADENCE_DIGEST => __('alerts.delivery.email.digest'),
                        $cadence === User::ALERT_CADENCE_UNATTENDED => __('alerts.delivery.email.unattended'),
                        default => __('alerts.delivery.email.immediate'),
                    },
                    'detail' => match (true) {
                        ! $emailEnabled => __('alerts.delivery.email.off_detail'),
                        $cadence === User::ALERT_CADENCE_DIGEST => __('alerts.delivery.email.digest_detail'),
                        $cadence === User::ALERT_CADENCE_UNATTENDED => trans_choice(
                            'alerts.delivery.email.unattended_detail',
                            UnattendedConversationAlertCollector::THRESHOLD_MINUTES,
                            ['count' => UnattendedConversationAlertCollector::THRESHOLD_MINUTES],
                        ),
                        default => __('alerts.delivery.email.immediate_detail'),
                    },
                ],
            ],
        ];
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function visibleRecentNotifications(User $agent, ?Collection $visibleUnreadNotifications = null): Collection
    {
        $visibleUnreadNotifications ??= $this->visibleUnreadNotifications($agent);
        $visibleUnreadNotificationIds = $visibleUnreadNotifications->pluck('id');
        $visibleRecentNotifications = $agent
            ->notifications()
            ->latest()
            ->take(60)
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->reject(fn (DatabaseNotification $notification): bool => $visibleUnreadNotificationIds->contains($notification->id));

        return $visibleUnreadNotifications
            ->merge($visibleRecentNotifications)
            ->values();
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return Collection<int, DatabaseNotification>
     */
    private function filterNotifications(Collection $notifications, string $alertKind, string $alertSearch): Collection
    {
        return $notifications
            ->filter(fn (DatabaseNotification $notification): bool => $this->notificationMatchesFilters($notification, $alertKind, $alertSearch))
            ->values();
    }

    private function notificationMatchesFilters(DatabaseNotification $notification, string $alertKind, string $alertSearch): bool
    {
        if (! $this->notificationMatchesKind($notification, $alertKind)) {
            return false;
        }

        if ($alertSearch === '') {
            return true;
        }

        return Str::contains(
            Str::lower($this->notificationSearchHaystack($notification)),
            Str::lower($alertSearch),
        );
    }

    private function notificationSearchHaystack(DatabaseNotification $notification): string
    {
        $notificationData = $notification->data;
        $ticketId = data_get($notificationData, 'ticket_id');
        $notificationKind = data_get($notificationData, 'kind');
        $storedSubject = data_get($notificationData, 'subject');
        $localizedSubjectFallback = match (true) {
            $notificationKind === 'conversation_needs_reply'
                && (! filled($storedSubject) || $storedSubject === 'Untitled conversation') => __('alerts.card.untitled_conversation'),
            $notificationKind === 'ticket_assigned'
                && data_get($notificationData, 'subject_kind', 'ticket') === 'ticket'
                && ! filled($storedSubject) => __('alerts.card.untitled_ticket'),
            $notificationKind === 'sla_deadline'
                && data_get($notificationData, 'subject_kind') === 'ticket'
                && (! filled($storedSubject) || $storedSubject === 'Untitled ticket') => __('alerts.card.untitled_ticket'),
            $notificationKind === 'sla_deadline'
                && data_get($notificationData, 'subject_kind') === 'conversation'
                && (! filled($storedSubject) || $storedSubject === 'Untitled conversation') => __('alerts.card.untitled_conversation'),
            $notificationKind === 'automation_rule_matched'
                && data_get($notificationData, 'subject_kind') === 'ticket'
                && ! filled($storedSubject) => __('alerts.card.untitled_ticket'),
            $notificationKind === 'automation_rule_matched'
                && data_get($notificationData, 'subject_kind') === 'conversation'
                && ! filled($storedSubject) => __('alerts.card.untitled_conversation'),
            default => null,
        };
        $siteName = data_get($notificationData, 'site_name');
        $localizedSite = __('alerts.card.on_site', [
            'site' => filled($siteName) ? $siteName : __('alerts.card.unknown_site'),
        ]);
        $localizedTicketReference = $ticketId
            ? __('alerts.card.ticket_reference', ['id' => $ticketId])
            : null;
        $priority = in_array($notificationKind, ['ticket_assigned', 'sla_deadline'], true)
            ? (string) data_get($notificationData, 'priority', 'normal')
            : null;
        $priorityKey = 'tickets.priorities.'.$priority;
        $localizedPriorityLabel = $priority !== null ? __($priorityKey) : null;
        $localizedPriority = $priority !== null
            ? __('alerts.card.priority', [
                'priority' => $localizedPriorityLabel === $priorityKey ? $priority : $localizedPriorityLabel,
            ])
            : null;
        $metric = $notificationKind === 'sla_deadline'
            ? (string) data_get($notificationData, 'metric', SlaClock::METRIC_RESOLUTION)
            : null;
        $localizedMetric = $metric !== null ? __('sla.metrics.'.$metric) : null;
        $localizedStage = $notificationKind === 'sla_deadline'
            ? (data_get($notificationData, 'stage') === 'breach'
                ? __('alerts.card.sla_breached')
                : __('alerts.card.sla_warning'))
            : null;

        return collect([
            $storedSubject,
            $localizedSubjectFallback,
            data_get($notificationData, 'support_code'),
            $ticketId ? 'Ticket #'.$ticketId : null,
            $localizedTicketReference,
            $ticketId,
            $siteName,
            $localizedSite,
            data_get($notificationData, 'message_preview'),
            data_get($notificationData, 'assigned_by_name'),
            data_get($notificationData, 'rule_name'),
            data_get($notificationData, 'visitor_anonymous_id'),
            $metric,
            $localizedMetric,
            data_get($notificationData, 'stage'),
            $localizedStage,
            $priority,
            $localizedPriority,
        ])
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn ($value): string => trim((string) $value))
            ->implode(' ');
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function visibleUnreadNotifications(User $agent): Collection
    {
        return $agent
            ->unreadNotifications()
            ->latest()
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => Gate::forUser($agent)->allows('view', $notification))
            ->values();
    }

    /**
     * @return array<int, array{label: string, value: string, detail: string}>
     */
    private function alertSnapshot(Collection $visibleNotifications, int $visibleUnreadNotificationCount): array
    {
        $conversationAlertCount = $visibleNotifications
            ->filter(fn (DatabaseNotification $notification): bool => $this->notificationMatchesKind($notification, 'conversation'))
            ->count();
        $ticketAlertCount = $visibleNotifications
            ->filter(fn (DatabaseNotification $notification): bool => $this->notificationMatchesKind($notification, 'ticket'))
            ->count();
        $slaAlertCount = $visibleNotifications
            ->filter(fn (DatabaseNotification $notification): bool => data_get($notification->data, 'kind') === 'sla_deadline')
            ->count();

        return [
            [
                'label' => __('alerts.snapshot.visible.label'),
                'value' => trans_choice('alerts.counts.visible', $visibleNotifications->count(), ['count' => $visibleNotifications->count()]),
                'detail' => $visibleNotifications->isNotEmpty()
                    ? __('alerts.snapshot.visible.present')
                    : __('alerts.snapshot.visible.empty'),
            ],
            [
                'label' => __('alerts.snapshot.unread.label'),
                'value' => trans_choice('alerts.counts.unread', $visibleUnreadNotificationCount, ['count' => $visibleUnreadNotificationCount]),
                'detail' => $visibleUnreadNotificationCount > 0
                    ? __('alerts.snapshot.unread.present')
                    : __('alerts.snapshot.unread.empty'),
            ],
            [
                'label' => __('alerts.snapshot.conversations.label'),
                'value' => trans_choice('alerts.counts.conversations', $conversationAlertCount, ['count' => $conversationAlertCount]),
                'detail' => $conversationAlertCount > 0
                    ? __('alerts.snapshot.conversations.present')
                    : __('alerts.snapshot.conversations.empty'),
            ],
            [
                'label' => __('alerts.snapshot.tickets.label'),
                'value' => trans_choice('alerts.counts.tickets', $ticketAlertCount, ['count' => $ticketAlertCount]),
                'detail' => $ticketAlertCount > 0
                    ? __('alerts.snapshot.tickets.present')
                    : __('alerts.snapshot.tickets.empty'),
            ],
            [
                'label' => __('alerts.snapshot.sla.label'),
                'value' => trans_choice('alerts.counts.sla', $slaAlertCount, ['count' => $slaAlertCount]),
                'detail' => $slaAlertCount > 0
                    ? __('alerts.snapshot.sla.present')
                    : __('alerts.snapshot.sla.empty'),
            ],
        ];
    }

    /**
     * @return array{heading: string, detail: ?string}
     */
    private function alertCountSummary(
        int $visibleNotificationCount,
        int $matchingNotificationCount,
        string $alertFilter,
        string $alertKind,
        string $alertSearch,
    ): array {
        $hasAlertFilters = $alertKind !== 'all' || $alertSearch !== '';
        $isUnreadOnlyView = $alertFilter === 'unread';
        $isCapped = $matchingNotificationCount > $visibleNotificationCount;

        if ($isCapped && ($hasAlertFilters || $isUnreadOnlyView)) {
            $summaryKind = $this->alertSummaryKind($alertFilter, $alertKind);

            return [
                'heading' => trans_choice('alerts.summary.capped_heading.'.$summaryKind, $matchingNotificationCount, [
                    'shown' => $visibleNotificationCount,
                    'count' => $matchingNotificationCount,
                ]),
                'detail' => trans_choice('alerts.summary.capped_detail.'.$summaryKind, $matchingNotificationCount, [
                    'shown' => $visibleNotificationCount,
                    'count' => $matchingNotificationCount,
                ]),
            ];
        }

        if ($hasAlertFilters) {
            $summaryKind = $this->alertSummaryKind($alertFilter, $alertKind);

            return [
                'heading' => trans_choice('alerts.summary.matching_heading.'.$summaryKind, $visibleNotificationCount, [
                    'count' => $visibleNotificationCount,
                ]),
                'detail' => null,
            ];
        }

        if ($alertFilter === 'unread') {
            return [
                'heading' => __('alerts.summary.unread_heading'),
                'detail' => null,
            ];
        }

        return [
            'heading' => trans_choice('alerts.summary.latest', $visibleNotificationCount, ['count' => $visibleNotificationCount]),
            'detail' => null,
        ];
    }

    private function alertSummaryKind(string $alertFilter, string $alertKind): string
    {
        if ($alertKind === 'conversation') {
            return 'conversation';
        }

        if ($alertKind === 'ticket') {
            return 'ticket';
        }

        if ($alertKind === 'sla') {
            return 'sla';
        }

        if ($alertFilter === 'unread') {
            return 'unread';
        }

        return 'all';
    }

    /**
     * @return array{
     *     heading: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>},
     *     detail: string,
     *     actions: list<array{label: string, url: string}>
     * }
     */
    private function alertEmptyState(string $alertFilter, string $alertKind, string $alertSearch): array
    {
        if ($alertSearch !== '') {
            return [
                'heading' => [
                    'key' => 'alerts.empty.search.heading',
                    'parameters' => ['search' => $alertSearch],
                    'localized_parameters' => [],
                ],
                'detail' => __('alerts.empty.search.detail'),
                'actions' => [
                    [
                        'label' => __('alerts.actions.clear_search'),
                        'url' => route('dashboard.alerts.index', $this->alertReturnParams($alertFilter, $alertKind, '')),
                    ],
                    [
                        'label' => __('alerts.actions.clear_all_filters'),
                        'url' => route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []),
                    ],
                ],
            ];
        }

        if ($alertKind !== 'all') {
            return [
                'heading' => [
                    'key' => 'alerts.empty.kind.'.$this->alertSummaryKind($alertFilter, $alertKind),
                    'parameters' => [],
                    'localized_parameters' => [],
                ],
                'detail' => __('alerts.empty.kind.detail'),
                'actions' => [
                    [
                        'label' => __('alerts.actions.clear_type'),
                        'url' => route('dashboard.alerts.index', $this->alertReturnParams($alertFilter, 'all', $alertSearch)),
                    ],
                    [
                        'label' => __('alerts.actions.clear_all_filters'),
                        'url' => route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []),
                    ],
                ],
            ];
        }

        if ($alertFilter === 'unread') {
            return [
                'heading' => [
                    'key' => 'alerts.empty.unread.heading',
                    'parameters' => [],
                    'localized_parameters' => [],
                ],
                'detail' => __('alerts.empty.unread.detail'),
                'actions' => [
                    [
                        'label' => __('alerts.actions.show_recent'),
                        'url' => route('dashboard.alerts.index'),
                    ],
                ],
            ];
        }

        return [
            'heading' => [
                'key' => 'alerts.empty.all.heading',
                'parameters' => [],
                'localized_parameters' => [],
            ],
            'detail' => __('alerts.empty.all.detail'),
            'actions' => [
                [
                    'label' => __('alerts.actions.back_to_dashboard'),
                    'url' => route('dashboard'),
                ],
                [
                    'label' => __('alerts.actions.review_preferences'),
                    'url' => route('dashboard.profile.show'),
                ],
            ],
        ];
    }

    private function redirectAfterAlertAction(Request $request): RedirectResponse
    {
        if ($request->input('return_to') === 'alerts') {
            return redirect()->route('dashboard.alerts.index', $this->alertReturnParams(
                $request->input('alert_filter') === 'unread' ? 'unread' : 'all',
                $this->normalizedAlertKind($request->input('alert_kind')),
                $this->normalizedAlertSearch($request->input('alert_search')),
            ));
        }

        return redirect()->to(route('dashboard').'#alerts');
    }

    private function normalizedAlertKind(mixed $value): string
    {
        return is_string($value) && array_key_exists($value, self::ALERT_KINDS) ? $value : 'all';
    }

    private function notificationMatchesKind(DatabaseNotification $notification, string $alertKind): bool
    {
        if ($alertKind === 'all') {
            return true;
        }

        $kind = data_get($notification->data, 'kind');

        if ($kind === 'automation_rule_matched') {
            return data_get($notification->data, 'subject_kind') === $alertKind;
        }

        return $kind === self::ALERT_KINDS[$alertKind];
    }

    private function normalizedAlertSearch(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return Str::limit(trim((string) $value), 120, '');
    }

    /**
     * @return array<string, string>
     */
    private function alertReturnParams(string $alertFilter, string $alertKind, string $alertSearch): array
    {
        $params = [];

        if ($alertFilter === 'unread') {
            $params['alert_filter'] = 'unread';
        }

        if ($alertKind !== 'all') {
            $params['alert_kind'] = $alertKind;
        }

        if ($alertSearch !== '') {
            $params['alert_search'] = $alertSearch;
        }

        return $params;
    }

    /**
     * @return array<int, array{
     *     feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>},
     *     href: string
     * }>
     */
    private function activeAlertFilters(string $alertFilter, string $alertKind, string $alertSearch): array
    {
        $alertQuery = $this->alertReturnParams($alertFilter, $alertKind, $alertSearch);
        $filters = [];

        if ($alertKind !== 'all') {
            $filters[] = $this->alertFilterChip(
                'alert_kind',
                [
                    'key' => 'alerts.chips.type',
                    'parameters' => [],
                    'localized_parameters' => ['value' => $this->alertKindLabels()[$alertKind]],
                ],
                $alertQuery,
            );
        }

        if ($alertSearch !== '') {
            $filters[] = $this->alertFilterChip('alert_search', [
                'key' => 'alerts.chips.search',
                'parameters' => ['value' => $alertSearch],
                'localized_parameters' => [],
            ], $alertQuery);
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $alertQuery
     * @param  array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}  $feedback
     * @return array{feedback: array{key: string, parameters: array<string, string>, localized_parameters: array<string, string>}, href: string}
     */
    private function alertFilterChip(string $queryKey, array $feedback, array $alertQuery): array
    {
        unset($alertQuery[$queryKey]);

        return [
            'feedback' => $feedback,
            'href' => route('dashboard.alerts.index', $alertQuery),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function alertKindLabels(): array
    {
        return [
            'conversation' => __('alerts.kinds.conversation'),
            'ticket' => __('alerts.kinds.ticket'),
            'sla' => __('alerts.kinds.sla'),
        ];
    }
}
