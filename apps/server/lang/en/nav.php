<?php

/*
 * The application shell: the rail, the topbar, and the things on them that are
 * present on every screen.
 *
 * Structure carried over from PR #782, which extracted this surface against a
 * seam that did not survive. The keys were well chosen and are kept rather than
 * reinvented; `theme`, `search.help` and the region labels are new, because a
 * catalogue built from the Blade file alone missed them.
 *
 * "Wayfindr" is deliberately absent. A product name is not copy.
 */
return [
    'groups' => [
        'work' => 'Work',
        'manage' => 'Manage',
    ],

    'items' => [
        'dashboard' => 'Dashboard',
        'conversations' => 'Conversations',
        'tickets' => 'Tickets',
        'alerts' => 'Alerts',
        'visitors' => 'Visitors',
        'reports' => 'Reports',
        'sites' => 'Sites',
        'account' => 'Account',
        'operator' => 'Operator',
    ],

    'regions' => [
        'primary' => 'Primary navigation',
        'breadcrumb' => 'Breadcrumb',
        'search' => 'Find support trail',
        'theme' => 'Colour theme',
    ],

    'theme' => [
        'system' => 'Auto',
        'light' => 'Light',
        'dark' => 'Dark',
    ],

    'search' => [
        'label' => 'Support code, ticket, or visitor ID',
        'placeholder' => 'Support code, ticket, visitor',
        'submit' => 'Find',
        'help' => 'Try a support code like WF-ABC123, a ticket reference like Ticket #123, or a visitor ID.',
        'scope' => 'Records outside your support access stay hidden.',
    ],

    'commands' => [
        'open' => 'Commands',
        'eyebrow' => 'Agent workspace',
        'title' => 'Command palette',
        'dismiss' => 'Close command palette',
        'search_label' => 'Find a command',
        'search_placeholder' => 'Type an action or destination',
        'current' => 'Current page',
        'empty' => 'No commands match that search.',
        'groups' => [
            'actions' => 'On this page',
            'navigation' => 'Navigation',
        ],
        'actions' => [
            'next' => 'Next item',
            'previous' => 'Previous item',
            'open' => 'Open item',
            'claim' => 'Claim',
            'reply' => 'Reply',
            'close' => 'Close',
            'search' => 'Search',
        ],
    ],

    'sign_out' => 'Sign out',
];
