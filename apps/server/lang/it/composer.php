<?php

/*
 * Drafted from lang/en/composer.php. NOT YET REVIEWED.
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
 */
return [
    'sending' => 'Invio...',
    'sending_reply' => 'Invio della risposta...',
    'sending_visitor_reply' => 'Invio della risposta del visitatore...',
    'uploading' => 'Caricamento in corso…',
    'attach_failed' => 'Impossibile allegare quel file.',
    'waiting_uploads' => 'In attesa che i caricamenti siano completati…',
    'remove' => 'Rimuovi :name',
    'attachment' => 'allegato',
    'rejected' => [
        'too_many' => 'Un messaggio può includere al massimo :max allegati.',
        'unreadable' => 'Impossibile leggere il file.',
        'too_large' => 'Il file supera il limite :limit.',
        'type' => 'Questo tipo di file non è consentito.',
        'conversation_full' => 'Questa conversazione ha raggiunto il limite di spazio per gli allegati.',
        'infected' => 'Questo file è stato rifiutato da una scansione di sicurezza.',
        'unscannable' => 'Questo file non può essere analizzato per la presenza di malware e non è stato accettato. Riprova tra poco.',
        'unavailable' => 'Uno o più allegati non sono disponibili.',
    ],
];
