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
    'detail_document_title' => 'Conversation :code',
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
        'capped_notice' => 'Showing the :shown most recently active of :total. Narrow the queue with the filters above to see the rest.',
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

    /*
     * The conversation detail page.
     *
     * Its own group inside the queue's catalogue rather than a `conversation.php`
     * beside `conversations.php`, which nobody would reliably tell apart.
     *
     * The cobrowse panels' HEADINGS are here because this page owns them, the
     * same way the queue owns "Last report". The VALUES inside them come from
     * `CobrowseConsentState` and are still the recorded exception -- they carry
     * their own `lang` until that vocabulary is extracted in its own change.
     */
    'detail' => [
        'untitled' => 'Untitled conversation',
        'no_messages' => 'No messages yet.',
        'unknown_visitor' => 'Unknown visitor',
        'not_reported' => 'Not reported',
        'no_heartbeat' => 'No visitor heartbeat yet.',
        'visitor_actor' => 'Visitor',
        'ticket_from_conversation' => 'Created from conversation :code.',
        'ticket_subject_fallback' => 'Conversation :code',
        'back' => 'Back to conversations',
        'support_code' => 'Support code :code',

        'statuses' => [
            'open' => 'Open',
            'closed' => 'Closed',
        ],

        'tones' => [
            'attention' => 'Attention',
            'ready' => 'Ready',
            'manual' => 'Manual',
        ],

        'headings' => [
            'messages' => 'Messages',
            'context' => 'Context',
            'references' => 'Support references',
            'ticket' => 'Ticket',
        ],

        'tabs' => [
            'workspace' => 'Conversation workspace',
            'conversation' => 'Conversation',
            'cobrowse' => 'Cobrowse',
            'visitor' => 'Visitor',
            'ticket' => 'Ticket',
            'linked_badge' => '{1} 1 linked|[2,*] :count linked',
            'not_created' => 'Not created',
            'position' => ':position of :total',
            'transcript_total' => '{1} 1 total|[2,*] :count total',
        ],

        'nav' => [
            'move' => 'Move through the conversation queue',
            'previous' => 'Previous conversation in this queue',
            'next' => 'Next conversation in this queue',
        ],

        'next_action' => [
            'heading' => 'Next action',
        ],

        'roles' => [
            'agent' => 'Agent',
            'integration' => 'Integration',
            'proactive' => 'Proactive message',
            'visitor' => 'Visitor',
        ],

        'reply' => [
            'heading' => 'Reply',
            'label' => 'Message',
            'send' => 'Send reply',
            'shortcut' => 'Command or Control plus Enter sends this reply.',
            'attach' => 'Attach file',
            'files' => 'Files to send with this reply',
            'guidance' => 'Write a clear, calm reply.',
            'privacy' => 'Keep sensitive details out of replies unless the visitor supplied them here.',
            'assist' => 'Reply assist',
            'helper' => 'Reply helper',
            'helper_note' => 'Reply helpers offer a starting point you can edit. Wayfindr never writes a reply for you.',
            'custom' => 'Write a custom reply',
            'writing_own' => 'Writing this one yourself',
            'context' => 'Reply context',
            'visibility' => 'Reply visibility',
            'visibility_none' => 'Reply visibility starts once this ticket is connected to a conversation.',
            'visibility_label' => 'Visibility',
            'typing' => 'Visitor is typing…',
            'no_body' => 'This message has no text or attachment.',
            'visitor_read' => 'Visitor read',
            'seen_by_visitor' => 'Seen by visitor :elapsed',
            'not_seen' => 'Not seen yet',
        ],

        'copilot' => [
            'heading' => 'Conversation summary',
            'suggested' => 'Suggested',
            'lede' => 'Generate a short handoff summary from this conversation when another agent needs the thread quickly.',
            'generate' => 'Summarize conversation',
            'regenerate' => 'Refresh summary',
            'retry' => 'Try summary again',
            'pending' => 'Generating a suggestion…',
            'pending_detail' => 'The request is queued. This panel updates when the summary is ready.',
            'generated' => 'Generated :elapsed.',
            'source_count' => '{1} Based on 1 text message.|[2,*] Based on :count text messages.',
            'stale' => 'New messages arrived after this summary.',
            'stale_detail' => 'Review the transcript or refresh the suggestion before using it for handoff.',
            'failed' => 'The summary could not be generated.',
            'failed_detail' => 'No reply or support record changed. You can try again.',
            'privacy' => 'Only the subject and bounded message text are selected. Agent and visitor profile fields, metadata, attachments, and cobrowse data are omitted; common sensitive patterns are scrubbed before provider delivery.',
        ],

        'context' => [
            'heading' => 'Visitor at a glance',
            'about' => 'What this is about',
            'visitor' => 'Visitor',
            'email' => 'Email',
            'site' => 'Site',
            'status' => 'Status',
            'presence' => 'Presence',
            'opened' => 'Opened',
            'last_seen' => 'Last seen',
            'latest_activity' => 'Latest activity',
            'entry_page' => 'Entry page',
            'latest_page' => 'Latest page',
            'host_context' => 'Host context',
            'host_visitor_id' => 'Host visitor ID',
            'field' => 'Field',
            'value' => 'Value',
            'timing' => 'Timing',
            'owner' => 'Owner',
            'assigned_to' => 'Assigned to',
            'owner_label' => 'Owner: :name',
            'previous_count' => '{1} 1 previous|[2,*] :count previous',
            'field_count' => '{1} 1 field|[2,*] :count fields',
            'seen_recently' => 'Seen in the last 2 minutes',
            'seen_at' => 'Seen :elapsed',
            'unassigned' => 'Unassigned',
            'open_profile' => 'Open visitor profile',
            'prior' => 'Prior conversations',
            'history' => 'History on this site',
            'safe_only' => 'Safe context only',
            'boundary' => 'Use this context to answer the current request. Do not collect, export, or infer extra visitor data without consent.',
            'no_host_context' => 'No host-provided context yet.',
            'no_prior' => 'No prior conversations for this visitor on this site.',
            'last_activity' => 'Last activity :elapsed',
            'last_activity_none' => 'none yet',
            'last_activity_label' => 'Last activity: :elapsed',
            'close' => 'Close conversation',
            'reopen' => 'Reopen conversation',
            'not_reported' => 'Not reported',
            'not_provided' => 'Not provided',
            'session_diagnostics' => 'Session diagnostics',
            'no_page_state' => 'No visitor page state yet.',
        ],

        'ticket' => [
            'heading' => 'Linked ticket',
            'work' => 'Linked ticket work',
            'actions' => 'Ticket actions',
            'lede' => 'Keep ownership and lifecycle close to the conversation.',
            'create_hint' => 'Create or attach a ticket when the next step needs durable follow-up.',
            'create' => 'Create ticket',
            'open' => 'Open ticket',
            'assign' => 'Assign ticket',
            'close' => 'Close ticket',
            'reopen' => 'Reopen ticket',
            'pending' => 'Mark pending',
            'none' => 'No ticket',
            'title' => 'Title',
            'category' => 'Category',
            'priority' => 'Priority',
            'uncategorized' => 'Uncategorized',
            'resolution_note' => 'Resolution note',
            'resolution_hint' => 'What changed or why this can be closed.',
            'claim' => 'Claim conversation',
            'release' => 'Release conversation',
        ],

        'references' => [
            'heading' => 'Support references',
            'lede' => 'Use these references when the visitor or another agent needs to find this support trail again.',
            'current' => 'Current support code',
            'same_visitor' => 'Same visitor support codes',
            'records' => 'Current and same-visitor records',
            'visitor_reference' => 'Visitor reference',
            'note' => 'Reference note',
            'none' => 'No previous support codes yet.',
        ],

        'cobrowse' => [
            'heading' => 'Cobrowse',
            'request' => 'Request cobrowse',
            'consent' => 'Consent granted',
            'updates' => 'Cobrowse updates',
            'waiting' => 'Waiting for live cobrowse updates.',
            'transport_health' => 'Transport health',
            'transport_detail' => 'Transport detail',
            'telemetry' => 'Connection telemetry',
            'no_telemetry' => 'No cobrowse connection telemetry yet.',
            'session_timeline' => 'Session timeline',
            'recovery_timeline' => 'Recovery timeline',
            'recovery_action' => 'Recovery action',
            'guidance' => 'Agent guidance',
            'page_snapshot' => 'Page snapshot',
            'no_snapshot' => 'No sanitized page snapshot yet.',
            'snapshot_freshness' => 'Snapshot freshness',
            'snapshot_guidance' => 'Snapshot refresh guidance',
            'fresh_path' => 'Fresh snapshot path',
            'fresh_requested' => 'Fresh snapshot already requested',
            'fresh_waiting' => 'Waiting for the visitor widget before requesting another snapshot.',
            'replay_preview' => 'Replay preview',
            'replay_heading' => 'Cobrowse replay preview',
            'no_replay' => 'No replay preview yet.',
            'refresh_preview' => 'Refresh preview',
            'mutation_stream' => 'Mutation stream',
            'no_mutations' => 'No mutation stream diagnostics yet.',
            'visitor_page' => 'Visitor page',
            'data_boundary' => 'Data boundary',
            'masked' => 'Masked',
            'status_safety' => 'Status safety',
            'requested_by' => 'Requested by',
            'stopped_by' => 'Stopped by',
            'requested' => 'Requested',
            'stopped' => 'Stopped',
            'reported' => 'Reported',
            'state' => 'State',
            'focus' => 'Focus',
            'scroll' => 'Scroll',
            'viewport' => 'Viewport',
            'url' => 'URL',
            'nodes' => 'Nodes',
            'samples' => 'Samples',
            'rtt' => 'RTT',
            'max_rtt' => 'Max RTT',
            'payload' => 'Payload',
            'max_payload' => 'Max payload',
            'batches' => 'Batches',
            'dropped' => 'Dropped',
            'dropped_batches' => 'Dropped batches',
            'mutations' => 'Mutations',
            'skipped' => 'Skipped',
            'reconnects' => 'Reconnects',
            'last_sequence' => 'Last sequence',
            'last_report' => 'Last report',
            'pressure' => 'Pressure',
        ],
    ],

    'validation' => [
        'reply_template' => 'Choose an available reply helper.',
        'body' => 'Please enter a reply or attach a file.',
    ],

    'reply_templates' => [
        'looking_into_it' => [
            'label' => 'Looking into it',
        ],
        'need_more_detail' => [
            'label' => 'Need more detail',
        ],
        'confirm_resolution' => [
            'label' => 'Confirm resolution',
        ],
        'ticket_follow_up' => [
            'label' => 'Ticket follow-up',
        ],
    ],

    'bulk' => [
        'region' => 'Bulk conversation actions',
        'selection' => 'Selection',
        'action_label' => 'Action',
        'choose_action' => 'Choose an action',
        'review' => 'Review changes',
        'clear' => 'Clear selection',
        'select_all' => 'Select all visible conversations',
        'select_conversation' => 'Select conversation: :subject',
        'selected' => [
            'none' => 'No conversations selected',
            'one' => '1 conversation selected',
            'many' => ':count conversations selected',
        ],
        'actions' => [
            'assign_agent' => 'Assign agent',
            'set_priority' => 'Set priority',
            'set_status' => 'Set status',
            'close' => 'Close conversations',
        ],
        'values' => [
            'agent' => 'Agent',
            'priority' => 'Priority',
            'status' => 'Status',
            'choose_agent' => 'Choose an agent',
            'choose_priority' => 'Choose a priority',
            'choose_status' => 'Choose a status',
        ],
        'confirm' => [
            'document_title' => 'Review bulk conversation changes',
            'title' => 'Review bulk changes',
            'subtitle' => 'Confirm the exact result of: :action.',
            'heading' => 'Conversations in this change',
            'summary' => 'Selected: :selected. Changes: :changed.',
            'undo_note' => 'Undo restores only this direct change. If somebody edits the same field afterward, Wayfindr skips that conversation instead of overwriting newer work.',
            'before' => 'Before',
            'after' => 'After',
            'result' => 'Result',
            'will_change' => 'Will change',
            'no_change' => 'No change',
            'apply' => '{1} Apply to 1 conversation|[2,*] Apply to :count conversations',
            'cancel' => 'Back to conversation queue',
        ],
        'undo' => [
            'submit' => 'Undo this bulk action',
        ],
        'flash' => [
            'applied' => 'Bulk action applied. Selected: :selected. Changed: :changed.',
            'undone' => 'Bulk action undone. Restored: :reverted. Skipped because newer work was found: :skipped.',
        ],
        'errors' => [
            'value_required' => 'Choose a value for this bulk action.',
            'value_invalid' => 'That bulk-action value is not available.',
            'assignee_unavailable' => 'That agent cannot work every selected conversation.',
            'preview_expired' => 'That review expired or was already used. Select the conversations and review the action again.',
            'preview_stale' => 'One or more conversations changed after the review. Nothing was applied; review the current values and try again.',
            'nothing_to_change' => 'Those conversations already have that value. Nothing was applied.',
            'already_undone' => 'That bulk action has already been undone.',
        ],
    ],

    'flash' => [
        'priority_updated' => 'Conversation priority updated.',
        'reply_sent' => 'Reply sent.',
        'closed' => 'Conversation closed.',
        'reopened' => 'Conversation reopened.',
        'claimed' => 'Conversation claimed.',
        'released' => 'Conversation released.',
        'ticket_exists' => 'Ticket already exists.',
        'ticket_created' => 'Ticket created.',
        'cobrowse_already_active' => 'Cobrowse request already active.',
        'cobrowse_requested' => 'Cobrowse requested.',
        'cobrowse_none' => 'No active cobrowse session.',
        'cobrowse_ended' => 'Cobrowse session ended.',
        'cobrowse_needed_for_snapshot' => 'Cobrowse must be active before requesting a fresh snapshot.',
        'snapshot_already_requested' => 'Fresh cobrowse snapshot already requested.',
        'snapshot_requested' => 'Fresh cobrowse snapshot requested.',
        'ai_summary_queued' => 'Conversation summary queued.',
        'ai_summary_pending' => 'A conversation summary is already being generated.',
        'ai_summary_failed' => 'The conversation summary could not be queued. No support data was sent.',
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
        'unknown_visitor' => 'Unknown visitor',
        'no_messages' => 'No messages yet',
    ],
];
