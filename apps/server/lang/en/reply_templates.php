<?php

/*
 * The account reply-templates page.
 *
 * An agent reaches this from the reply composer on a conversation, which
 * already speaks their language -- so before this catalogue existed, choosing
 * German meant a German conversation and an English page one click away.
 *
 * Every string on that page lives here, including the three flash messages.
 * Those travel from the controller as KEYS rather than sentences, because the
 * request that redirects and the request that renders are different requests
 * and the agent's language is resolved per request. See
 * `AgentProfileController::update()` for the same reasoning where it was first
 * written down.
 */

return [
    'title' => 'Reply templates',
    'subtitle' => 'Manage account-wide helper replies for common visitor updates.',
    'back' => 'Back to account',

    'flash' => [
        'created' => 'Reply template created.',
        'updated' => 'Reply template updated.',
        'archived' => 'Reply template archived.',
    ],

    'standards' => [
        'heading' => 'Template standards',
        'lede' => 'Reusable, safe, and still human.',
        'calm' => 'Treat templates as calm starting points, not scripts agents must send unchanged.',
        'use_for' => 'Use templates for acknowledgements, status updates, next steps, and common clarification requests.',
        'keep_out' => 'Keep visitor-visible templates free of passwords, payment details, private handoff notes, and promises your team cannot keep.',
    ],

    'create' => [
        'heading' => 'Create template',
        'lede' => 'Short, reusable, and still editable before send.',
        'name' => 'Template name',
        // A worked example rather than an instruction, so the shape of a good
        // name is visible without reading anything.
        'name_placeholder' => 'Billing follow-up',
        'body' => 'Reply body',
        'body_placeholder' => 'Thanks for the update. I am checking this now and will follow up shortly.',
        'submit' => 'Create template',
    ],

    'list' => [
        'heading' => 'Templates',
        // `:count` arrives already grouped for the reader -- `1.234` for a
        // German agent -- so this must not group it again.
        'total' => ':count total',
        'column_template' => 'Template',
        'column_body' => 'Body',
        'column_status' => 'Status',
        'column_manage' => 'Manage',
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'empty' => [
        'heading' => 'No managed reply templates yet.',
        'body' => 'Built-in helpers stay available in reply composers until your team adds account templates. Add one when agents keep rewriting the same calm, useful answer.',
        'action' => 'Create the first template',
    ],

    'manage' => [
        'name' => 'Name',
        'body' => 'Body',
        'save' => 'Save template',
        'archive' => 'Archive',
        'archived_note' => 'Archived templates stay out of reply helpers.',
    ],

    'validation' => [
        'name' => 'Please name this reply template.',
        'body' => 'Please add a reply body.',
    ],
];
