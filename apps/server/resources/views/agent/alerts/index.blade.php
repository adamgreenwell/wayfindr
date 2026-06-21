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
                <span class="lede">{{ $notificationCount }} visible</span>
                <span class="lede">{{ $unreadNotificationCount }} unread</span>
                @if ($unreadNotificationCount > 0)
                    <form method="POST" action="{{ route('dashboard.alerts.read-all') }}">
                        @csrf
                        <input type="hidden" name="return_to" value="alerts">
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
            <p class="empty">No visible alerts yet.</p>
        @else
            <div class="notice-copy notice-copy-bordered">
                <p><strong>Showing the latest {{ $notificationCount }} visible {{ \Illuminate\Support\Str::plural('alert', $notificationCount) }}.</strong></p>
                <p>Alerts you can no longer access are hidden so old notifications do not leak restricted support work.</p>
            </div>

            <div class="message-list">
                @foreach ($notifications as $notification)
                    @include('agent.partials.alert-card', [
                        'notification' => $notification,
                        'alertReturnTo' => 'alerts',
                    ])
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
