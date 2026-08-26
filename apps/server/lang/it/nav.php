<?php

/*
 * Drafted from lang/en/nav.php. NOT YET REVIEWED.
 *
 * Machine output against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md. Every value here is a
 * proposal: the pipeline optimises for a diff somebody can check, not for a
 * translation nobody has to.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * No plural strings in this catalogue.
 * /
 */
return [
    'groups' => [
        'work' => 'Lavoro',
        'manage' => 'Gestisci',
    ],
    'items' => [
        'dashboard' => 'Cruscotto',
        'conversations' => 'Conversazioni',
        'tickets' => 'Ticket',
        'alerts' => 'Avvisi',
        'visitors' => 'Visitatori',
        'reports' => 'Report',
        'sites' => 'Siti',
        'account' => 'Account',
        'operator' => 'Gestore',
    ],
    'regions' => [
        'primary' => 'Navigazione principale',
        'breadcrumb' => 'Percorso di navigazione',
        'search' => 'Trova percorso di supporto',
        'theme' => 'Tema colore',
    ],
    'theme' => [
        'system' => 'Automatico',
        'light' => 'Chiaro',
        'dark' => 'Scuro',
    ],
    'search' => [
        'label' => 'Codice di supporto, ticket o visitatore ID',
        'placeholder' => 'Codice di supporto, ticket, visitatore',
        'submit' => 'Trova',
        'help' => 'Provi con un codice di supporto come WF-ABC123, un riferimento ticket come Ticket #123 o un visitatore ID.',
        'scope' => 'I record al di fuori del suo accesso di supporto rimangono nascosti.',
    ],
    'sign_out' => 'Disconnetti',
];
