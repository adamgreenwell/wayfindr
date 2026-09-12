<?php

return [
    // The 429 view answers every html 429 in the product, including throttled
    // routes inside the authenticated dashboard where SetDashboardLocale has
    // already resolved the agent's own language. Hard-coded English there would
    // sit inside a document declaring `lang="de"`, which makes a screen reader
    // pronounce English words with German phonetics. Before sign-in the same
    // keys resolve to English, because those routes are not extracted.
    'throttled' => [
        'document_title' => 'Too many attempts',
        'heading' => 'Too many attempts',
        'lede' => 'Wait a moment, then try again.',
        'retry_in' => '{1} Try again in about :count minute.|[2,*] Try again in about :count minutes.',
        'retry_soon' => 'Try again shortly.',
        'scope' => 'This limit counts how often the attempt can be made. It does not lock your account or change anything you have already saved.',
        'back_dashboard' => 'Back to the dashboard',
        'back_sign_in' => 'Back to sign in',
    ],
];
