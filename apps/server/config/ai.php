<?php

return [
    // Product code always addresses one stable provider name. The driver below
    // is operator-controlled, so changing vendors never leaks into a feature.
    'default' => 'wayfindr',

    'providers' => [
        'wayfindr' => [
            'driver' => env('WAYFINDR_AI_PROVIDER', ''),
            'key' => env('WAYFINDR_AI_API_KEY'),
            'url' => env('WAYFINDR_AI_ENDPOINT'),

            // Provider-side retention is not part of an assistive request.
            // Drivers that understand this option therefore receive false.
            'store' => false,

            'models' => [
                'text' => [
                    'default' => env('WAYFINDR_AI_MODEL'),
                ],
            ],
        ],
    ],
];
