@php
    $notificationData = $notification->data;
    $notificationKind = data_get($notificationData, 'kind');
    $isMacroNotification = $notificationKind === 'automation_rule_matched'
        && data_get($notificationData, 'automation_kind') === 'macro';
    $messageCount = max(1, (int) data_get($notificationData, 'message_count', 1));
    $alertStatusLabel = $notification->unread() ? __('alerts.card.status.unread') : __('alerts.card.status.read');
    $alertActionUrl = data_get($notificationData, 'url');
    $storedSubject = data_get($notificationData, 'subject');
    $storedSubjectIsFallback = ($notificationKind === 'conversation_needs_reply'
        && $storedSubject === 'Untitled conversation')
        || ($notificationKind === 'sla_deadline'
            && in_array($storedSubject, ['Untitled conversation', 'Untitled ticket'], true))
        || ($notificationKind === 'automation_rule_matched'
            && in_array($storedSubject, [null, 'Untitled conversation', 'Untitled ticket'], true));
    $subjectIsAuthored = filled($storedSubject) && ! $storedSubjectIsFallback;
    $subjectKind = data_get($notificationData, 'subject_kind');
    $subject = $subjectIsAuthored
        ? $storedSubject
        : (($notificationKind === 'ticket_assigned' || (in_array($notificationKind, ['sla_deadline', 'automation_rule_matched'], true) && $subjectKind === 'ticket'))
            ? __('alerts.card.untitled_ticket')
            : __('alerts.card.untitled_conversation'));
    $siteName = data_get($notificationData, 'site_name');
    $siteNameIsAuthored = filled($siteName);
    $siteFeedback = [
        'key' => 'alerts.card.on_site',
        'parameters' => $siteNameIsAuthored ? ['site' => $siteName] : [],
        'localized_parameters' => $siteNameIsAuthored ? [] : ['site' => __('alerts.card.unknown_site')],
    ];

    if ($notificationKind === 'sla_deadline') {
        $alertActionLabel = $subjectKind === 'ticket' ? __('alerts.card.open_ticket') : __('alerts.card.open_conversation');
        $alertNextMove = data_get($notificationData, 'stage') === 'breach'
            ? __('alerts.card.sla_breach_next')
            : __('alerts.card.sla_warning_next');
        $priority = (string) data_get($notificationData, 'priority', 'normal');
        $priorityFeedback = [
            'key' => 'alerts.card.priority',
            'parameters' => [],
            'localized_parameters' => ['priority' => __('tickets.priorities.'.$priority)],
        ];
    } elseif ($notificationKind === 'ticket_assigned') {
        $alertActionLabel = __('alerts.card.open_ticket');
        $alertNextMove = __('alerts.card.ticket_next');
        $assignedByName = data_get($notificationData, 'assigned_by_name');
        $assignedByIsAuthored = filled($assignedByName);
        $assignedByFeedback = [
            'key' => 'alerts.card.assigned_by',
            'parameters' => $assignedByIsAuthored ? ['name' => $assignedByName] : [],
            'localized_parameters' => $assignedByIsAuthored ? [] : ['name' => __('alerts.card.someone')],
        ];
        $priority = (string) data_get($notificationData, 'priority', 'normal');
        $priorityKey = 'tickets.priorities.'.$priority;
        $priorityLabel = __($priorityKey);
        $priorityIsKnown = $priorityLabel !== $priorityKey;
        $priorityFeedback = [
            'key' => 'alerts.card.priority',
            'parameters' => $priorityIsKnown ? [] : ['priority' => $priority],
            'localized_parameters' => $priorityIsKnown ? ['priority' => $priorityLabel] : [],
        ];
    } elseif ($notificationKind === 'automation_rule_matched') {
        $alertActionLabel = $subjectKind === 'ticket' ? __('alerts.card.open_ticket') : __('alerts.card.open_conversation');
        $alertNextMove = $isMacroNotification ? __('alerts.card.macro_next') : __('alerts.card.automation_next');
        $priority = (string) data_get($notificationData, 'priority', 'normal');
        $priorityKey = 'tickets.priorities.'.$priority;
        $priorityLabel = __($priorityKey);
        $priorityIsKnown = $priorityLabel !== $priorityKey;
        $priorityFeedback = [
            'key' => 'alerts.card.priority',
            'parameters' => $priorityIsKnown ? [] : ['priority' => $priority],
            'localized_parameters' => $priorityIsKnown ? ['priority' => $priorityLabel] : [],
        ];
    } else {
        $alertActionLabel = __('alerts.card.open_conversation');
        $alertNextMove = __('alerts.card.conversation_next');
    }
@endphp

