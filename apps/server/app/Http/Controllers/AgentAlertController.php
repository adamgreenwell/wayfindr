<?php

namespace App\Http\Controllers;

use App\Models\User;
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
    ];

    public function index(Request $request): View
    {
        $agent = $request->user();

        abort_unless($agent->account_id, 403);

        $alertFilter = $request->query('alert_filter') === 'unread' ? 'unread' : 'all';
        $alertKind = $this->normalizedAlertKind($request->query('alert_kind'));
        $alertSearch = $this->normalizedAlertSearch($request->query('alert_search'));
        $visibleUnreadNotifications = $this->visibleUnreadNotifications($agent);
        $filteredUnreadNotifications = $this->filterNotifications($visibleUnreadNotifications, $alertKind, $alertSearch);
        $filteredNotifications = $alertFilter === 'unread'
            ? $filteredUnreadNotifications
            : $this->filterNotifications($this->visibleRecentNotifications($agent, $visibleUnreadNotifications), $alertKind, $alertSearch);
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
            'alertSnapshot' => $this->alertSnapshot($notifications, $filteredUnreadNotifications->count()),
            'activeAlertFilters' => $this->activeAlertFilters($alertFilter, $alertKind, $alertSearch),
            'notifications' => $notifications,
            'notificationCount' => $notifications->count(),
            'unreadNotificationCount' => $filteredUnreadNotifications->count(),
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
        if ($alertKind !== 'all' && data_get($notification->data, 'kind') !== self::ALERT_KINDS[$alertKind]) {
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

        return collect([
            data_get($notificationData, 'subject'),
            data_get($notificationData, 'support_code'),
            $ticketId ? 'Ticket #'.$ticketId : null,
            $ticketId,
            data_get($notificationData, 'site_name'),
            data_get($notificationData, 'message_preview'),
            data_get($notificationData, 'assigned_by_name'),
            data_get($notificationData, 'visitor_anonymous_id'),
            data_get($notificationData, 'priority'),
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
            ->filter(fn (DatabaseNotification $notification): bool => data_get($notification->data, 'kind') === 'conversation_needs_reply')
            ->count();
        $ticketAlertCount = $visibleNotifications
            ->filter(fn (DatabaseNotification $notification): bool => data_get($notification->data, 'kind') === 'ticket_assigned')
            ->count();

        return [
            [
                'label' => 'Visible alerts',
                'value' => $visibleNotifications->count().' visible',
                'detail' => $visibleNotifications->isNotEmpty()
                    ? 'Current alerts you can still open.'
                    : 'Nothing currently needs attention in this alert view.',
            ],
            [
                'label' => 'Unread alerts',
                'value' => $visibleUnreadNotificationCount.' unread',
                'detail' => $visibleUnreadNotificationCount > 0
                    ? 'Still waiting for review or mark-read.'
                    : 'No unread alerts are waiting for review.',
            ],
            [
                'label' => 'Conversation alerts',
                'value' => $conversationAlertCount.' '.Str::plural('conversation', $conversationAlertCount),
                'detail' => $conversationAlertCount > 0
                    ? 'Visitor replies and chat follow-up.'
                    : 'No visitor reply alerts in this view.',
            ],
            [
                'label' => 'Ticket alerts',
                'value' => $ticketAlertCount.' '.Str::plural('ticket', $ticketAlertCount),
                'detail' => $ticketAlertCount > 0
                    ? 'Ticket assignments and durable work.'
                    : 'No ticket assignment alerts in this view.',
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
            $matchingAlertLabel = $this->matchingAlertLabel($alertFilter, $alertKind, $matchingNotificationCount);

            return [
                'heading' => "{$visibleNotificationCount} shown of {$matchingNotificationCount} matching {$matchingAlertLabel}.",
                'detail' => "Showing {$visibleNotificationCount} alerts after the current display cap. {$matchingNotificationCount} {$matchingAlertLabel} match this view.",
            ];
        }

        if ($hasAlertFilters) {
            $matchingAlertLabel = $this->matchingAlertLabel($alertFilter, $alertKind, $visibleNotificationCount);

            return [
                'heading' => "Showing {$visibleNotificationCount} matching {$matchingAlertLabel}.",
                'detail' => null,
            ];
        }

        if ($alertFilter === 'unread') {
            return [
                'heading' => 'Showing unread visible alerts.',
                'detail' => null,
            ];
        }

        return [
            'heading' => "Showing the latest {$visibleNotificationCount} visible ".Str::plural('alert', $visibleNotificationCount).'.',
            'detail' => null,
        ];
    }

    private function matchingAlertLabel(string $alertFilter, string $alertKind, int $alertCount): string
    {
        if ($alertKind === 'conversation') {
            return Str::plural('conversation alert', $alertCount);
        }

        if ($alertKind === 'ticket') {
            return Str::plural('ticket alert', $alertCount);
        }

        if ($alertFilter === 'unread') {
            return 'unread '.Str::plural('alert', $alertCount);
        }

        return Str::plural('alert', $alertCount);
    }

    /**
     * @return array{heading: string, detail: string, actions: list<array{label: string, url: string}>}
     */
    private function alertEmptyState(string $alertFilter, string $alertKind, string $alertSearch): array
    {
        if ($alertSearch !== '') {
            return [
                'heading' => sprintf('No alerts match "%s".', $alertSearch),
                'detail' => 'Search checks support codes, ticket numbers, subjects, sites, visitors, and message previews you can still access.',
                'actions' => [
                    [
                        'label' => 'Clear search',
                        'url' => route('dashboard.alerts.index', $this->alertReturnParams($alertFilter, $alertKind, '')),
                    ],
                    [
                        'label' => 'Clear all alert filters',
                        'url' => route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []),
                    ],
                ],
            ];
        }

        if ($alertKind !== 'all') {
            return [
                'heading' => 'No '.$this->matchingAlertLabel($alertFilter, $alertKind, 2).' match this view.',
                'detail' => 'Try all alert types to include the other support signals you can still access.',
                'actions' => [
                    [
                        'label' => 'Clear alert type filter',
                        'url' => route('dashboard.alerts.index', $this->alertReturnParams($alertFilter, 'all', $alertSearch)),
                    ],
                    [
                        'label' => 'Clear all alert filters',
                        'url' => route('dashboard.alerts.index', $alertFilter === 'unread' ? ['alert_filter' => 'unread'] : []),
                    ],
                ],
            ];
        }

        if ($alertFilter === 'unread') {
            return [
                'heading' => 'You are caught up.',
                'detail' => 'New eligible visitor replies and ticket assignments will appear here when they need attention.',
                'actions' => [
                    [
                        'label' => 'Show recent alerts',
                        'url' => route('dashboard.alerts.index'),
                    ],
                ],
            ];
        }

        return [
            'heading' => 'No visible alerts yet.',
            'detail' => 'Visitor replies and ticket assignments you can support will appear here once they need attention.',
            'actions' => [
                [
                    'label' => 'Back to dashboard',
                    'url' => route('dashboard'),
                ],
                [
                    'label' => 'Review alert preferences',
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
     * @return array<int, array{label: string, href: string}>
     */
    private function activeAlertFilters(string $alertFilter, string $alertKind, string $alertSearch): array
    {
        $alertQuery = $this->alertReturnParams($alertFilter, $alertKind, $alertSearch);
        $filters = [];

        if ($alertKind !== 'all') {
            $filters[] = $this->alertFilterChip(
                'alert_kind',
                'Type: '.$this->alertKindLabels()[$alertKind],
                $alertQuery,
            );
        }

        if ($alertSearch !== '') {
            $filters[] = $this->alertFilterChip('alert_search', 'Search: '.$alertSearch, $alertQuery);
        }

        return $filters;
    }

    /**
     * @param  array<string, string>  $alertQuery
     * @return array{label: string, href: string}
     */
    private function alertFilterChip(string $queryKey, string $label, array $alertQuery): array
    {
        unset($alertQuery[$queryKey]);

        return [
            'label' => $label,
            'href' => route('dashboard.alerts.index', $alertQuery),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function alertKindLabels(): array
    {
        return [
            'conversation' => 'Conversation alerts',
            'ticket' => 'Ticket alerts',
        ];
    }
}
