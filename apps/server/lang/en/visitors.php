<?php

/*
 * The visitor directory and profile.
 *
 * Visitor names, identifiers, URLs, support subjects, agent names, and host
 * context are account or visitor content. The views mark those values with an
 * unknown language instead of sending them through this catalogue.
 *
 * Counts are whole plural messages rather than English fragments. Callers pass
 * both the raw count to `trans_choice` and a ReaderNumber-formatted `:count`.
 */
return [
    'document_title' => 'Visitors',
    'title' => 'Visitors',
    'subtitle' => [
        'browsers' => 'Everyone this desk has seen, whether or not they got in touch, most recently seen first.',
        'contacts' => 'Everyone this desk has heard from, most recently seen first.',
    ],

    'filters' => [
        'heading' => 'Search',
        'hint' => 'By name, email, or the identifier your site gave them.',
        'search' => 'Search',
        'placeholder' => 'Name, email, or ID',
        'site' => 'Site',
        'any_site' => 'Any site',
        'last_seen' => 'Last seen',
        'any_time' => 'Any time',
        'submit' => 'Search visitors',
        'clear' => 'Clear',
    ],

    'export' => [
        'csv' => 'Export CSV',
        'boundary_heading' => 'Export boundary',
        'boundary_lede' => 'Filtered and site-scoped',
        'boundary_fields' => 'Exports include contact identity, timestamps, and defined attribute values. Raw host context, contact notes, conversations, tickets, and browser-ID history are intentionally omitted.',
        'boundary_scope' => 'The file includes at most the :count most recent visitors matching the current filters on sites you are allowed to support.',
    ],

    'list' => [
        'heading' => 'Visitors',
        'columns' => [
            'visitor' => 'Visitor',
            'site' => 'Site',
            'last_seen' => 'Last seen',
            'conversations' => 'Conversations',
        ],
        'unknown_site' => 'Unknown site',
    ],

    'empty' => [
        'browsers' => 'No visitors match this search. On the sites shown here Wayfindr records somebody when they load a page, so this also lists people who were only browsing.',
        'contacts' => 'No visitors match this search. Wayfindr records somebody when they open the chat, not when they load a page, so this lists people who reached out.',
    ],

    'presence' => [
        'seen_recently' => 'Seen in the last 2 minutes',
        'seen_at' => 'Seen :elapsed',
        'no_heartbeat' => 'No visitor heartbeat yet.',
    ],

    'counts' => [
        'visitors' => '{1} :count visitor|[2,*] :count visitors',
        'conversations' => '{1} :count conversation|[2,*] :count conversations',
        'tickets' => '{1} :count ticket|[2,*] :count tickets',
        'active_conversations' => '{1} :count active conversation|[2,*] :count active conversations',
        'active_tickets' => '{1} :count active ticket|[2,*] :count active tickets',
        'fields' => '{1} :count field|[2,*] :count fields',
        'shown_conversations' => '{1} :count shown|[2,*] :count shown',
        'shown_tickets' => '{1} :count shown|[2,*] :count shown',
    ],

    'common' => [
        'not_provided' => 'Not provided',
        'not_reported' => 'Not reported',
    ],

    'profile' => [
        'document_title' => 'Visitor profile',
        'title' => 'Visitor profile',
        'back' => 'Back to visitors',
        'glance' => [
            'heading' => 'Visitor at a glance',
            'safe_only' => 'Safe context only',
            'visitor' => 'Visitor',
            'host_visitor_id' => 'Host visitor ID',
            'last_seen' => 'Last seen',
            'latest_page' => 'Latest page',
            'entry_page' => 'First captured entry page',
            'support_history' => 'Support history',
        ],
    ],

    'snapshot' => [
        'heading' => 'Support snapshot',
        'conversations' => 'Conversations',
        'tickets' => 'Tickets',
        'next_step' => 'Next step',
        'agent_cue' => 'Agent cue',
        'status' => [
            'needs_reply' => 'Needs reply',
            'review_context' => 'Review context',
            'waiting' => 'Waiting',
            'in_progress' => 'In progress',
            'clear' => 'Clear',
        ],
        'reply' => [
            'body' => 'Visitor replied last. Open the latest support item before scanning older history.',
            'cta' => 'Reply to visitor',
            'title' => 'Reply to visitor',
        ],
        'empty_conversation' => [
            'body' => 'No messages have landed yet. Use the current visitor context to decide whether to greet, wait, or create a ticket.',
            'cta' => 'Review context',
            'title' => 'Start the conversation',
        ],
        'waiting_conversation' => [
            'body' => 'No visitor reply is waiting right now. Keep the thread visible and respond when the visitor comes back.',
            'cta' => 'Review conversation',
            'title' => 'Waiting on visitor',
        ],
        'waiting_ticket' => [
            'body' => 'No visitor reply is waiting right now. Review the active ticket when follow-up is due.',
            'cta' => 'Review ticket',
            'title' => 'Ticket in progress',
        ],
        'clear' => [
            'body' => 'No active support work is attached to this visitor.',
            'title' => 'No active work',
        ],
    ],

    'references' => [
        'heading' => 'Support references',
        'lede' => 'Stable anchors for search, handoff, and follow-up.',
        'visitor' => 'Visitor lookup reference',
        'latest_support_code' => 'Latest support code',
        'latest_ticket' => 'Latest ticket',
        'ticket' => 'Ticket #:id',
        'no_conversations' => 'No conversations yet',
        'no_tickets' => 'No tickets yet',
    ],

    'boundary' => [
        'heading' => 'Data boundary',
        'body' => 'Use this page to understand the support trail. Do not collect, export, or infer extra visitor data without consent.',
    ],

    'context' => [
        'heading' => 'Host context',
        'field' => 'Field',
        'value' => 'Value',
        'empty_heading' => 'No host-provided context yet.',
        'empty_body' => 'Wayfindr only has the anonymous visitor reference until the host site supplies safe customer or account context.',
    ],

    'history' => [
        'heading' => 'Support history',
        'conversations' => 'Conversations',
        'tickets' => 'Tickets',
        'no_conversations_heading' => 'No conversations for this visitor yet.',
        'no_conversations_body' => 'New conversations will appear here once this visitor starts a support thread on this site.',
        'no_tickets_heading' => 'No tickets for this visitor yet.',
        'no_tickets_body' => 'Create a ticket from a conversation when the next step needs durable follow-up.',
        'untitled_conversation' => 'Untitled conversation',
        'owner' => 'Owner',
        'unassigned' => 'Unassigned',
        'last_activity' => 'Last activity: :elapsed',
        'support_code' => 'Support code',
        'updated' => 'Updated: :elapsed',
    ],
];
