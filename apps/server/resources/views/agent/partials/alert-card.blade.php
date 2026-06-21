@php
    $notificationData = $notification->data;
    $notificationKind = data_get($notificationData, 'kind');
    $messageCount = max(1, (int) data_get($notificationData, 'message_count', 1));
@endphp

<article class="message">
    <div class="message-meta">
        <strong>{{ data_get($notificationData, 'subject', $notificationKind === 'ticket_assigned' ? 'Untitled ticket' : 'Untitled conversation') }}</strong>
        <span>
            @if ($notification->read())
                Read {{ $notification->read_at->diffForHumans() }}
                ·
            @endif
            {{ $notification->created_at->diffForHumans() }}
        </span>
    </div>
    @if ($notificationKind === 'ticket_assigned')
        <p class="lede">Ticket assigned</p>
        <p class="message-body">{{ data_get($notificationData, 'assigned_by_name', 'Someone') }} assigned this ticket to you.</p>
        <p class="lede">
            <a class="text-link" href="{{ data_get($notificationData, 'url') }}">
                Ticket #{{ data_get($notificationData, 'ticket_id') }}
            </a>
            on {{ data_get($notificationData, 'site_name', 'Unknown site') }}
            · {{ ucfirst((string) data_get($notificationData, 'priority', 'normal')) }} priority
        </p>
    @else
        <p class="lede">
            {{ $messageCount === 1 ? '1 new message' : $messageCount.' new messages' }}
        </p>
        <p class="message-body">{{ data_get($notificationData, 'message_preview') }}</p>
        <p class="lede">
            <a class="text-link" href="{{ data_get($notificationData, 'url') }}">
                {{ data_get($notificationData, 'support_code') }}
            </a>
            on {{ data_get($notificationData, 'site_name', 'Unknown site') }}
        </p>
    @endif

    @if ($notification->unread())
        <form method="POST" action="{{ route('dashboard.alerts.read', $notification) }}">
            @csrf
            @isset($alertReturnTo)
                <input type="hidden" name="return_to" value="{{ $alertReturnTo }}">
            @endisset
            <button class="button secondary" type="submit">Mark read</button>
        </form>
    @else
        <p class="lede">Already read.</p>
    @endif
</article>
