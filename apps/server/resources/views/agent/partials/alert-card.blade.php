@php
    $notificationData = $notification->data;
    $notificationKind = data_get($notificationData, 'kind');
    $messageCount = max(1, (int) data_get($notificationData, 'message_count', 1));
    $alertStatusLabel = $notification->unread() ? 'Unread' : 'Read';
@endphp

<article class="message">
    <div class="message-meta">
        <strong>{{ data_get($notificationData, 'subject', $notificationKind === 'ticket_assigned' ? 'Untitled ticket' : 'Untitled conversation') }}</strong>
        <span class="message-status-line">
            <span
                class="readiness-status"
                data-status="{{ $notification->unread() ? 'attention' : 'ready' }}"
                aria-label="Alert status: {{ $alertStatusLabel }}"
            >
                {{ $alertStatusLabel }}
            </span>
            <span>
                @if ($notification->read())
                    Read {{ $notification->read_at->diffForHumans() }}
                    ·
                @endif
                {{ $notification->created_at->diffForHumans() }}
            </span>
        </span>
    </div>
    @if ($notificationKind === 'ticket_assigned')
        <p class="lede">Ticket assigned</p>
        <p class="message-body">{{ data_get($notificationData, 'assigned_by_name', 'Someone') }} assigned this ticket to you.</p>
        <p class="field-help">
            <strong>Why this alert:</strong>
            This ticket was assigned to you. Open the ticket or mark this alert read once triaged.
        </p>
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
        <p class="field-help">
            <strong>Why this alert:</strong>
            Visitor reply is waiting on a conversation you can support. Open the conversation or mark this alert read once handled.
        </p>
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
            @if (($alertFilter ?? null) === 'unread')
                <input type="hidden" name="alert_filter" value="unread">
            @endif
            @if (($alertKind ?? 'all') !== 'all')
                <input type="hidden" name="alert_kind" value="{{ $alertKind }}">
            @endif
            @if (($alertSearch ?? '') !== '')
                <input type="hidden" name="alert_search" value="{{ $alertSearch }}">
            @endif
            <button class="button secondary" type="submit">Mark read</button>
        </form>
    @else
        <p class="lede">Already read.</p>
    @endif
</article>
