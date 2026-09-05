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
        'timezone' => 'Time zone',
        'timezone_default' => 'Use the install default',
        'timezone_help' => 'Times and dates across the dashboard are shown in this zone, including which day a report groups something under.',
        'save' => 'Save profile',
    ],

    'routing' => [
        'heading' => 'Assignment availability',
        'label' => 'Automatic assignment status',
        'online' => 'Online',
        'away' => 'Away',
        'help' => 'Online lets enabled sites route new work to you while you have room. Away stops new automatic assignments; work already assigned to you stays put.',
        'save' => 'Save availability',
    ],

    'readiness' => [
        'heading' => 'Alert readiness',
        'lede' => 'Your current support signal path',
    ],

    'readiness_cards' => [
        'dashboard_label' => 'Dashboard alerts',
        'paused' => 'Paused',
        'quiet_detail' => 'Quiet mode suppresses new dashboard and email alerts.',
        'listening' => 'Listening',
        'listening_detail' => 'You will receive dashboard alerts for eligible support work.',
        'scope_label' => 'Alert scope',
        'scope_assigned' => 'Assigned to me',
        'scope_assigned_detail' => 'Only conversations and tickets assigned to you create new alerts.',
        'scope_quiet' => 'Quiet mode',
        'scope_quiet_detail' => 'Your scope is paused until quiet mode is turned off.',
        'scope_all' => 'All support work',
        'scope_all_detail' => 'Conversations and tickets you can support can create new alerts.',
        'email_label' => 'Email delivery',
        'email_off' => 'Dashboard only',
        'email_off_detail' => 'Email alerts are off for your profile.',
        'email_ready' => 'Ready',
        'email_ready_detail' => 'Email alerts are enabled and outbound mail looks configured.',
        'email_setup' => 'Needs setup',
        'cadence_label' => 'Cadence',
        'cadence_unattended' => 'Unattended only',
        'cadence_immediate' => 'Immediate',
        'cadence_immediate_detail' => 'New eligible alerts can notify immediately when email alerts are enabled.',
        'cadence_digest' => 'Digest',
        'cadence_digest_off_detail' => 'Digest preference is saved, but email alerts are off.',
        'cadence_unattended_detail' => 'Email goes out only when a visitor message stays unseen for :minutes minutes.',
        'cadence_unattended_off_detail' => 'Unattended preference is saved, but email alerts are off.',
        'cadence_digest_detail' => 'Digest delivery is preferred. Latest digest: :latest.',
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
        'sound_alerts' => 'Play a sound for new dashboard alerts',
        'sound_help' => 'A short local tone plays only while this dashboard is open in the background. Your browser may wait for you to interact with the page before allowing sound.',
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

    'digest' => [
        'no_alerts_message' => 'No digest-ready alerts found.',
        'failed_message' => 'Digest email could not be queued.',
        'never_message' => 'No digest run has been recorded yet.',
        'queued_message' => '{1} Queued digest email with :count alert.|[2,*] Queued digest email with :count alerts.',
        'queued_label' => 'Queued digest email',
        'no_alerts_label' => 'No digest-ready alerts',
        'failed_label' => 'Digest delivery failed',
        'never_label' => 'Not run yet',
    ],

    'flash' => [
        'profile_updated' => 'Profile updated.',
        'alerts_updated' => 'Alert preferences updated.',
        'password_updated' => 'Password updated.',
        'routing_status_updated' => 'Assignment availability updated.',
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
