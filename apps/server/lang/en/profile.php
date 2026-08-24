<?php

return [
    // The browser tab and breadcrumb. Shipped as "Agent Profile" while the
    // page heading says "Agent profile" -- the same page named two ways on one
    // screen. Preserved exactly rather than tidied, because an extraction that
    // quietly edits copy is an extraction nobody can trust; filed to be fixed
    // on purpose instead.
    'document_title' => 'Agent Profile',
    'title' => 'Agent profile',
    'subtitle' => 'Keep your agent identity and sign-in password current.',

    'context' => [
        'email' => 'Email',
        'account' => 'Account',
        'role' => 'Role',
        'member_since' => 'Member since',
        'member_since_unknown' => 'Unknown',
    ],

    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'agent' => 'Agent',
    ],

    'details' => [
        'heading' => 'Your profile',
        'lede' => 'Your name, and the language you read this in',
        'name' => 'Name',
        'email_help' => 'Your email is used for sign-in. Ask an owner if it needs changed.',
        'language' => 'Dashboard language',
        'language_default' => 'Use the install default',
        'language_help' => 'Yours alone. It changes the dashboard for you and nobody else, and does not affect what language the widget speaks to your visitors — that is set per site.',
        'save' => 'Save profile',
    ],

    'readiness' => [
        'heading' => 'Alert readiness',
        'lede' => 'Your current support signal path',
    ],

    'alerts' => [
        'heading' => 'Alert preferences',
        'lede' => 'Keep support signals useful',
        'guidance_heading' => 'How alerts behave',
        'guidance_dashboard' => 'Dashboard alerts are the source of truth for support work that needs attention.',
        'guidance_email' => 'Email alerts are optional delivery, not a separate queue.',
        'guidance_quiet' => 'Quiet mode pauses new alerts without changing assignments, site access, or support responsibility.',
        'mode' => 'Alert mode',
        'email_alerts' => 'Email alerts',
        'cadence' => 'Email cadence',
        'cadence_help' => 'Digest delivery bundles eligible email alerts when the scheduler runs. Unattended only emails when a visitor message waits unseen. Dashboard alerts stay immediate.',
        'last_digest' => 'Last digest',
        'email_help' => 'Email alerts send the same calm support signals to your inbox when mail is configured. Quiet mode still suppresses new alerts.',
        'delivery_ready' => 'Email delivery ready',
        'delivery_attention' => 'Email delivery needs attention',
        'save' => 'Save alert preferences',

        'modes' => [
            'all' => 'All site alerts I can support',
            'assigned' => 'Only conversations and tickets assigned to me',
            'quiet' => 'Quiet mode',
        ],

        'cadences' => [
            'immediate' => 'Send email alerts as they happen',
            'unattended' => 'Email only when a visitor waits unseen',
            'digest' => 'Prefer digest delivery when available',
        ],
    ],

    'password' => [
        'heading' => 'Change password',
        'lede' => 'Use this after receiving a temporary password',
        'current' => 'Current password',
        'new' => 'New password',
        'confirm' => 'Confirm new password',
        'save' => 'Update password',
    ],
];
