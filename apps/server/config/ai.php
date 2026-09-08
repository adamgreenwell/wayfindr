<?php

return [
    // Product code always addresses one stable provider name. The driver below
    // is operator-controlled, so changing vendors never leaks into a feature.
    'default' => 'wayfindr',

    'providers' => [
        'wayfindr' => [
            'driver' => env('WAYFINDR_AI_PROVIDER', ''),
            'key' => env('WAYFINDR_AI_API_KEY'),
            // The SDK falls back to each hosted provider's default URL only
            // when this is null; an empty env value would create a relative
            // `chat/completions` request instead.
            'url' => env('WAYFINDR_AI_ENDPOINT') ?: null,

            // OpenRouter calls are pinned to one named upstream endpoint. The
            // agent adds ZDR and disables fallback routing on every request.
            'openrouter' => [
                'provider' => env('WAYFINDR_AI_OPENROUTER_PROVIDER'),
            ],

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
