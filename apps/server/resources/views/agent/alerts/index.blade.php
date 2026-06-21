<x-layouts.app title="Alerts" :agent="$agent" :account="$account">
    <p><a class="text-link" href="{{ route('dashboard') }}">Back to dashboard</a></p>
    <h1>Alert center</h1>
    <p class="lede">Visible support alerts for {{ $account->name }}.</p>

    <section class="section" aria-labelledby="alert-center-heading">
        <div class="section-header">
            <div>
                <h2 id="alert-center-heading">Recent alerts</h2>
                <p class="lede">Unread alerts stay here until the related work is opened or marked read.</p>
            </div>
            <div class="section-actions">
                @foreach (['all' => 'All alerts', 'unread' => 'Unread only'] as $filterValue => $filterLabel)
                    @php
                        $filterParams = [];

                        if ($filterValue === 'unread') {
                            $filterParams['alert_filter'] = 'unread';
                        }
                    @endphp
                    <a
                        class="button {{ $alertFilter === $filterValue ? '' : 'secondary' }}"
                        href="{{ route('dashboard.alerts.index', $filterParams) }}"
                        @if ($alertFilter === $filterValue) aria-current="page" @endif
                    >
                        {{ $filterLabel }}
                    </a>
                @endforeach
                <span class="lede">{{ $notificationCount }} visible</span>
                <span class="lede">{{ $unreadNotificationCount }} unread</span>
                @if ($unreadNotificationCount > 0)
                    <form method="POST" action="{{ route('dashboard.alerts.read-all') }}">
                        @csrf
                        <input type="hidden" name="return_to" value="alerts">
                        @if ($alertFilter === 'unread')
                            <input type="hidden" name="alert_filter" value="unread">
                        @endif
                        <button class="button secondary" type="submit">Mark all read</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="meta-grid" aria-label="Alert snapshot">
            @foreach ($alertSnapshot as $snapshotItem)
                <div class="meta-item">
                    <span class="meta-label">{{ $snapshotItem['label'] }}</span>
                    <span class="meta-value">{{ $snapshotItem['value'] }}</span>
                    <p class="field-help">{{ $snapshotItem['detail'] }}</p>
                </div>
            @endforeach
        </div>

        @if ($notifications->isEmpty())
            <p class="empty">{{ $alertFilter === 'unread' ? 'No unread visible alerts.' : 'No visible alerts yet.' }}</p>
        @else
            <div class="notice-copy notice-copy-bordered">
                @if ($alertFilter === 'unread')
                    <p><strong>Showing unread visible alerts.</strong></p>
                @else
                    <p><strong>Showing the latest {{ $notificationCount }} visible {{ \Illuminate\Support\Str::plural('alert', $notificationCount) }}.</strong></p>
                @endif
                <p>Alerts you can no longer access are hidden so old notifications do not leak restricted support work.</p>
            </div>

            <div class="message-list">
                @foreach ($notifications as $notification)
                    @include('agent.partials.alert-card', [
                        'notification' => $notification,
                        'alertFilter' => $alertFilter,
                        'alertReturnTo' => 'alerts',
                    ])
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
