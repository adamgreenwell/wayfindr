<?php

/*
 * The sites directory and new-site form.
 *
 * Site, domain, account and agent names, page URLs, and search terms are
 * authored data. The views mark those values with an unknown language instead
 * of sending them through this catalogue.
 *
 * Counts are complete plural phrases, never fragments. Callers pass the raw
 * count to `trans_choice` and a ReaderNumber-formatted value as `:count`.
 */
return [
    'document_title' => 'Sites',
    'title' => 'Sites',
    'subtitle' => 'Manage widget installs, support access, privacy rules, and issue routing.',
    'add_site' => 'Add site',

    'flash' => [
        'created' => 'Site created. Copy the install snippet to finish connecting it.',
    ],

    'index' => [
        'snapshot' => [
            'heading' => 'Site operations snapshot',
            'lede' => 'A quick read on the sites your support role can currently reach.',
            'aria' => 'Site operations metrics',
            'visible' => [
                'label' => 'Visible sites',
                'value' => '{1} :count visible site|[0,*] :count visible sites',
                'detail' => 'Visible to your support role before filters.',
                'action' => 'Review sites',
            ],
            'workload' => [
                'label' => 'Active support work',
                'value' => '{1} :count active site|[0,*] :count active sites',
                'detail' => ':conversations, :open_tickets, :pending_tickets across visible sites.',
                'action' => 'Review active sites',
            ],
            'install' => [
                'label' => 'Install attention',
                'value' => '{1} :count site needs install attention|[0,*] :count sites need install attention',
                'detail' => 'Widget installs that have not checked in recently or have not reported yet.',
                'action' => 'Review installs',
            ],
            'access' => [
                'label' => 'Support access',
                'value' => '{1} :count site with explicit access|[0,*] :count sites with explicit access',
                'detail' => '{1} :count uses account-wide fallback.|[0,*] :count use account-wide fallback.',
            ],
        ],

        'filters' => [
            'heading' => 'Site filters',
            'lede' => 'Narrow connected sites by support work, install health, or name.',
            'clear' => 'Clear filters',
            'search' => 'Search',
            'placeholder' => 'Site name or domain',
            'workload' => 'Workload',
            'install' => 'Install',
            'install_health' => 'Install health',
            'state' => 'State',
            'apply' => 'Apply filters',
            'active_aria' => 'Active site filters',
            'filtered' => 'Filtered sites',
            'all_visible' => 'All visible sites',
            'none' => 'No filters applied',
            'options' => [
                'workload' => [
                    'all' => 'All workloads',
                    'active' => 'Active support work',
                    'without_work' => 'Quiet',
                ],
                'install' => [
                    'all' => 'All install states',
                    'needs_attention' => 'Needs attention',
                    'live' => 'Live',
                ],
                'state' => [
                    'active_sites' => 'Active sites',
                    'archived' => 'Archived',
                    'all' => 'All states',
                ],
            ],
            'summary' => [
                'shown' => '{1} :shown shown of :visible visible|[0,*] :shown shown of :visible visible',
                'visible' => '{1} :count visible|[0,*] :count visible',
            ],
        ],

        'list' => [
            'heading' => 'Connected sites',
            'lede' => 'Visible to your support role',
            'open_tester' => 'Open tester',
            'columns' => [
                'site' => 'Site',
                'workload' => 'Workload',
                'access' => 'Access',
                'install_health' => 'Install health',
                'last_page' => 'Last page',
            ],
        ],

        'state' => [
            'archived' => 'Archived',
        ],
        'common' => [
            'not_set' => 'Not set',
            'not_reported' => 'Not reported',
        ],
        'counts' => [
            'open_conversations' => '{1} :count open conversation|[0,*] :count open conversations',
            'open_tickets' => '{1} :count open ticket|[0,*] :count open tickets',
            'pending_tickets' => '{1} :count pending ticket|[0,*] :count pending tickets',
            'assigned' => '{1} :count assigned|[0,*] :count assigned',
            'more' => '{1} + :count more|[0,*] + :count more',
        ],
        'workload' => [
            'none' => 'No active support work',
        ],
        'access' => [
            'explicit' => 'Explicit access',
            'assigned_support' => 'Assigned support',
            'fallback' => 'Account-wide fallback',
            'all_agents' => 'All account agents',
        ],
        'install' => [
            'not_installed' => 'Not installed',
            'no_check_in' => 'No check-in yet',
            'finish' => 'Finish install',
            'live' => 'Live',
            'needs_check' => 'Needs check',
            'seen' => 'Seen :elapsed',
            'review' => 'Review install',
        ],
        'empty' => [
            'actions' => [
                'clear_all' => 'Clear all site filters',
                'clear_search' => 'Clear search',
                'clear_install' => 'Clear install health filter',
                'clear_workload' => 'Clear workload filter',
                'back_to_active' => 'Back to active sites',
            ],
            'search' => [
                'heading' => 'No sites match ":search".',
                'detail' => 'Search checks site name and domain. Clear the search term or loosen the other site filters to review more visible sites.',
            ],
            'install_attention' => [
                'heading' => 'No sites need install attention right now.',
                'detail' => 'Every visible site has sent a recent widget signal. Clear the install health filter to review all connected sites.',
            ],
            'live' => [
                'heading' => 'No live widget installs match these filters.',
                'detail' => 'Try clearing the install health filter to see sites that still need their first widget signal.',
            ],
            'workload_active' => [
                'heading' => 'No sites have active support work right now.',
                'detail' => 'Clear the workload filter to include quiet sites that may still need install or access review.',
            ],
            'workload_quiet' => [
                'heading' => 'No quiet sites match these filters.',
                'detail' => 'Clear the workload filter to include sites with active conversations or tickets.',
            ],
            'archived' => [
                'heading' => 'No sites are archived.',
                'detail' => 'Archiving takes a site out of service without deleting anything, so it can be undone at any time. Nothing here means every site you can see is still serving its widget.',
            ],
            'default' => [
                'heading' => 'No sites are visible to you yet.',
                'detail' => 'Add the first site to get a public key and widget install snippet.',
            ],
        ],
    ],

    'create' => [
        'document_title' => 'Add Site',
        'title' => 'Add site',
        'subtitle' => 'Create a new Wayfindr install target for :account.',
        'back' => 'Back to dashboard',
        'details' => [
            'heading' => 'Site details',
            'public_key' => 'Public key generated automatically',
        ],
        'fields' => [
            'name' => 'Site name',
            'domain' => 'Domain',
            'domain_help' => 'You can paste a full URL here. Wayfindr stores the host name so the install target stays tidy.',
        ],
        'submit' => 'Create site',
    ],
];
