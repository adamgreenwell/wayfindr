<?php

return [
    'documentation' => [
        'forge_url' => env('WAYFINDR_FORGE_DOCS_URL', 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/laravel-forge.md'),
        'runtime_requirements_url' => env('WAYFINDR_RUNTIME_REQUIREMENTS_DOCS_URL', 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/runtime-requirements.md'),
        'self_hosting_url' => env('WAYFINDR_SELF_HOSTING_DOCS_URL', 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/self-hosting/install.md'),
    ],

    'data_responsibility' => [
        'label' => 'Operator reminder',
        'message' => 'Retaining visitor-supplied data may create privacy, security, and legal obligations.',
        'guidance' => 'Keep only what you need, set a retention period you can justify, and make sure your privacy notice matches how this Wayfindr installation is used.',
        'docs_url' => 'https://github.com/adamgreenwell/wayfindr/blob/main/docs/privacy/data-responsibility.md',
    ],

    'release' => [
        'commit' => env('WAYFINDR_COMMIT'),
        'version' => env('WAYFINDR_VERSION'),
    ],
];