<article class="message">
    <div class="message-meta">
        <strong @if ($subjectIsAuthored) lang="" @endif>{{ $subject }}</strong>
        <span class="message-status-line">
            <span
                class="readiness-status"
                data-status="{{ $notification->unread() ? 'attention' : 'ready' }}"
                aria-label="{{ __('alerts.card.status.aria', ['status' => $alertStatusLabel]) }}"
            >
                {{ $alertStatusLabel }}
            </span>
            <span>
                @if ($notification->read())
                    {{ __('alerts.card.status.read_at', ['elapsed' => $notification->read_at->diffForHumans()]) }}
                    ·
                @endif
                {{ $notification->created_at->diffForHumans() }}
            </span>
        </span>
    </div>
    @if ($notificationKind === 'sla_deadline')
        <p class="lede">{{ data_get($notificationData, 'stage') === 'breach' ? __('alerts.card.sla_breached') : __('alerts.card.sla_warning') }}</p>
        <p class="message-body">{{ __('alerts.card.sla_metric', ['metric' => __('sla.metrics.'.data_get($notificationData, 'metric', 'resolution'))]) }}</p>
        <p class="field-help">
            <strong>{{ __('alerts.card.why') }}</strong>
            {{ data_get($notificationData, 'stage') === 'breach' ? __('alerts.card.sla_breach_why') : __('alerts.card.sla_warning_why') }}
        </p>
        <p class="field-help"><strong>{{ __('alerts.card.next_move') }}</strong> {{ $alertNextMove }}</p>
        <p class="lede">
            <a class="text-link" href="{{ data_get($notificationData, 'url') }}">
                {{ $subjectKind === 'ticket' ? __('alerts.card.ticket_reference', ['id' => data_get($notificationData, 'ticket_id')]) : data_get($notificationData, 'support_code') }}
            </a>
            <x-translated-feedback :feedback="$siteFeedback" />
            · <x-translated-feedback :feedback="$priorityFeedback" />
        </p>
    @elseif ($notificationKind === 'ticket_assigned')
        <p class="lede">{{ __('alerts.card.ticket_assigned') }}</p>
        <p class="message-body"><x-translated-feedback :feedback="$assignedByFeedback" /></p>
        <p class="field-help">
            <strong>{{ __('alerts.card.why') }}</strong>
            {{ __('alerts.card.ticket_why') }}
        </p>
        <p class="field-help">
            <strong>{{ __('alerts.card.next_move') }}</strong>
            {{ $alertNextMove }}
        </p>
        <p class="lede">
            <a class="text-link" href="{{ data_get($notificationData, 'url') }}">
                {{ __('alerts.card.ticket_reference', ['id' => data_get($notificationData, 'ticket_id')]) }}
            </a>
            <x-translated-feedback :feedback="$siteFeedback" />
            · <x-translated-feedback :feedback="$priorityFeedback" />
        </p>
    @elseif ($notificationKind === 'automation_rule_matched')
        <p class="lede">{{ $isMacroNotification ? __('alerts.card.macro_applied') : __('alerts.card.automation_matched') }}</p>
        <p class="message-body">
            <strong>{{ $isMacroNotification ? __('alerts.card.automation_macro') : __('alerts.card.automation_rule') }}</strong>
            <span lang="">{{ data_get($notificationData, 'rule_name') }}</span>
        </p>
        <p class="field-help">
            <strong>{{ __('alerts.card.why') }}</strong>
            {{ $isMacroNotification ? __('alerts.card.macro_why') : __('alerts.card.automation_why') }}
        </p>
        <p class="field-help">
            <strong>{{ __('alerts.card.next_move') }}</strong>
            {{ $alertNextMove }}
        </p>
        <p class="lede">
            <a class="text-link" @if ($subjectKind !== 'ticket') lang="" @endif href="{{ data_get($notificationData, 'url') }}">
                {{ $subjectKind === 'ticket'
                    ? __('alerts.card.ticket_reference', ['id' => data_get($notificationData, 'ticket_id')])
                    : data_get($notificationData, 'support_code') }}
            </a>
            <x-translated-feedback :feedback="$siteFeedback" />
            · <x-translated-feedback :feedback="$priorityFeedback" />
        </p>
    @else
        <p class="lede">
            {{ trans_choice('alerts.counts.new_messages', $messageCount, ['count' => $messageCount]) }}
        </p>
        @if (filled(data_get($notificationData, 'message_preview')))
            <p class="message-body" lang="">{{ data_get($notificationData, 'message_preview') }}</p>
        @endif
        <p class="field-help">
            <strong>{{ __('alerts.card.why') }}</strong>
            {{ __('alerts.card.conversation_why') }}
        </p>
        <p class="field-help">
            <strong>{{ __('alerts.card.next_move') }}</strong>
            {{ $alertNextMove }}
        </p>
        <p class="lede">
            <a class="text-link" @if (filled(data_get($notificationData, 'support_code'))) lang="" @endif href="{{ data_get($notificationData, 'url') }}">
                {{ data_get($notificationData, 'support_code') }}
            </a>
            <x-translated-feedback :feedback="$siteFeedback" />
        </p>
    @endif

    <div class="section-actions">
        @if ($alertActionUrl)
            <a class="button" href="{{ $alertActionUrl }}">{{ $alertActionLabel }}</a>
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
                <button class="button secondary" type="submit">{{ __('alerts.card.mark_read') }}</button>
            </form>
        @else
            <p class="lede">{{ __('alerts.card.already_read') }}</p>
        @endif
    </div>
</article>
