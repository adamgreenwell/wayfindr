<?php

/*
 * Bozza tratta da lang/en/operator_break_glass.php. NON ANCORA REVISIONATA.
 * Scritta a mano seguendo il glossario e le regole di traduzione. I dati del
 * cliente restano fuori dal catalogo e le view li contrassegnano con una
 * lingua sconosciuta.
 */

return [
    'document_title' => 'Accesso del gestore',
    'title' => 'Accesso del gestore',
    'introduction' => 'Per impostazione predefinita non può vedere le conversazioni o i ticket di alcun account. Quando serve, richieda qui l’accesso a una conversazione, un sito o un account. L’account vede il motivo, approva o rifiuta la richiesta e può terminare l’accesso in qualsiasi momento. L’accesso è in sola lettura, scade automaticamente e ogni pagina aperta viene registrata per l’account.',

    'request' => [
        'heading' => 'Richiedi l’accesso',
        'subtitle' => 'Richieda solo l’ambito minimo necessario per rispondere alla domanda',
        'scope' => [
            'label' => 'Che cosa deve vedere?',
            'options' => [
                'conversation' => 'Una conversazione (codice di supporto)',
                'site' => 'Un sito',
                'account' => 'Intero account',
            ],
        ],
        'support_code' => [
            'label' => 'Codice di supporto',
            'help' => 'Compili questo campo se ha scelto una conversazione.',
        ],
        'site' => [
            'label' => 'Sito',
            'choose' => 'Scegli un sito',
            'help' => 'Compili questo campo se ha scelto un sito.',
        ],
        'account' => [
            'label' => 'Account',
            'choose' => 'Scegli un account',
            'help' => 'Compili questo campo se ha scelto un intero account.',
        ],
        'duration' => [
            'label' => 'Per quanto tempo le serve?',
            'options' => [
                'fifteen_minutes' => '15 minuti',
                'one_hour' => '1 ora',
                'four_hours' => '4 ore',
                'one_day_maximum' => '24 ore (massimo)',
            ],
        ],
        'reason' => [
            'label' => 'Perché le serve?',
            'placeholder' => 'Che cosa sta esaminando e perché la risposta richiede i contenuti di questo account?',
        ],
        'submit' => 'Richiedi l’accesso',
    ],

    'requests' => [
        'heading' => 'Le sue richieste',
        'count' => '{1} :count voce recente|[2,*] :count voci recenti',
        'empty' => 'Non ha ancora richiesto l’accesso ad alcun account. I dati di supporto restano chiusi ai gestori finché un account non li rende accessibili.',
        'scope_status' => 'Ambito: :scope — Stato: :status',
        'requested' => 'Richiesta :elapsed',
        'expires' => 'scade :elapsed',
        'waiting_on' => 'in attesa di :people',
        'waiting_on_fallback' => 'in attesa di un titolare o amministratore dell’account',
        'self_approve' => 'Autoapprova',
        'open' => 'Apri l’accesso',
        'close' => 'Termina ora',
    ],

    'grant' => [
        'document_title' => 'Accesso del gestore',
        'back' => 'Torna all’accesso del gestore',
        'summary' => 'Accesso in sola lettura fino alle :until (:elapsed). Ogni visualizzazione viene registrata ed è visibile a :account.',
        'conversations' => [
            'heading' => 'Conversazioni',
            'count' => '{1} :count inclusa|[2,*] :count incluse',
            'empty' => 'Nessuna conversazione nell’ambito.',
            'row' => ':site · iniziata :elapsed',
            'view' => 'Visualizza la trascrizione',
        ],
        'tickets' => [
            'heading' => 'Ticket',
            'count' => '{1} :count incluso|[2,*] :count inclusi',
            'empty' => 'Nessun ticket nell’ambito.',
            'row' => ':status · aperto :elapsed',
            'view' => 'Visualizza',
        ],
    ],

    'conversation' => [
        'document_title' => 'Trascrizione della conversazione',
        'back' => 'Torna alla concessione',
        'summary' => 'Trascrizione in sola lettura · :site · l’accesso scade :elapsed.',
        'transcript' => [
            'heading' => 'Trascrizione',
            'count' => '{1} :count messaggio|[2,*] :count messaggi',
            'empty' => 'Nessun messaggio in questa conversazione.',
            'message_heading' => 'Da :sender · :time',
        ],
        'senders' => [
            'visitor' => 'Visitatore',
            'agent' => ':name (agente)',
            'system' => 'Sistema',
        ],
        'attachment' => [
            'summary' => 'Allegato: :filename (:mime, :size KB, scansione: :scan)',
            'boundary' => 'solo nomi e dimensioni; l’accesso del gestore non apre mai un file.',
        ],
        'tickets' => [
            'heading' => 'Ticket da questa conversazione',
            'count' => '{1} :count collegato|[2,*] :count collegati',
            'view' => 'Visualizza',
        ],
    ],

    'ticket' => [
        'document_title' => 'Scheda del ticket',
        'reference' => 'Ticket n. :id',
        'back' => 'Torna alla concessione',
        'heading' => 'Ticket n. :id — :subject',
        'summary' => 'Sola lettura · :site · l’accesso scade :elapsed.',
        'record' => [
            'heading' => 'Scheda del ticket',
            'status' => 'Stato',
            'priority' => 'Priorità',
            'category' => 'Categoria',
            'opened' => 'Aperto',
            'conversation' => 'Conversazione',
            'out_of_scope' => '(fuori ambito)',
        ],
    ],

    'values' => [
        'not_set' => '—',
        'not_available' => 'n/d',
    ],

    'flash' => [
        'requested' => 'Accesso richiesto per :scope. Decide l’account.',
        'requested_generic' => 'Accesso richiesto. Decide l’account.',
        'self_approved' => 'Autoapprovato — accesso a :scope fino alle :until.',
        'self_approved_generic' => 'Accesso autoapprovato.',
        'already_expired' => 'Questa concessione era già scaduta; viene registrata come scaduta.',
        'closed' => 'Concessione terminata. L’accesso è stato revocato.',
    ],

    'validation' => [
        'account_required' => 'Scelga un account per l’accesso all’intero account.',
        'site_required' => 'Scelga un sito per l’accesso al sito.',
        'support_code_required' => 'Inserisca un codice di supporto per accedere a una conversazione.',
        'conversation_not_found' => 'Non è stata trovata alcuna conversazione per quel codice di supporto.',
    ],

    'errors' => [
        'grant_not_active' => 'Questa concessione non è attiva.',
    ],
];
