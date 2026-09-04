<?php

return [
    'document_title' => 'Alerts',
    'title' => 'Alert center',
    'subtitle' => 'Visible support alerts for :account.',

    'center' => [
        'heading' => 'Recent alerts',
        'lede' => 'Unread alerts stay here until the related work is opened or marked read.',
        'lanes' => 'Alert lanes',
        'all' => 'All alerts',
        'unread_only' => 'Unread only',
        'bulk_matching' => 'Mark matching read',
        'bulk_unread' => 'Mark unread alerts read',
        'bulk_matching_help' => 'All unread alerts matching this view, including alerts outside the current display, will be marked read.',
        'bulk_unread_help' => 'All unread alerts you can still access, including alerts outside the current display, will be marked read.',
        'privacy' => 'Alerts you can no longer access are hidden so old notifications do not leak restricted support work.',
    ],

    'delivery' => [
        'heading' => 'Alert delivery context',
        'region' => 'Personal alert delivery context',
        'source_detail' => 'Dashboard alerts remain the source of truth for support work that needs attention.',
        'change_preferences' => 'Change alert preferences',
        'mode' => [
            'label' => 'Current mode',
            'assigned_detail' => 'Only assigned conversations and tickets create new alerts for you.',
            'quiet_detail' => 'Quiet mode pauses new alerts without changing existing visible alerts.',
            'all_detail' => 'Eligible support work from sites you can support can create alerts.',
        ],
        'email' => [
            'label' => 'Email delivery',
            'off' => 'Email off',
            'digest' => 'Digest preferred',
            'unattended' => 'Unattended only',
            'immediate' => 'Immediate email',
            'off_detail' => 'Email alerts are off for your profile. The alert center remains available here.',
            'digest_detail' => 'Digest delivery is preferred when the scheduler runs. Dashboard alerts still appear here immediately.',
            'unattended_detail' => '{1} Email goes out only when a visitor message stays unseen for :count minute. Dashboard alerts still appear here immediately.|[2,*] Email goes out only when a visitor message stays unseen for :count minutes. Dashboard alerts still appear here immediately.',
            'immediate_detail' => 'Immediate email delivery is enabled when mail is configured. Dashboard alerts still appear here immediately.',
        ],
    ],

    'filters' => [
        'region' => 'Filter alerts',
        'search_label' => 'Search alerts',
        'search_placeholder' => 'Support code, ticket #, subject, site, or visitor',
        'search_help' => 'Search visible alert context only; restricted support work stays hidden.',
        'kind_label' => 'Alert type',
        'apply' => 'Apply',
        'clear' => 'Clear filters',
        'active_region' => 'Active alert filters',
        'active_heading' => 'Active alert filters',
        'active_detail' => 'Alerts narrowed to the support work matching this view.',
    ],

    'kinds' => [
        'all' => 'All alerts',
        'conversation' => 'Conversation alerts',
        'ticket' => 'Ticket alerts',
        'sla' => 'SLA alerts',
    ],

    'focus' => [
        'region' => 'Alert center focus',
        'heading' => 'Alert focus',
        'detail' => 'What this alert center is showing before you triage items.',
        'view' => 'View',
        'type' => 'Type',
        'visible' => 'Visible',
        'unread' => 'Unread',
        'search' => 'Search',
    ],

    'chips' => [
        'type' => 'Type: :value',
        'search' => 'Search: :value',
    ],

    'counts' => [
        'visible' => '{1} :count visible|[2,*] :count visible',
        'unread' => '{1} :count unread|[2,*] :count unread',
        'conversations' => '{1} :count conversation|[2,*] :count conversations',
        'tickets' => '{1} :count ticket|[2,*] :count tickets',
        'sla' => '{1} :count SLA alert|[2,*] :count SLA alerts',
        'new_messages' => '{1} 1 new message|[2,*] :count new messages',
    ],

    'snapshot' => [
        'region' => 'Alert snapshot',
        'visible' => [
            'label' => 'Visible alerts',
            'present' => 'Current alerts you can still open.',
            'empty' => 'Nothing currently needs attention in this alert view.',
        ],
        'unread' => [
            'label' => 'Unread alerts',
            'present' => 'Still waiting for review or mark-read.',
            'empty' => 'No unread alerts are waiting for review.',
        ],
        'conversations' => [
            'label' => 'Conversation alerts',
            'present' => 'Visitor replies and chat follow-up.',
            'empty' => 'No visitor reply alerts in this view.',
        ],
        'tickets' => [
            'label' => 'Ticket alerts',
            'present' => 'Ticket assignments and durable work.',
            'empty' => 'No ticket assignment alerts in this view.',
        ],
        'sla' => [
            'label' => 'SLA alerts',
            'present' => 'Deadlines approaching or already breached.',
            'empty' => 'No SLA deadlines need attention in this view.',
        ],
    ],

    'summary' => [
        'unread_heading' => 'Showing unread visible alerts.',
        'latest' => '{1} Showing the latest 1 visible alert.|[2,*] Showing the latest :count visible alerts.',
        'matching_heading' => [
            'all' => '{1} Showing 1 matching alert.|[2,*] Showing :count matching alerts.',
            'unread' => '{1} Showing 1 matching unread alert.|[2,*] Showing :count matching unread alerts.',
            'conversation' => '{1} Showing 1 matching conversation alert.|[2,*] Showing :count matching conversation alerts.',
            'ticket' => '{1} Showing 1 matching ticket alert.|[2,*] Showing :count matching ticket alerts.',
            'sla' => '{1} Showing 1 matching SLA alert.|[2,*] Showing :count matching SLA alerts.',
        ],
        'capped_heading' => [
            'all' => '{1} :shown shown of 1 matching alert.|[2,*] :shown shown of :count matching alerts.',
            'unread' => '{1} :shown shown of 1 matching unread alert.|[2,*] :shown shown of :count matching unread alerts.',
            'conversation' => '{1} :shown shown of 1 matching conversation alert.|[2,*] :shown shown of :count matching conversation alerts.',
            'ticket' => '{1} :shown shown of 1 matching ticket alert.|[2,*] :shown shown of :count matching ticket alerts.',
            'sla' => '{1} :shown shown of 1 matching SLA alert.|[2,*] :shown shown of :count matching SLA alerts.',
        ],
        'capped_detail' => [
            'all' => '{1} Showing :shown alerts after the current display cap. 1 alert matches this view.|[2,*] Showing :shown alerts after the current display cap. :count alerts match this view.',
            'unread' => '{1} Showing :shown alerts after the current display cap. 1 unread alert matches this view.|[2,*] Showing :shown alerts after the current display cap. :count unread alerts match this view.',
            'conversation' => '{1} Showing :shown alerts after the current display cap. 1 conversation alert matches this view.|[2,*] Showing :shown alerts after the current display cap. :count conversation alerts match this view.',
            'ticket' => '{1} Showing :shown alerts after the current display cap. 1 ticket alert matches this view.|[2,*] Showing :shown alerts after the current display cap. :count ticket alerts match this view.',
            'sla' => '{1} Showing :shown alerts after the current display cap. 1 SLA alert matches this view.|[2,*] Showing :shown alerts after the current display cap. :count SLA alerts match this view.',
        ],
    ],

    'empty' => [
        'search' => [
            'heading' => 'No alerts match ":search".',
            'detail' => 'Search checks support codes, ticket numbers, subjects, sites, visitors, and message previews you can still access.',
        ],
        'kind' => [
            'conversation' => 'No conversation alerts match this view.',
            'ticket' => 'No ticket alerts match this view.',
            'sla' => 'No SLA alerts match this view.',
            'detail' => 'Try all alert types to include the other support signals you can still access.',
        ],
        'unread' => [
            'heading' => 'You are caught up.',
            'detail' => 'New eligible visitor replies and ticket assignments will appear here when they need attention.',
        ],
        'all' => [
            'heading' => 'No visible alerts yet.',
            'detail' => 'Visitor replies and ticket assignments you can support will appear here once they need attention.',
        ],
    ],

    'actions' => [
        'clear_search' => 'Clear search',
        'clear_all_filters' => 'Clear all alert filters',
        'clear_type' => 'Clear alert type filter',
        'show_recent' => 'Show recent alerts',
        'back_to_dashboard' => 'Back to dashboard',
        'review_preferences' => 'Review alert preferences',
    ],

    'card' => [
        'status' => [
            'unread' => 'Unread',
            'read' => 'Read',
            'aria' => 'Alert status: :status',
            'read_at' => 'Read :elapsed',
        ],
        'untitled_ticket' => 'Untitled ticket',
        'untitled_conversation' => 'Untitled conversation',
        'ticket_assigned' => 'Ticket assigned',
        'automation_matched' => 'Automation matched this support work',
        'automation_rule' => 'Rule:',
        'macro_applied' => 'A macro notified you about this support work',
        'automation_macro' => 'Macro:',
        'sla_warning' => 'SLA deadline approaching',
        'sla_breached' => 'SLA deadline breached',
        'sla_metric' => 'Target: :metric',
        'sla_warning_why' => 'This work has used most of its business-hours target.',
        'sla_breach_why' => 'This work has passed its business-hours target.',
        'sla_warning_next' => 'Open the work now and decide who will move it forward before the target expires.',
        'sla_breach_next' => 'Open the work, take ownership, and restore a clear next step.',
        'assigned_by' => ':name assigned this ticket to you.',
        'someone' => 'Someone',
        'why' => 'Why this alert:',
        'next_move' => 'Next move:',
        'ticket_why' => 'This ticket was assigned to you. Open the ticket or mark this alert read once triaged.',
        'ticket_next' => 'Open the assigned ticket and decide the owner, priority, or next status.',
        'automation_why' => 'A configured rule explicitly asked Wayfindr to notify you about this work.',
        'automation_next' => 'Open the work, review the automated changes, and take the next appropriate step.',
        'macro_why' => 'An agent applied a configured macro that explicitly asked Wayfindr to notify you.',
        'macro_next' => 'Open the work, review the macro changes, and take the next appropriate step.',
        'conversation_why' => 'Visitor reply is waiting on a conversation you can support. Open the conversation or mark this alert read once handled.',
        'conversation_next' => 'Open the conversation and reply while the visitor is waiting.',
        'ticket_reference' => 'Ticket #:id',
        'on_site' => 'on :site',
        'priority' => ':priority priority',
        'unknown_site' => 'Unknown site',
        'open_ticket' => 'Open ticket',
        'open_conversation' => 'Open conversation',
        'mark_read' => 'Mark read',
        'already_read' => 'Already read.',
    ],
];
