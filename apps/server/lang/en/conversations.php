<?php

/**
 * The conversation queue.
 *
 * Two rules shape this file, both of them learned from the copy it replaces.
 *
 * **Whole sentences carry placeholders; fragments are not concatenated.** The
 * queue used to build its summary as `'Showing '.$count.' after the '.$lane.'
 * support-lane filter.'`, which reads as one sentence and is really three
 * pieces in English word order. No other language is obliged to keep that
 * order, and a translator handed the pieces cannot move them.
 *
 * **Counts go through `trans_choice`, verb included.** `'1 needs attention'`
 * against `'3 need attention'` is a plural rule, and plural rules belong beside
 * the words rather than in an inline ternary beside the code. English changes
 * the verb here and German does not, which is exactly the sort of thing a
 * hand-built plural gets wrong.
 */
return [
    'document_title' => 'Conversations',
    'title' => 'Conversation queue',
    'page_title_active' => 'Active visitor conversations for :account.',
    'page_title_closed' => 'Closed visitor conversations for :account.',

    'search' => [
        'placeholder' => 'Subject, support code, or visitor',
        'hint' => 'Search by subject, support code, visitor ID, visitor name, or visitor email.',
        'label' => 'Search',
        'submit' => 'Search conversations',
    ],

    'sites' => [
        'any' => 'Any site',
    ],

    'filters_label_presence' => 'Presence',
    'filters' => [
        'all' => 'All open',
        'new_activity' => 'New activity',
        'needs_reply' => 'Needs reply',
        'assigned_to_me' => 'Assigned to me',
        'unassigned' => 'Unassigned',
        'cobrowse_attention' => 'Cobrowse attention',
        'closed' => 'Closed',
    ],

    'lanes' => [
        'region_label' => 'Conversation lanes',
        'new_activity' => 'Needs attention',
        'needs_reply' => 'Needs reply',
        'assigned_to_me' => 'Assigned to me',
        'unassigned' => 'Unassigned',
        'active' => 'Active visitors',
        'recent' => 'Recently active',
    ],

    'chips' => [
        'region_label' => 'Active conversation filters',
        'site' => 'Site: :name',
        'search' => 'Search: :term',
        'presence' => 'Presence: :label',
    ],

    'actions' => [
        'clear_filters' => 'Clear filters',
        'clear_all' => 'Clear all conversation filters',
        'clear_search' => 'Clear search',
        'clear_support_lane' => 'Clear support lane',
        'show_active' => 'Show active conversations',
        'check_installs' => 'Check widget installs',
    ],

    'counts' => [
        'conversations' => '{1} 1 conversation|[2,*] :count conversations',
        'needs_attention' => '{1} 1 needs attention|[2,*] :count need attention',
        'cobrowse_attention' => '{1} 1 cobrowse session needs attention|[2,*] :count cobrowse sessions need attention',
        'closed' => '{1} 1 closed|[2,*] :count closed',
        'open_matching' => '{1} 1 open matching|[2,*] :count open matching',
        'matches' => '{1} 1 conversation matches|[2,*] :count conversations match',
        'matching_conversations' => '{1} 1 matching conversation|[2,*] :count matching conversations',
    ],

    'summary' => [
        // Every sentence carrying a count is plural-aware as a WHOLE, not just
        // in the number it interpolates. `:shown` already chose between
        // "1 conversation" and "3 conversations", and the verbs around it have
        // to agree with the same number -- English got this wrong too, reading
        // "1 shown of 1 matching conversations".
        'lane_narrowed_heading' => ':shown shown of :matching',
        // The attention lane gets its own sentence rather than a predicate
        // pushed into the one above. `1 needs attention` is a clause, not a
        // noun phrase, and dropping a clause into a slot meant for a number
        // produces word order no language has to accept.
        'lane_narrowed_attention_heading' => '{1} :shown of :matching needs attention|[2,*] :shown of :matching need attention',
        'lane_narrowed_detail' => '{1} Showing :shown after the :lane support-lane filter. :matching the other queue filters.|[2,*] Showing :shown after the :lane support-lane filter. :matching the other queue filters.',
        'filtered_detail' => '{1} Showing :shown matching the current queue filters.|[2,*] Showing :shown matching the current queue filters.',
        'open_heading' => ':open open · :attention · :cobrowse',
    ],

    'empty' => [
        'no_match_filters' => 'No conversations match those filters.',
        'no_new_activity' => 'No conversations need attention.',
        'no_cobrowse_attention' => 'No active cobrowse sessions need attention.',
        'no_closed' => 'No closed conversations yet.',
        'no_active' => 'No active conversations yet.',
        'no_search_match' => 'No conversations match ":term".',
        'search_covers' => 'Search covers subject, support code, visitor ID, visitor name, and visitor email.',
        'refine_detail' => 'Try another site or presence filter, or clear the filters to return to the broader queue.',
        'closed_detail' => 'Closed conversations will appear here after agents close support threads.',
        'default_detail' => 'New visitor conversations will appear here as support starts.',
        'first_run_detail' => 'New visitor conversations will appear here as support starts. Conversations begin when a visitor opens the widget on a connected site.',
        'lane_detail' => 'Try another support lane or clear the lane filter. :matching the other queue filters.',
        'lane_assigned_to_me' => 'No conversations are assigned to you in this queue.',
        'lane_cobrowse_attention' => 'No active cobrowse sessions need attention.',
        'lane_needs_reply' => 'No conversations need a reply right now.',
        'lane_new_activity' => 'No conversations need attention right now.',
        'lane_unassigned' => 'No unassigned conversations in this queue.',
    ],

    'columns' => [
        'subject' => 'Subject',
        'site' => 'Site',
        'visitor' => 'Visitor',
        'attention' => 'Attention',
        'read' => 'Read',
        'cobrowse' => 'Cobrowse',
        'assigned' => 'Assigned',
        'timing' => 'Timing',
    ],

    'row' => [
        'attention_waiting_on_visitor' => 'Waiting on visitor',
        'attention_needs_reply' => 'Needs reply',
        'preview_none_body' => 'No messages have been sent yet.',
        'preview_none_label' => 'No activity preview yet',
        'preview_no_text' => 'Message has no text preview.',
        'preview_visitor' => 'Latest visitor message',
        'preview_agent' => 'Latest agent reply',
        'preview_message' => 'Latest message',
        'opened' => 'Opened :elapsed',
        'closed' => 'Closed :elapsed',
        'waiting_on_reply' => 'Waiting on reply for :elapsed',
        'waiting_on_visitor' => 'Waiting on visitor for :elapsed',
        'waiting_on_update' => 'Waiting on update for :elapsed',
        'read_new_activity' => 'New activity',
        'read_seen' => 'Seen',
        'unassigned_agent' => 'Unassigned',
        'last_report' => 'Last report :value',
        'pressure' => 'Pressure :value',
        'activity' => 'Activity :elapsed',
        'untitled' => 'Untitled conversation',
        'no_messages' => 'No messages yet',
    ],
];
