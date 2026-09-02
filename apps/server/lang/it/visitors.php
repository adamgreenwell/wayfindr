<?php

/*
 * Bozza da lang/en/visitors.php. NON ANCORA REVISIONATA.
 *
 * Scritta a mano seguendo il glossario e le regole della politica di
 * traduzione. Ogni valore è una proposta; le istruzioni in prosa usano Lei e
 * le etichette delle azioni usano l'imperativo semplice.
 */
return [
    'document_title' => 'Visitatori',
    'title' => 'Visitatori',
    'subtitle' => [
        'browsers' => 'Tutte le persone viste da questo team, che abbiano o meno preso contatto, a partire dalle più recenti.',
        'contacts' => 'Tutte le persone da cui questo team ha ricevuto notizie, a partire dalle più recenti.',
    ],

    'filters' => [
        'heading' => 'Cerca',
        'hint' => 'Per nome, email o identificativo assegnato dal sito.',
        'search' => 'Cerca',
        'placeholder' => 'Nome, email o ID',
        'site' => 'Sito',
        'any_site' => 'Qualsiasi sito',
        'last_seen' => 'Ultimo contatto',
        'any_time' => 'Qualsiasi momento',
        'submit' => 'Cerca visitatori',
        'clear' => 'Azzera',
    ],

    'list' => [
        'heading' => 'Visitatori',
        'columns' => [
            'visitor' => 'Visitatore',
            'site' => 'Sito',
            'last_seen' => 'Ultimo contatto',
            'conversations' => 'Conversazioni',
        ],
        'unknown_site' => 'Sito sconosciuto',
    ],

    'empty' => [
        'browsers' => 'Nessun visitatore corrisponde a questa ricerca. Sui siti mostrati qui Wayfindr registra una persona quando carica una pagina, quindi l’elenco comprende anche chi stava solo navigando.',
        'contacts' => 'Nessun visitatore corrisponde a questa ricerca. Wayfindr registra una persona quando apre la chat, non quando carica una pagina, quindi l’elenco comprende chi ha preso contatto.',
    ],

    'presence' => [
        'seen_recently' => 'Visto negli ultimi 2 minuti',
        'seen_at' => 'Visto :elapsed',
        'no_heartbeat' => 'Nessun segnale recente dal visitatore.',
    ],

    'counts' => [
        'visitors' => '{1} :count visitatore|[2,*] :count visitatori',
        'conversations' => '{1} :count conversazione|[2,*] :count conversazioni',
        'tickets' => '{1} :count ticket|[2,*] :count ticket',
        'active_conversations' => '{1} :count conversazione attiva|[2,*] :count conversazioni attive',
        'active_tickets' => '{1} :count ticket attivo|[2,*] :count ticket attivi',
        'fields' => '{1} :count campo|[2,*] :count campi',
        'shown' => '{1} :count visualizzato|[2,*] :count visualizzati',
    ],

    'common' => [
        'not_provided' => 'Non fornito',
        'not_reported' => 'Non segnalato',
    ],

    'profile' => [
        'document_title' => 'Profilo del visitatore',
        'title' => 'Profilo del visitatore',
        'back' => 'Torna alla dashboard',
        'glance' => [
            'heading' => 'Visitatore in sintesi',
            'safe_only' => 'Solo contesto sicuro',
            'visitor' => 'Visitatore',
            'host_visitor_id' => 'ID visitatore dell’host',
            'last_seen' => 'Ultimo contatto',
            'latest_page' => 'Pagina più recente',
            'entry_page' => 'Prima pagina di ingresso acquisita',
            'support_history' => 'Cronologia dell’assistenza',
        ],
    ],

    'snapshot' => [
        'heading' => 'Quadro dell’assistenza',
        'conversations' => 'Conversazioni',
        'tickets' => 'Ticket',
        'next_step' => 'Passaggio successivo',
        'agent_cue' => 'Indicazione per l’agente',
        'status' => [
            'needs_reply' => 'Richiede risposta',
            'review_context' => 'Esamina il contesto',
            'waiting' => 'In attesa',
            'in_progress' => 'In corso',
            'clear' => 'Tutto a posto',
        ],
        'reply' => [
            'body' => 'Il visitatore ha risposto per ultimo. Apra l’elemento di assistenza più recente prima di esaminare la cronologia precedente.',
            'cta' => 'Rispondi al visitatore',
            'title' => 'Rispondi al visitatore',
        ],
        'empty_conversation' => [
            'body' => 'Non è ancora arrivato alcun messaggio. Usi il contesto attuale del visitatore per decidere se salutare, attendere o creare un ticket.',
            'cta' => 'Esamina il contesto',
            'title' => 'Avvia la conversazione',
        ],
        'waiting_conversation' => [
            'body' => 'Al momento non è attesa alcuna risposta dal visitatore. Tenga visibile la conversazione e risponda quando il visitatore torna.',
            'cta' => 'Esamina la conversazione',
            'title' => 'In attesa del visitatore',
        ],
        'waiting_ticket' => [
            'body' => 'Al momento non è attesa alcuna risposta dal visitatore. Esamini il ticket attivo quando è il momento del follow-up.',
            'cta' => 'Esamina il ticket',
            'title' => 'Ticket in corso',
        ],
        'clear' => [
            'body' => 'Nessuna attività di assistenza è collegata a questo visitatore.',
            'title' => 'Nessuna attività',
        ],
    ],

    'references' => [
        'heading' => 'Riferimenti dell’assistenza',
        'lede' => 'Riferimenti stabili per ricerca, passaggio di consegne e follow-up.',
        'visitor' => 'Riferimento di ricerca del visitatore',
        'latest_support_code' => 'Codice di assistenza più recente',
        'latest_ticket' => 'Ticket più recente',
        'ticket' => 'Ticket n. :id',
        'no_conversations' => 'Ancora nessuna conversazione',
        'no_tickets' => 'Ancora nessun ticket',
    ],

    'boundary' => [
        'heading' => 'Confine dei dati',
        'body' => 'Usi questa pagina per comprendere il percorso di assistenza. Non raccolga, esporti o deduca altri dati del visitatore senza consenso.',
    ],

    'context' => [
        'heading' => 'Contesto dell’host',
        'field' => 'Campo',
        'value' => 'Valore',
        'empty_heading' => 'Nessun contesto fornito dall’host.',
        'empty_body' => 'Wayfindr dispone solo del riferimento anonimo del visitatore finché il sito host non fornisce un contesto sicuro sul cliente o sull’account.',
    ],

    'history' => [
        'heading' => 'Cronologia dell’assistenza',
        'conversations' => 'Conversazioni',
        'tickets' => 'Ticket',
        'no_conversations_heading' => 'Ancora nessuna conversazione per questo visitatore.',
        'no_conversations_body' => 'Le nuove conversazioni compariranno qui quando questo visitatore avvierà una richiesta di assistenza su questo sito.',
        'no_tickets_heading' => 'Ancora nessun ticket per questo visitatore.',
        'no_tickets_body' => 'Crei un ticket da una conversazione quando il passaggio successivo richiede un follow-up duraturo.',
        'untitled_conversation' => 'Conversazione senza titolo',
        'owner' => 'Responsabile',
        'unassigned' => 'Non assegnato',
        'last_activity' => 'Ultima attività: :elapsed',
        'support_code' => 'Codice di assistenza',
        'updated' => 'Aggiornato: :elapsed',
    ],
];
