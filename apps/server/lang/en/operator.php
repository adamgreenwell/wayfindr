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

    'scanning' => [
        'document_title' => 'Scanning settings',
        'title' => 'Attachment scanning',
        'subtitle' => 'Scan uploaded files for malware before they are stored. Changes apply immediately, no restart.',
        'heading' => 'Malware scanner',
        'lede' => 'Without a scanner, uploads are still accepted with defense-in-depth: a byte-sniffed type allowlist, private storage, forced-download disposition, and nosniff — but not virus-scanned.',
        'driver' => 'Scanner',
        'external_driver_help' => 'The current scanner is configured in the environment: :driver. Saving other settings will preserve it.',
        'none' => 'None (accept with defense-in-depth)',
        'driver_help' => 'ClamAV runs locally, so files never leave the server to be scanned. Choose it and set the clamd socket below.',
        'socket' => 'ClamAV socket',
        'socket_help' => 'A TCP address (:tcp) or a Unix socket (:unix) for the running clamd.',
        'fail_closed' => 'Reject uploads when the scanner is unreachable (fail-closed — recommended). Unchecked, uploads are accepted unscanned if the scanner is down.',
        'save' => 'Save scanning settings',
        'test_heading' => 'Test reachability',
        'test_lede' => 'Confirm the configured scanner is running and responds — no terminal needed.',
        'test' => 'Test scanner',
        'flash' => [
            'saved' => 'Scanning settings saved. Run a reachability test to confirm the scanner responds.',
            'none' => 'No scanner is configured — uploads are accepted with defense-in-depth (type allowlist, private storage, forced download) but not virus-scanned. Choose ClamAV and save to enable scanning.',
            'misconfigured' => 'Scanner is misconfigured: :message',
            'reachable' => 'Scanner reachable: the :driver scanner responded. Uploads will be scanned before they are stored.',
            'unreachable' => 'The :driver scanner is configured but unreachable at :socket. Confirm clamd is running and the socket is correct.',
        ],
    ],
];
