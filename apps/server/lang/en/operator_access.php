<?php

/*
 * The account-facing operator-access page.
 *
 * Scope identifiers, operator/admin names and access reasons are account data,
 * not dashboard copy. The controller hands those to the view separately and
 * the view marks each one with an unknown language. Scope types, lifecycle
 * states, relative times and counts are product vocabulary and live here.
 *
 * Flash messages keep their key and semantic scope/time context across the
 * redirect. The GET request translates them so the language and clock belong
 * to the page that actually renders the result.
 */

return [
    'document_title' => 'Operator Access',
    'title' => 'Operator access',
    'subtitle' => 'When a platform operator needs to see this account’s support data, they have to ask. Approve, refuse, or end access here.',
    'back' => 'Back to account',

    'counts' => [
        'active' => '{1} :count active grant|[2,*] :count active grants',
        'pending' => '{1} :count pending request|[2,*] :count pending requests',
        'open' => '{1} :count open grant|[2,*] :count open grants',
        'shown' => '{1} :count grant shown|[2,*] :count grants shown',
    ],

    'pending' => [
        'heading' => 'Awaiting your approval',
        'empty' => 'No pending requests. A platform operator can only reach this account’s support content through a request on this page.',
        'approve' => 'Approve',
        'deny' => 'Deny',
    ],

    'active' => [
        'heading' => 'Active grants',
        'empty' => 'No operator can see this account’s support content right now.',
        'revoke' => 'Revoke now',
    ],

    'history' => [
        'heading' => 'Past grants',
        'empty' => 'No past grants.',
    ],

    'grant' => [
        'pending_summary' => ':scope · :duration · read-only',
        'minutes' => '{1} :count minute|[2,*] :count minutes',
        'requester_reason' => ':requester: :reason',
        'requested' => 'Requested :elapsed',
        'active_summary' => ':scope — expires :elapsed',
        'self_approved_at' => 'Self-approved (no other admin existed) :elapsed',
        'self_approved' => 'Self-approved (no other admin existed)',
        'approved_by_at' => 'Approved by :approver :elapsed',
        'approved_by' => 'Approved by :approver',
        'past_summary' => ':scope — :status',
        'requested_self_approved' => 'Requested :elapsed · self-approved',
    ],

    'people' => [
        'former_operator' => 'Former operator',
        'former_admin' => 'a former admin',
    ],

    'scopes' => [
        'conversation' => 'Conversation',
        'conversation_deleted' => 'Conversation (deleted)',
        'conversation_out_of_scope' => 'Conversation (out of scope)',
        'site' => 'Site',
        'site_deleted' => 'Site (deleted)',
        'site_out_of_scope' => 'Site (out of scope)',
        'account' => 'Entire account',
        'other' => 'Scope',
    ],

    'statuses' => [
        'awaiting_approval' => 'Awaiting approval',
        'active' => 'Active',
        'denied' => 'Denied',
        'closed_early' => 'Closed early',
        'expired' => 'Expired',
    ],

    'flash' => [
        'approved' => 'Access approved until :until: :scope.',
        'approved_generic' => 'Access approved.',
        'denied' => 'Request denied. No access was granted.',
        'already_expired' => 'That grant had already expired; it is recorded as expired.',
        'closed' => 'Grant closed. Access is revoked.',
    ],
];
