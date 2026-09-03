<?php

/*
 * The platform-operator side of break-glass access.
 *
 * Account names, site names, support codes, reasons, message bodies and file
 * metadata are customer data. They stay outside this catalogue and the views
 * mark them with an unknown language. Scope and lifecycle vocabulary is shared
 * with the account-facing operator-access catalogue.
 */

return [
    'document_title' => 'Operator Access',
    'title' => 'Operator access',
    'introduction' => 'You cannot see any account’s conversations or tickets by default. Ask here when you need to, for one conversation, one site or one account. The account sees your reason, approves or denies it, and can end it at any point. Access is read-only and expires on its own, and every page you open is recorded for them.',

    'request' => [
        'heading' => 'Ask for access',
        'subtitle' => 'Ask for the least that answers your question',
        'scope' => [
            'label' => 'What do you need to see?',
            'options' => [
                'conversation' => 'One conversation (support code)',
                'site' => 'One site',
                'account' => 'Entire account',
            ],
        ],
        'support_code' => [
            'label' => 'Support code',
            'help' => 'Fill this in if you chose one conversation.',
        ],
        'site' => [
            'label' => 'Site',
            'choose' => 'Choose a site',
            'help' => 'Fill this in if you chose one site.',
        ],
        'account' => [
            'label' => 'Account',
            'choose' => 'Choose an account',
            'help' => 'Fill this in if you chose an entire account.',
        ],
        'duration' => [
            'label' => 'How long do you need it?',
            'options' => [
                'fifteen_minutes' => '15 minutes',
                'one_hour' => '1 hour',
                'four_hours' => '4 hours',
                'one_day_maximum' => '24 hours (maximum)',
            ],
        ],
        'reason' => [
            'label' => 'Why do you need it?',
            'placeholder' => 'What are you investigating, and why does answering it need this account’s content?',
        ],
        'submit' => 'Request access',
    ],

    'requests' => [
        'heading' => 'Your requests',
        'count' => '{1} :count recent|[2,*] :count recent',
        'empty' => 'You have not asked for access to any account yet. Support data is closed to operators until an account opens it.',
        'scope_status' => ':scope — :status',
        'requested' => 'Requested :elapsed',
        'expires' => 'expires :elapsed',
        'waiting_on' => 'waiting on :people',
        'waiting_on_fallback' => 'waiting on an account owner or admin',
        'self_approve' => 'Self-approve',
        'open' => 'Open access',
        'close' => 'Close now',
    ],

    'grant' => [
        'document_title' => 'Operator Access',
        'back' => 'Back to operator access',
        'summary' => 'Read-only access until :until (:elapsed). Every view is recorded and visible to :account.',
        'conversations' => [
            'heading' => 'Conversations',
            'count' => '{1} :count covered|[2,*] :count covered',
            'empty' => 'No conversations in scope.',
            'row' => ':site · started :elapsed',
            'view' => 'View transcript',
        ],
        'tickets' => [
            'heading' => 'Tickets',
            'count' => '{1} :count covered|[2,*] :count covered',
            'empty' => 'No tickets in scope.',
            'row' => ':status · opened :elapsed',
            'view' => 'View',
        ],
    ],

    'conversation' => [
        'document_title' => 'Conversation Transcript',
        'back' => 'Back to grant',
        'summary' => 'Read-only transcript · :site · access expires :elapsed.',
        'transcript' => [
            'heading' => 'Transcript',
            'count' => '{1} :count message|[2,*] :count messages',
            'empty' => 'No messages in this conversation.',
            'message_heading' => ':sender · :time',
        ],
        'senders' => [
            'visitor' => 'Visitor',
            'agent' => ':name (agent)',
            'integration' => ':name (integration)',
            'system' => 'System',
        ],
        'attachment' => [
            'summary' => 'Attachment: :filename (:mime, :size KB, scan: :scan)',
            'boundary' => 'names and sizes only; operator access never opens a file.',
        ],
        'tickets' => [
            'heading' => 'Tickets from this conversation',
            'count' => '{1} :count linked|[2,*] :count linked',
            'view' => 'View',
        ],
    ],

    'ticket' => [
        'document_title' => 'Ticket Record',
        'reference' => 'Ticket #:id',
        'back' => 'Back to grant',
        'heading' => 'Ticket #:id — :subject',
        'summary' => 'Read-only · :site · access expires :elapsed.',
        'record' => [
            'heading' => 'Ticket record',
            'status' => 'Status',
            'priority' => 'Priority',
            'category' => 'Category',
            'opened' => 'Opened',
            'conversation' => 'Conversation',
            'out_of_scope' => '(out of scope)',
        ],
    ],

    'values' => [
        'not_set' => '—',
        'not_available' => 'n/a',
    ],

    'flash' => [
        'requested' => 'Access requested for :scope. The account decides.',
        'requested_generic' => 'Access requested. The account decides.',
        'self_approved' => 'Self-approved — access to :scope until :until.',
        'self_approved_generic' => 'Access self-approved.',
        'already_expired' => 'That grant had already expired; it is recorded as expired.',
        'closed' => 'Grant closed. Access is revoked.',
    ],

    'validation' => [
        'account_required' => 'Choose an account for account-wide access.',
        'site_required' => 'Choose a site for site access.',
        'support_code_required' => 'Enter a support code for conversation access.',
        'conversation_not_found' => 'No conversation was found for that support code.',
    ],

    'errors' => [
        'grant_not_active' => 'This grant is not active.',
        'not_awaiting_approval' => 'This grant is not awaiting approval.',
        'self_approval_requires_standing' => 'Self-approval requires owner or admin standing on the target account.',
        'account_decides' => 'This account has an owner or admin, so they decide. Your request is waiting with them.',
        'only_active_can_close' => 'Only an active grant can be closed.',
    ],
];
