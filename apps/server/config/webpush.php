<?php

use App\Models\AgentPushSubscription;

return [
    // Environment values are the boot-safe baseline. Platform operators can
    // replace these three values through the encrypted settings store without
    // baking credentials into a cached config file.
    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => null,
    ],

    'model' => AgentPushSubscription::class,
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    'client_options' => [],
    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),
];
