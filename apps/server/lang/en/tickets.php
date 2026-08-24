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

    'next_action' => [
        'needs_reply' => [
            'title' => 'Reply to visitor',
            'body' => 'Visitor replied last. Send a clear response, then mark the ticket pending or close it when the outcome is settled.',
            'cta' => 'Jump to reply',
        ],
        'needs_owner' => [
            'title' => 'Assign an owner',
            'body' => 'No agent owns this ticket yet. Assign someone before work gets lost.',
            'cta' => 'Assign ticket',
        ],
        'waiting_on_customer' => [
            'title' => 'Wait on customer',
            'body' => 'Agent replied last. Keep the ticket visible, then reopen the loop when the visitor answers.',
            'cta' => 'Review status actions',
        ],
        'resolved' => [
            'title' => 'Review resolution',
            'body' => 'This ticket is closed. Reopen it only if the customer comes back or the outcome changes.',
            'cta' => 'Review status actions',
        ],
        'needs_agent' => [
            'title' => 'Add the next update',
            'body' => 'This ticket is assigned and ready for an agent update. Add a reply, internal note, or status change.',
            'cta' => 'Review actions',
        ],
    ],

    'status_readiness' => [
        'reply_before_closing' => [
            'title' => 'Reply before closing',
            'detail' => 'Visitor replied last. Closing now may leave the customer waiting. Use pending or close only after an agent update or a confirmed outcome.',
            'cta' => 'Jump to reply',
        ],
        'assign_first' => [
            'title' => 'Assign before status changes',
            'detail' => 'Assign an owner before changing status so follow-up does not drift.',
            'cta' => 'Assign ticket',
        ],
        'pending' => [
            'title' => 'Pending ticket',
            'detail' => 'This ticket is pending. Reopen it when the visitor answers or new work is needed.',
            'cta' => 'Review reopen option',
        ],
        'calm' => [
            'title' => 'Lifecycle options are calm',
            'detail' => 'Agent replied last. Mark pending if you are waiting on the visitor, or close once the outcome is settled.',
            'cta' => 'Review status actions',
        ],
        'closed' => [
            'title' => 'Closed ticket',
            'detail' => 'Reopen only if the customer comes back or the outcome changes. Use the reopen note to leave the next agent enough context.',
            'cta' => 'Review reopen option',
        ],
        'default' => [
            'title' => 'Lifecycle options are calm',
            'detail' => 'Add the next update, internal note, pending state, or close once the outcome is clear.',
            'cta' => 'Review status actions',
        ],
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

    'guidance' => [
        'category_aria' => 'Category guide',
        'priority_aria' => 'Priority guide',
        'agent_move' => 'Agent move: :action',
    ],

    'category_help' => [
        'question' => [
            'description' => 'General question or how-to help.',
            'guidance' => 'Use for: clarification, product guidance, or "how do I?" support.',
        ],
        'bug' => [
            'description' => 'Something broken or not working as expected.',
            'guidance' => 'Use for: broken, unexpected, or reproducible behavior.',
        ],
        'billing' => [
            'description' => 'Pricing, invoice, payment, or account billing issue.',
            'guidance' => 'Use for: pricing, invoices, payments, renewals, or billing-account changes.',
        ],
        'access' => [
            'description' => 'Login, permissions, or account access issue.',
            'guidance' => 'Use for: login, roles, locked accounts, permissions, or identity/access issues.',
        ],
        'task' => [
            'description' => 'Follow-up work, configuration, or operational request.',
            'guidance' => 'Use for: setup, configuration, operational work, or planned follow-up.',
        ],
        'other' => [
            'description' => 'Anything that does not fit the other categories.',
            'guidance' => 'Use sparingly; add context so it can be recategorized later.',
        ],
    ],

    'priority_help' => [
        'low' => [
            'description' => 'Nice-to-have follow-up or non-blocking question.',
            'agent_action' => 'handle after active visitor blockers.',
        ],
        'normal' => [
            'description' => 'Standard support request with no immediate deadline.',
            'agent_action' => 'answer in normal queue order.',
        ],
        'high' => [
            'description' => 'Time-sensitive issue affecting an important customer workflow.',
            'agent_action' => 'keep it moving today.',
        ],
        'urgent' => [
            'description' => 'Business-critical, active outage, or blocked production work.',
            'agent_action' => 'assign immediately and keep the visitor updated.',
        ],
    ],

    'flash' => [
        'reply_sent' => 'Reply sent.',
        'assignee_updated' => 'Ticket assignee updated.',
        'closed' => 'Ticket closed.',
        'escalated' => 'Ticket escalated.',
        'label_added' => 'Ticket label added.',
        'label_removed' => 'Ticket label removed.',
        'marked_pending' => 'Ticket marked pending.',
        'note_added' => 'Ticket note added.',
        'note_added_posted' => 'Ticket note added and posted to the linked issue.',
        'note_added_not_posted' => 'Ticket note added, but the external comment could not be posted. See ticket activity.',
        'reopened' => 'Ticket reopened.',
        'updated' => 'Ticket updated.',
    ],
];
