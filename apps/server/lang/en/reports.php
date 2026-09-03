<?php

return [
    'document_title' => 'Reports',
    'title' => 'Reports',
    'subtitle' => 'How much support came in, how fast it was answered, and who answered it.',

    'tabs' => [
        'region' => 'Report sections',
        'volume' => 'Volume',
        'speed' => 'Speed',
        'tickets' => 'Tickets',
        'agents' => 'Agents',
        'satisfaction' => 'Satisfaction',
    ],

    'range' => [
        'heading' => 'Range',
        'one_site' => 'One site',
        'all_sites' => 'All visible sites',
        'period' => 'Period',
        'last_days' => '{1} Last :count day|[0,*] Last :count days',
        'site' => 'Site',
        'archived_sites' => 'Archived sites',
        'report' => 'Report',
        'apply' => 'Apply',
        'reset' => 'Reset',
    ],

    'history' => [
        'heading' => 'What these numbers can reach',
        'lede' => 'Not all of it is the same age',
        'opened' => 'Conversations opened',
        'opened_detail' => 'and',
        'first_response' => 'first response times',
        'first_response_detail' => 'are recoverable from the whole history of this install.',
        'lifecycle' => 'Closes, resolution times and reopens',
        'lifecycle_with_date' => 'are read from lifecycle records, which this install began keeping on :date. Anything before that is unrecorded rather than absent — conversations were closed, but nothing was keeping the sequence, and it cannot be reconstructed after the fact.',
        'lifecycle_without_date' => 'are read from lifecycle records, and this install has not stamped when it started keeping them. Run outstanding migrations; until then these figures cover only what happens to be on record.',
        'purge' => 'Purging a site removes its history along with everything else, so a total can legitimately fall.',
    ],

    'counts' => [
        'opened' => '{1} :count opened|[0,*] :count opened',
        'closed' => '{1} :count closed|[0,*] :count closed',
        'created' => '{1} :count created|[0,*] :count created',
        'open_now' => '{1} :count open now|[0,*] :count open now',
        'tickets_created' => '{1} :count ticket created|[0,*] :count tickets created',
        'tickets_closed' => '{1} :count ticket closed|[0,*] :count tickets closed',
        'tickets_open_now' => '{1} :count ticket open now|[0,*] :count tickets open now',
        'opened_label' => 'Opened',
        'closed_label' => 'Closed',
        'created_label' => 'Created',
        'tickets_closed_label' => 'Closed',
        'measured' => '{1} :count measured|[0,*] :count measured',
        'closes_measured' => '{1} :count close measured|[0,*] :count closes measured',
        'agents' => '{1} :count agent|[0,*] :count agents',
        'comments' => '{1} :count comment|[0,*] :count comments',
    ],

    'charts' => [
        'tallest_day' => 'Tallest day: :count',
    ],

    'metrics' => [
        'median' => 'Median',
        'p90' => '90th percentile',
        'slowest_tenth' => 'The slowest tenth took at least this long.',
        'unmeasured' => 'Counted but not measured',
        'reopened' => 'Reopened',
        'reopened_detail' => 'A resolution that did not hold.',
    ],

    'duration' => [
        'seconds' => '{1} :count second|[0,*] :count seconds',
        'minutes' => '{1} :count minute|[0,*] :count minutes',
        'hours' => '{1} :count hour|[0,*] :count hours',
        'days' => '{1} :count day|[0,*] :count days',
    ],

    'conversations' => [
        'volume' => [
            'heading' => 'Conversation volume',
            'empty' => 'No conversations were opened or closed in this period.',
            'chart_aria' => 'Conversations per day. :opened opened and :closed closed over the :days days ending :date. The busiest day had :busiest.',
            'day_title' => ':date: :opened opened, :closed closed',
            'export' => 'Export the daily series as CSV',
        ],
        'queue' => [
            'heading' => 'Waiting right now',
            'lede' => 'A live count, not a trend',
            'empty' => 'Nothing is waiting on a reply.',
            'waiting' => '{1} :count conversation is waiting on the desk, the oldest for :duration.|[0,*] :count conversations are waiting on the desk, the oldest for :duration.',
            'threshold' => '{1} For reference, unattended alerts fire once a conversation has waited :count minute without anyone looking at it. This count is every conversation waiting, whatever its age.|[0,*] For reference, unattended alerts fire once a conversation has waited :count minutes without anyone looking at it. This count is every conversation waiting, whatever its age.',
        ],
        'response' => [
            'heading' => 'First response',
            'empty' => 'No conversation opened in this period has had a first reply yet.',
            'median_detail' => 'Half of visitors waited less than this.',
            'p90_detail' => 'The unlucky tenth waited at least this long.',
            'awaiting' => '{1} :count conversation opened in this period has had no reply at all, so it is counted here rather than folded into the figures above.|[0,*] :count conversations opened in this period have had no reply at all, so they are counted here rather than folded into the figures above.',
        ],
        'resolution' => [
            'heading' => 'Resolution',
            'unmeasurable_empty' => '{1} :count conversation was closed in this period, but it opened before this install started recording reopens, so how long the work took cannot be established. Resolution times will appear as conversations opened since then are closed.|[0,*] :count conversations were closed in this period, but they opened before this install started recording reopens, so how long the work took cannot be established. Resolution times will appear as conversations opened since then are closed.',
            'empty' => 'No conversation was closed in this period.',
            'median_detail' => 'From opening, or from the reopen that started the stretch of work.',
            'unmeasured_detail' => 'Closed before this install started recording reopens, so how long the work took cannot be established. Counted as closes above; left out of the two figures here rather than inflating them.',
            'reopened_by_visitor' => 'Reopened by a visitor',
            'reopened_by_visitor_detail' => 'The visitor came back rather than an agent reopening it — the clearest signal the answer did not land.',
        ],
    ],

    'tickets' => [
        'volume' => [
            'heading' => 'Ticket volume',
            'empty_before_history' => 'No ticket was created in this period, and no close is on record for it. This install began recording ticket closes on :date, and the range selected reaches back before that — tickets closed earlier left no trace to count.',
            'empty' => 'No ticket was created or closed in this period.',
            'chart_aria' => 'Tickets per day. :created created and :closed closed over the :days days ending :date. The busiest day had :busiest.',
            'day_title' => ':date: :created created, :closed closed',
        ],
        'resolution' => [
            'heading' => 'Ticket resolution',
            'unmeasurable_empty' => '{1} :count ticket was closed in this period, but it opened before this install started recording ticket reopens, so how long the work took cannot be established. Resolution times will appear as tickets opened since then are closed.|[0,*] :count tickets were closed in this period, but they opened before this install started recording ticket reopens, so how long the work took cannot be established. Resolution times will appear as tickets opened since then are closed.',
            'reopened_unmeasurable' => '{1} :count ticket was reopened in this period — a resolution that did not hold. That is countable even where the durations are not.|[0,*] :count tickets were reopened in this period — resolutions that did not hold. That is countable even where the durations are not.',
            'reopened_without_close' => 'A resolution that did not hold. Nothing closed in this period, so there is no resolution time to report alongside it.',
            'empty_before_history' => 'No ticket close is on record in this period. This install began recording ticket closes on :date, and the range selected reaches back before that — tickets closed earlier left no trace to count, so this is not the same as nothing having happened.',
            'empty' => 'No ticket was closed in this period.',
            'median_detail' => 'Half of tickets were resolved faster than this.',
            'unmeasured_detail' => 'Opened before this install started recording ticket reopens, so how long the work took cannot be established. Left out of the two figures above rather than inflating them.',
            'reopened_detail' => 'A resolution that did not hold. Each reopen starts a new episode, so a ticket closed three times contributes three resolutions rather than one long one.',
            'history' => 'This install began recording ticket closes and reopens on :date. A ticket opened before then may have been closed and reopened while nothing was writing it down, so it is counted as a close and left out of the times here.',
        ],
        'agents' => [
            'heading' => 'Who carried the ticket work',
            'empty' => 'No ticket replies or closes in this period.',
        ],
    ],

    'tables' => [
        'agent' => 'Agent',
        'replies' => 'Replies sent',
        'tickets_closed' => 'Tickets closed',
        'conversations_closed' => 'Conversations closed',
    ],

    'agents' => [
        'heading' => 'Who carried the work',
        'empty' => 'No agent replied to or closed a conversation in this period.',
        'removed' => 'Removed agent',
        'deactivated' => 'Deactivated',
        'deactivated_detail' => 'Deactivated agents stay listed: they did the work, and a total that changes when someone leaves is not a total.',
        'export' => 'Export as CSV',
    ],

    'satisfaction' => [
        'heading' => 'Whether it helped',
        'summary' => '{1} :answered of :closed close answered|[0,*] :answered of :closed closes answered',
        'no_closes' => 'No conversation was closed in this period, so nobody was asked.',
        'no_answers_before' => 'Nobody answered in this period. That is not a bad score — it is no score, and the two must not be read as the same thing. If your sites are not asking, turn the prompt on under',
        'setting' => 'Asking how it went',
        'no_answers_after' => 'in a site’s settings.',
        'good' => 'Good',
        'good_detail' => ':percentage of the people who answered.',
        'ok' => 'Ok',
        'ok_detail' => 'Helped, but not a story anybody will tell.',
        'bad' => 'Bad',
        'bad_detail' => 'The answer this whole tab exists to surface.',
        'answered' => 'Answered',
        'answered_detail' => '{1} Out of :count close. Every figure above is a share of this number, never of the closes — people who said nothing are not counted as satisfied.|[0,*] Out of :count closes. Every figure above is a share of this number, never of the closes — people who said nothing are not counted as satisfied.',
        'small_sample' => 'Few enough answers that one more would move the percentage noticeably. Read it as a direction rather than a measurement.',
    ],

    'comments' => [
        'heading' => 'What people said',
        'empty' => 'Nobody left a comment in this period. The comment box is optional, and most people skip it — a score with no words is still an answer.',
        'score' => 'Score',
        'said' => 'What they said',
        'conversation' => 'Conversation',
        'when' => 'When',
        'latest' => '{1} The most recent :count comment. A score tells you something went wrong; this is the only place that says what.|[0,*] The most recent :count comments. A score tells you something went wrong; this is the only place that says what.',
    ],
];
