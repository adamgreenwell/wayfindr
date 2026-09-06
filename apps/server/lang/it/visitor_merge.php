<?php

/* Bozza da lang/en/visitor_merge.php. NON ANCORA REVISIONATA. */
return [
    'heading' => 'Unisci contatto duplicato',
    'lede' => 'Scelga l’unico contatto che il team deve conservare',
    'boundary' => [
        'heading' => 'Decisione permanente sull’identità',
        'body' => 'Il contatto attuale verrà eliminato dopo che conversazioni, ticket, note sul contatto, caricamenti e ID browser saranno spostati nel contatto scelto.',
        'precedence' => 'Il contatto scelto conserva i valori di identità e degli attributi personalizzati già compilati; il contatto attuale riempie i campi vuoti. Non è possibile unire ID visitatore host o indirizzi email differenti già compilati.',
        'continuity' => 'I vecchi ID browser restano alias privati del contatto scelto, così le schede aperte e i browser che tornano non ricreano il duplicato.',
    ],
    'search' => [
        'label' => 'Trova il contatto da conservare',
        'placeholder' => 'Nome, email, ID host o ID browser',
        'submit' => 'Trova contatti',
        'clear' => 'Cancella',
        'empty' => 'Nessun altro contatto di questo sito corrisponde alla ricerca.',
        'limit' => 'Vengono mostrati fino a 10 risultati di questo sito.',
    ],
    'candidate' => [
        'contact' => 'Contatto da conservare',
        'email' => 'Email',
        'host_id' => 'ID visitatore host',
        'browser_id' => 'ID browser',
        'last_seen' => 'Ultima visualizzazione',
        'not_provided' => 'Non fornito',
        'confirm' => 'Ho verificato che si tratta della stessa persona e comprendo che l’unione non può essere annullata.',
        'submit' => 'Unisci in questo contatto',
    ],
    'errors' => [
        'target_required' => 'Scelga un contatto valido da conservare.',
        'same_contact' => 'Scelga un contatto diverso da conservare.',
        'external_id_conflict' => 'Questi contatti hanno ID visitatore host differenti. Risolva il conflitto di identità prima di unirli.',
        'email_conflict' => 'Questi contatti hanno indirizzi email differenti. Risolva il conflitto di identità prima di unirli.',
        'alias_conflict' => 'Un ID browser appartiene già a un altro contatto. Nessun record è stato modificato.',
    ],
    'flash' => [
        'merged' => 'Contatto duplicato unito. La cronologia di assistenza e gli ID browser ora appartengono a questo contatto.',
    ],
];
