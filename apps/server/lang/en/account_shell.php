<?php

/*
 * The account area's context sidebar: the second navigation column beside
 * every page under /dashboard/account, modelled on the operator console's.
 *
 * Group names are short headings above a run of links, and destination names
 * are the links themselves -- each one names the page it opens, in the words
 * that page and the account overview already use for it, so the sidebar never
 * calls a destination something its own title does not.
 */

return [
    'sections_label' => 'Account sections',

    'groups' => [
        'account' => 'Account',
        'content' => 'Support content',
        'workflow' => 'Workflow',
        'connections' => 'Connections',
        'oversight' => 'Oversight',
    ],

    'sections' => [
        'overview' => 'Overview',
        'roles' => 'Roles',
        'security' => 'Security',
        'articles' => 'Articles',
        'reply_templates' => 'Reply templates',
        'labels' => 'Ticket labels',
        'visitor_attributes' => 'Visitor attributes',
        'automations' => 'Automations',
        'sla_policies' => 'SLA policies',
        'integrations' => 'Integrations',
        'api' => 'API and webhooks',
        'audit' => 'Audit log',
        'operator_access' => 'Operator access',
    ],
];
