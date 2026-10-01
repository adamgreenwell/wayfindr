<?php

/* Bozza da lang/en/visitor_export.php. Revisionata secondo la sezione 9 di docs/product/translation-policy.md. */
return [
    'section' => [
        'heading' => 'Esporta questo contatto',
        'lede' => 'Per una richiesta di vedere tutti i dati conservati su questa persona',
        'body' => 'Scarichi uno ZIP con tutto ciò che Wayfindr conserva su questo contatto: identità, conversazioni, file e ticket, gli avvisi e le voci di audit che lo riguardano, e il testo conservato di passaggio che lo cita. Gli agenti sono indicati per ruolo, non per nome. Lo verifichi prima di inviarlo: include le note sul contatto, che la normativa applicabile potrebbe consentirle di trattenere.',
        'link' => 'Scarica esportazione',
    ],
    'before_erasing' => 'Se la persona ha chiesto anche una copia dei propri dati, la scarichi prima di cancellarla: la cancellazione non è reversibile.',
    'errors' => [
        'gone' => 'Questo contatto è stato cancellato o unito a un altro mentre l’esportazione veniva preparata, quindi non è stato scaricato nulla.',
        'too_large' => 'La cronologia di questo contatto supera ciò che un archivio di esportazione può contenere, quindi non è stato scaricato nulla.',
    ],
    'readme' => [
        'title' => 'Tutto ciò che Wayfindr conserva su un contatto',
        'intro' => 'Questo archivio è stato esportato da Wayfindr, una piattaforma di assistenza clienti, per rispondere a una richiesta della persona che descrive. Ogni file descrive lo stesso momento.',
        'moment' => 'Esportato il :time (UTC).',
        'contents_heading' => 'Cosa contiene',
        'contents' => [
            'visitor.json: la scheda del contatto con tutti gli attributi e l’ultimo indirizzo di pagina, i suoi ID browser e le note del team sul contatto.',
            'conversations/: un file per conversazione, con messaggi, valutazioni, dettagli dei file, sessioni di cobrowse, risultati dell’assistente IA e le risposte inviate via email.',
            'cobrowse.json: le sue sessioni di cobrowse in una conversazione non sua, se ce ne sono.',
            'proactive.json: i messaggi proattivi mostrati alla persona, e quando ciascuno è stato mostrato, utilizzato o chiuso.',
            'tickets/: i suoi ticket, con le note, dove è stata inviata ciascuna nota e con quale esito, e le issue collegate.',
            'alerts.json: gli avvisi ricevuti dagli agenti che la riguardano.',
            'audit.json: la cronologia di ciò che è accaduto alla persona, alle sue conversazioni e ai suoi ticket.',
            'incidental.json: il testo conservato di passaggio che può citarla: risposte dei webhook, errori delle automazioni, ricerche salvate nella coda e job non riusciti.',
            'break_glass.json: gli accessi del gestore della piattaforma ai suoi dati, con la motivazione.',
            'attachments/: i file condivisi nelle sue conversazioni.',
        ],
        'roles' => 'Le persone sono indicate per ruolo, non per nome: «visitor» è la persona stessa, mentre «agent», «platform operator», «integration» e «system» indicano chi altro ha agito. L’identità del personale coinvolto è un dato che riguarda il personale stesso; l’installazione la aggiunge dove la legge richiede di indicare chi ha visto i dati.',
        'review' => 'Il gestore dell’installazione verifica questo archivio prima di inviarlo e può rimuovere tutto ciò che la normativa applicabile gli consente di trattenere.',
        'not_reached_heading' => 'Cosa questo archivio non può contenere',
        'not_reached' => [
            'i backup;',
            'i sistemi esterni di gestione delle issue;',
            'le email già inviate;',
            'ciò che il fornitore IA ha conservato;',
            'ciò che le integrazioni API hanno già recuperato;',
            'i file caricati da un agente per una risposta non ancora inviata;',
            'i log, il testo dei job non riusciti che non riporta uno dei suoi codici di supporto e l’infrastruttura del gestore.',
        ],
        'counts_heading' => 'Quanto contiene',
        'pruned' => 'Questi file sono stati rimossi dalla conservazione dopo l’avvio dell’esportazione, quindi sono elencati ma non inclusi:',
        'withheld' => 'Questi file non sono stati caricati completamente o sono trattenuti dallo scanner antimalware, quindi sono elencati ma non inclusi:',
    ],
];
