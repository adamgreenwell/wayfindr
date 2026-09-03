<?php

return [
    'shell' => [
        'sections_label' => 'Operator sections',
        'heading' => 'Operator',
        'back' => 'Back',
        'back_to_setup' => 'Back to setup checklist',
        'back_to_console' => 'Back to operator console',
        'sections' => [
            'console' => 'Console',
            'onboarding' => 'Setup checklist',
            'mail' => 'Mail',
            'storage' => 'Storage',
            'scanning' => 'Scanning',
            'backups' => 'Backups',
            'localization' => 'Language and region',
            'operator_access' => 'Operator access',
        ],
    ],

    'localization' => [
        'document_title' => 'Language and region',
        'title' => 'Language and region',
        'subtitle' => 'What the dashboard reads in for anyone who has not chosen for themselves. Changes apply immediately, no restart.',
        'heading' => 'Install defaults',
        'lede' => 'These are defaults, not rules. An agent who picks their own language or timezone on their profile keeps it — this answers for everyone else, which on a new install is everyone.',
        'language' => 'Language',
        'language_help' => 'Applies to the agent dashboard. What a visitor sees in the widget is chosen from their own browser and is not affected by this.',
        'timezone' => 'Timezone',
        'timezone_help' => 'Times and report days are shown on this clock. Records are always stored in UTC, so changing this re-reads existing history rather than rewriting it.',
        'save' => 'Save language and region',
        'flash' => [
            'saved' => 'Language and region saved. Agents who have not chosen their own now read this.',
        ],
    ],
];
