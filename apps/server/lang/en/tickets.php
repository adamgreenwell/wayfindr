<?php

/*
 * The ticket queue.
 *
 * Same two rules as the conversation queue: whole sentences carry placeholders
 * rather than being assembled from fragments, and every sentence interpolating
 * a count is a `trans_choice` on that count -- verb included. This surface had
 * a `ticketCountVerb()` choosing "matches" or "match" separately from the
 * number it agreed with, which is the shape that reads correctly in English by
 * luck and fails in German.
 *
 * `categories` and `priorities` are keyed by the values stored on the row, and
 * are the display names only. Their descriptions and guidance belong to the
 * ticket forms and extract with that surface; `TicketCategory::label()` still
 * answers English for the pages that have not been extracted.
 */
return [
    'document_title' => 'Tickets',
    'title' => 'Ticket queue',
    'subtitle' => 'Structured support work for :account.',

    'search' => [
        'label' => 'Search',
        'placeholder' => 'Ticket #123, support code, subject, requester',
        'hint' => 'Search by ticket number, subject, description, support code, requester, email, or anonymous visitor ID.',
        'submit' => 'Apply filters',
    ],

    'columns' => [
        'subject' => 'Subject',
        'status' => 'Status',
        'priority' => 'Priority',
        'category' => 'Category',
        'labels' => 'Labels',
        'assignee' => 'Assignee',
        'next_step' => 'Next step',
        'external_issue' => 'External issue',
        'latest_activity' => 'Latest activity',
        'timing' => 'Timing',
        'site' => 'Site',
        'label' => 'Label',
    ],

    'regions' => [
        'filters' => 'Active ticket filters',
        'lanes' => 'Ticket lanes',
        'next_steps' => 'Ticket next steps',
    ],

    'filters' => [
        'assignee' => [
            'all' => 'Any assignee',
            'assigned_to_me' => 'Assigned to me',
            'unassigned' => 'Unassigned',
        ],
        'status' => [
            'open' => 'All open',
            'pending' => 'Pending',
            'closed' => 'Closed',
            'all' => 'All tickets',
        ],
        'priority_any' => 'Any priority',
        'category_any' => 'Any category',
        'category_uncategorized' => 'Uncategorized',
        'label_any' => 'Any label',
        'site_any' => 'Any site',
        'attention' => [
            'all' => 'Any next step',
            'escalated' => 'Recently escalated',
            'needs_reply' => 'Needs reply',
            'needs_owner' => 'Needs owner',
            'needs_agent' => 'Needs agent',
            'waiting_on_customer' => 'Waiting on customer',
            'resolved' => 'Resolved',
        ],
        'external' => [
            'all' => 'Any external issue',
            'failed' => 'Needs attention',
            'pending' => 'Sync pending',
            'linked' => 'Linked',
            'none' => 'No external issue',
        ],
    ],

    'categories' => [
        'question' => 'Question',
        'bug' => 'Bug',
        'billing' => 'Billing',
        'access' => 'Access',
        'task' => 'Task',
        'other' => 'Other',
    ],

    'statuses' => [
        'open' => 'Open',
        'pending' => 'Pending',
        'closed' => 'Closed',
    ],

    'statuses' => [
        'open' => 'Open',
        'pending' => 'Pending',
        'closed' => 'Closed',
    ],

    'priorities' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'chips' => [
        'status' => 'Status: :value',
        'assignee' => 'Assignee: :value',
        'site' => 'Site: :value',
        'priority' => 'Priority: :value',
        'category' => 'Category: :value',
        'label' => 'Label: :value',
        'next_step' => 'Next step: :value',
        'external' => 'External issue: :value',
        'search' => 'Search: :value',
    ],

    'actions' => [
        'clear_filters' => 'Clear filters',
        'clear_all' => 'Clear all ticket filters',
        'clear_search' => 'Clear search',
        'clear_next_step' => 'Clear next-step filter',
        'clear_external' => 'Clear external issue filter',
        'open_conversations' => 'Open conversations',
        'show_all' => 'Show all tickets',
    ],

    'counts' => [
        'tickets' => '{1} 1 ticket|[2,*] :count tickets',
        'matches' => '{1} 1 ticket matches|[2,*] :count tickets match',
        'matching_tickets' => '{1} 1 matching ticket|[2,*] :count matching tickets',
    ],

    'summary' => [
        'lane_narrowed_heading' => ':shown shown of :matching',
        'heading' => [
            'open' => '{1} :count open|[2,*] :count open',
            'pending' => '{1} :count pending|[2,*] :count pending',
            'closed' => '{1} :count closed|[2,*] :count closed',
            'total' => '{1} :count total|[2,*] :count total',
        ],
        'lane_narrowed_heading' => ':shown shown of :matching',
        'heading' => [
            'open' => '{1} :count open|[2,*] :count open',
            'pending' => '{1} :count pending|[2,*] :count pending',
            'closed' => '{1} :count closed|[2,*] :count closed',
            'total' => '{1} :count total|[2,*] :count total',
        ],
        'filtered_detail' => '{1} Showing :shown matching the current queue filters.|[2,*] Showing :shown matching the current queue filters.',
        'lane_narrowed_detail' => '{1} Showing :shown after the :lane next-step filter. :matching the other queue filters.|[2,*] Showing :shown after the :lane next-step filter. :matching the other queue filters.',
    ],

    'empty' => [
        'no_match_filters' => 'No tickets match those filters.',
        'none_yet' => 'No tickets yet.',
        'no_pending' => 'No pending tickets yet.',
        'no_closed' => 'No closed tickets yet.',
        'no_open' => 'No open tickets yet.',
        'first_run_detail' => 'Tickets are created from conversations: open a thread and turn it into a durable ticket from its Ticket tab.',
        'waiting_detail' => 'When visitors need durable follow-up, tickets will land here.',
        'search_detail' => 'Search covers ticket number, subject, description, support code, requester, email, and anonymous visitor IDs.',
        'search_heading' => 'No tickets match ":term".',
        'next_step_detail' => 'Try another next-step queue or clear the next-step filter.',
        'next_step_heading' => 'No tickets need :phrase right now.',
        'external_detail' => 'Try another external issue state or clear the external issue filter.',
        'external_heading' => 'No tickets match that external issue state.',
        'refine_detail' => 'Try clearing a filter, widening the status, or searching by support code if you have one.',
    ],

    'attention_phrase' => [
        'escalated' => 'recent escalation review',
        'needs_reply' => 'a visitor reply',
        'needs_owner' => 'an owner',
        'needs_agent' => 'an agent update',
        'waiting_on_customer' => 'customer follow-up',
        'resolved' => 'resolution review',
        'default' => 'that next step',
    ],

    'external_state' => [
        'failed' => 'Open the ticket to review safe retry options.',
        'pending' => 'Waiting for external tracker confirmation.',
        'linked' => 'External tracker reference is attached.',
        'none' => 'Wayfindr is the only tracker for this ticket.',
    ],

    'lifecycle' => [
        'pending' => 'Ticket marked pending',
        'closed' => 'Ticket closed',
        'reopened' => 'Ticket reopened',
        'unheld' => 'Ticket taken off hold',
        'escalated' => 'Ticket escalated',
        'default' => 'Lifecycle update',
    ],

    'read_state' => [
        'seen' => 'Visitor saw reply',
        'unseen' => 'Not seen yet',
        'none' => 'No agent reply yet',
        'detail_none' => 'No agent reply has been sent.',
        'detail_seen' => 'Seen :elapsed',
        'detail_unseen' => 'Latest agent reply has not been seen.',
    ],

    'external_attempt' => [
        'none_label' => 'No external attempt yet',
        'none_body' => 'Create or link an external issue when this ticket needs work in another tracker.',
        'failed_label' => ':provider sync failed',
        'failed_body' => ':project needs attention. Provider details withheld.',
        'pending_label' => ':provider sync pending',
        'pending_body' => ':project is waiting for provider confirmation.',
        'linked_label' => ':provider link active',
        'linked_body' => ':project is linked to :reference.',
        'linked_body_bare' => ':project is linked.',
        'removed_label' => ':provider link removed',
        'removed_body' => ':project is no longer linked to :reference.',
        'removed_body_bare' => ':project external link was removed.',
        'created_label' => ':provider issue created',
        'created_body' => ':project is linked to :reference.',
        'created_body_bare' => ':project was created in the external tracker.',
        'project_unknown' => 'Project not recorded',
    ],

    'row' => [
        'attention_needs_reply' => 'Needs reply',
        'attention_needs_owner' => 'Needs owner',
        'attention_waiting_on_customer' => 'Waiting on customer',
        'attention_resolved' => 'Resolved',
        'attention_needs_agent' => 'Needs agent',
        'description_needs_reply' => 'Visitor replied last.',
        'description_needs_owner' => 'Assign this ticket to keep it moving.',
        'description_resolved' => 'Ticket is closed.',
        'description_needs_agent' => 'Ready for an agent update.',
        'description_waiting_marked_pending' => 'Marked pending.',
        'description_waiting_agent_replied' => 'Agent replied last.',
        'preview_visitor' => 'Visitor message',
        'preview_agent' => 'Agent reply',
        'preview_message' => 'Latest message',
        'preview_no_text' => 'Message has no text preview.',
        'opened' => 'Opened :elapsed',
        'closed' => 'Closed :elapsed',
        'waiting_on_owner' => 'Waiting on owner for :elapsed',
        'waiting_on_reply' => 'Waiting on reply for :elapsed',
        'waiting_on_customer' => 'Waiting on customer for :elapsed',
        'waiting_on_update' => 'Waiting on update for :elapsed',
        'waiting_customer_since_open' => 'Waiting on customer since ticket opened',
        'waiting_agent_since_open' => 'Waiting on agent update since ticket opened',
        'not_linked' => 'Not linked',
        'lifecycle_note' => 'Lifecycle note',
        'latest_attempt' => 'Latest attempt',
        'escalated_to_you' => 'Escalated to you',
        'escalated_recent' => 'Recently escalated',
        'actor_visitor' => 'Visitor',
        'actor_system' => 'System',
        'preview_summary' => 'Ticket summary',
        'preview_none_body' => 'Open the ticket to add context or send the next update.',
        'preview_none_label' => 'No activity preview yet',
        'no_linked_conversation' => 'No linked conversation',
        'reply_visibility_none' => 'Reply visibility starts once this ticket is connected to a conversation.',
        'reply_visibility' => 'Reply visibility:',
        'none' => 'None',
        'unassigned' => 'Unassigned',
    ],
];
