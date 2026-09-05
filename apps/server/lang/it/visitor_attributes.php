<?php

/* Bozza da lang/en/visitor_attributes.php. NON ANCORA REVISIONATA. */
return [
    'document_title' => 'Attributi dei visitatori',
    'heading' => 'Attributi dei visitatori',
    'subtitle' => 'Trasforma il contesto selezionato dell’host in dati di contatto denominati e tipizzati che gli agenti possono comprendere e filtrare.',
    'back' => 'Torna all’account',
    'boundary' => [
        'heading' => 'Confine dei dati',
        'lede' => 'Le definizioni non raccolgono nuovi dati',
        'body' => 'Una definizione interpreta solo una chiave di contesto sicura già inviata dal sito host. Mantenga l’elenco breve, previsto e coperto dall’informativa sulla privacy del sito.',
        'delete' => 'L’eliminazione di una definizione rimuove l’etichetta e il filtro. Non elimina silenziosamente il valore sottostante del contesto host.',
    ],
    'fields' => [
        'key' => 'Chiave del contesto host',
        'key_help' => 'Usi lettere minuscole, numeri e trattini bassi. Il primo carattere deve essere una lettera.',
        'immutable_key' => 'La chiave resta fissa affinché i valori esistenti rimangano collegati alla stessa definizione.',
        'label' => 'Etichetta per gli agenti',
        'label_placeholder' => 'Piano',
        'type' => 'Tipo di valore',
        'type_help' => 'La modifica del tipo non riscrive i valori memorizzati. I valori non compatibili appaiono come non impostati.',
    ],
    'types' => ['text' => 'Testo', 'number' => 'Numero', 'boolean' => 'Sì o no', 'date' => 'Data'],
    'create' => ['heading' => 'Definisci un attributo', 'lede' => 'Fino a :count definizioni per account', 'submit' => 'Definisci attributo'],
    'existing' => [
        'heading' => 'Attributi definiti',
        'count' => '{0} Nessuna definizione|{1} :count definizione|[2,*] :count definizioni',
        'empty' => 'Non è stato ancora definito alcun attributo dei visitatori.',
        'save' => 'Salva definizione',
        'delete' => 'Elimina definizione',
    ],
    'flash' => [
        'created' => 'Attributo del visitatore definito.',
        'updated' => 'Definizione dell’attributo aggiornata.',
        'deleted' => 'Definizione dell’attributo eliminata. Il contesto host memorizzato è rimasto invariato.',
    ],
    'errors' => [
        'duplicate' => 'Questa chiave del contesto host è già definita.',
        'limit' => 'Un account può definire fino a :count attributi dei visitatori.',
        'unsafe_key' => 'Scelga una chiave del contesto host non sensibile. I campi di identità, autenticazione, pagamento e indirizzo non sono accettati qui.',
    ],

    'filters' => [
        'attribute' => 'Attributo',
        'any_attribute' => 'Qualsiasi attributo',
        'value' => 'Valore esatto',
        'value_placeholder' => 'Valore da trovare',
        'help' => 'I valori degli attributi corrispondono esattamente dopo l’applicazione del tipo configurato.',
        'invalid' => 'Inserisca un valore compatibile con il tipo di attributo selezionato.',
        'manage' => 'Gestisci attributi dei visitatori',
    ],

    'profile' => [
        'heading' => 'Dettagli definiti',
        'lede' => 'Contesto host sicuro, denominato per questo account',
        'attribute' => 'Attributo',
        'value' => 'Valore',
        'not_set' => 'Non impostato',
        'yes' => 'Sì',
        'no' => 'No',
        'manage' => 'Gestisci definizioni',
    ],
];
