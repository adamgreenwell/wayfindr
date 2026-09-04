<?php

return [
    'document_title' => 'Avvisi',
    'title' => 'Centro avvisi',
    'subtitle' => 'Avvisi di supporto visibili per :account.',

    'center' => [
        'heading' => 'Avvisi recenti',
        'lede' => 'Gli avvisi non letti restano qui finché il lavoro correlato non viene aperto o contrassegnato come letto.',
        'lanes' => 'Corsie degli avvisi',
        'all' => 'Tutti gli avvisi',
        'unread_only' => 'Solo non letti',
        'bulk_matching' => 'Contrassegna come letti i corrispondenti',
        'bulk_unread' => 'Contrassegna come letti i non letti',
        'bulk_matching_help' => 'Tutti gli avvisi non letti che corrispondono a questa vista saranno contrassegnati come letti, inclusi quelli fuori dalla visualizzazione corrente.',
        'bulk_unread_help' => 'Tutti gli avvisi non letti a cui può ancora accedere saranno contrassegnati come letti, inclusi quelli fuori dalla visualizzazione corrente.',
        'privacy' => 'Gli avvisi a cui non può più accedere vengono nascosti, così le vecchie notifiche non espongono lavoro di supporto riservato.',
    ],

    'delivery' => [
        'heading' => 'Contesto di consegna degli avvisi',
        'region' => 'Contesto personale di consegna degli avvisi',
        'source_detail' => 'Gli avvisi della dashboard restano la fonte principale per il lavoro di supporto che richiede attenzione.',
        'change_preferences' => 'Modifica preferenze degli avvisi',
        'mode' => [
            'label' => 'Modalità attuale',
            'assigned_detail' => 'Solo le conversazioni e i ticket assegnati generano nuovi avvisi per lei.',
            'quiet_detail' => 'La modalità silenziosa mette in pausa i nuovi avvisi senza modificare quelli visibili esistenti.',
            'all_detail' => 'Il lavoro di supporto idoneo dei siti che può gestire può generare avvisi.',
        ],
        'email' => [
            'label' => 'Consegna email',
            'off' => 'Email disattivata',
            'digest' => 'Riepilogo preferito',
            'unattended' => 'Solo non presidiato',
            'immediate' => 'Email immediata',
            'off_detail' => 'Gli avvisi email sono disattivati per il suo profilo. Il centro avvisi resta disponibile qui.',
            'digest_detail' => 'La consegna del riepilogo viene preferita quando viene eseguito il pianificatore. Gli avvisi della dashboard appaiono comunque subito qui.',
            'unattended_detail' => '{1} L’email viene inviata solo quando un messaggio del visitatore rimane non visualizzato per 1 minuto. Gli avvisi della dashboard appaiono comunque subito qui.|[2,*] L’email viene inviata solo quando un messaggio del visitatore rimane non visualizzato per :count minuti. Gli avvisi della dashboard appaiono comunque subito qui.',
            'immediate_detail' => 'La consegna email immediata è attiva quando la posta è configurata. Gli avvisi della dashboard appaiono comunque subito qui.',
        ],
    ],

    'filters' => [
        'region' => 'Filtra avvisi',
        'search_label' => 'Cerca avvisi',
        'search_placeholder' => 'Codice supporto, ticket, oggetto, sito o visitatore',
        'search_help' => 'Cerchi solo nel contesto visibile degli avvisi; il lavoro di supporto riservato resta nascosto.',
        'kind_label' => 'Tipo di avviso',
        'apply' => 'Applica',
        'clear' => 'Cancella filtri',
        'active_region' => 'Filtri avvisi attivi',
        'active_heading' => 'Filtri avvisi attivi',
        'active_detail' => 'Gli avvisi sono limitati al lavoro di supporto che corrisponde a questa vista.',
    ],

    'kinds' => [
        'all' => 'Tutti gli avvisi',
        'conversation' => 'Avvisi di conversazione',
        'ticket' => 'Avvisi di ticket',
        'sla' => 'Avvisi SLA',
    ],

    'focus' => [
        'region' => 'Focus del centro avvisi',
        'heading' => 'Focus degli avvisi',
        'detail' => 'Ciò che mostra questo centro avvisi prima di esaminare gli elementi.',
        'view' => 'Vista',
        'type' => 'Tipo',
        'visible' => 'Visibili',
        'unread' => 'Non letti',
        'search' => 'Ricerca',
    ],

    'chips' => [
        'type' => 'Tipo: :value',
        'search' => 'Ricerca: :value',
    ],

    'counts' => [
        'visible' => '{1} :count visibile|[2,*] :count visibili',
        'unread' => '{1} :count non letto|[2,*] :count non letti',
        'conversations' => '{1} :count conversazione|[2,*] :count conversazioni',
        'tickets' => '{1} :count ticket presente|[2,*] :count ticket presenti',
        'sla' => '{1} :count avviso SLA|[2,*] :count avvisi SLA',
        'new_messages' => '{1} 1 nuovo messaggio|[2,*] :count nuovi messaggi',
    ],

    'snapshot' => [
        'region' => 'Riepilogo degli avvisi',
        'visible' => [
            'label' => 'Avvisi visibili',
            'present' => 'Avvisi attuali che può ancora aprire.',
            'empty' => 'Al momento nulla richiede attenzione in questa vista degli avvisi.',
        ],
        'unread' => [
            'label' => 'Avvisi non letti',
            'present' => 'Ancora in attesa di esame o di essere contrassegnati come letti.',
            'empty' => 'Nessun avviso non letto è in attesa di esame.',
        ],
        'conversations' => [
            'label' => 'Avvisi di conversazione',
            'present' => 'Risposte dei visitatori e follow-up delle chat.',
            'empty' => 'Nessun avviso di risposta del visitatore in questa vista.',
        ],
        'tickets' => [
            'label' => 'Avvisi di ticket',
            'present' => 'Assegnazioni di ticket e lavoro persistente.',
            'empty' => 'Nessun avviso di assegnazione ticket in questa vista.',
        ],
        'sla' => [
            'label' => 'Avvisi SLA',
            'present' => 'Scadenze vicine o già violate.',
            'empty' => 'Nessuna scadenza SLA richiede attenzione in questa vista.',
        ],
    ],

    'summary' => [
        'unread_heading' => 'Sono mostrati gli avvisi visibili non letti.',
        'latest' => '{1} Viene mostrato l’ultimo avviso visibile.|[2,*] Vengono mostrati gli ultimi :count avvisi visibili.',
        'matching_heading' => [
            'all' => '{1} Viene mostrato 1 avviso corrispondente.|[2,*] Vengono mostrati :count avvisi corrispondenti.',
            'unread' => '{1} Viene mostrato 1 avviso non letto corrispondente.|[2,*] Vengono mostrati :count avvisi non letti corrispondenti.',
            'conversation' => '{1} Viene mostrato 1 avviso di conversazione corrispondente.|[2,*] Vengono mostrati :count avvisi di conversazione corrispondenti.',
            'ticket' => '{1} Viene mostrato 1 avviso di ticket corrispondente.|[2,*] Vengono mostrati :count avvisi di ticket corrispondenti.',
            'sla' => '{1} Viene mostrato 1 avviso SLA corrispondente.|[2,*] Vengono mostrati :count avvisi SLA corrispondenti.',
        ],
        'capped_heading' => [
            'all' => '{1} :shown mostrato di 1 avviso corrispondente.|[2,*] :shown mostrati di :count avvisi corrispondenti.',
            'unread' => '{1} :shown mostrato di 1 avviso non letto corrispondente.|[2,*] :shown mostrati di :count avvisi non letti corrispondenti.',
            'conversation' => '{1} :shown mostrato di 1 avviso di conversazione corrispondente.|[2,*] :shown mostrati di :count avvisi di conversazione corrispondenti.',
            'ticket' => '{1} :shown mostrato di 1 avviso di ticket corrispondente.|[2,*] :shown mostrati di :count avvisi di ticket corrispondenti.',
            'sla' => '{1} :shown mostrato di 1 avviso SLA corrispondente.|[2,*] :shown mostrati di :count avvisi SLA corrispondenti.',
        ],
        'capped_detail' => [
            'all' => '{1} Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. 1 avviso corrisponde a questa vista.|[2,*] Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. :count avvisi corrispondono a questa vista.',
            'unread' => '{1} Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. 1 avviso non letto corrisponde a questa vista.|[2,*] Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. :count avvisi non letti corrispondono a questa vista.',
            'conversation' => '{1} Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. 1 avviso di conversazione corrisponde a questa vista.|[2,*] Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. :count avvisi di conversazione corrispondono a questa vista.',
            'ticket' => '{1} Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. 1 avviso di ticket corrisponde a questa vista.|[2,*] Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. :count avvisi di ticket corrispondono a questa vista.',
            'sla' => '{1} Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. 1 avviso SLA corrisponde a questa vista.|[2,*] Dopo il limite di visualizzazione corrente vengono mostrati :shown avvisi. :count avvisi SLA corrispondono a questa vista.',
        ],
    ],

    'empty' => [
        'search' => [
            'heading' => 'Nessun avviso corrisponde a “:search”.',
            'detail' => 'La ricerca controlla codici di supporto, numeri di ticket, oggetti, siti, visitatori e anteprime dei messaggi a cui può ancora accedere.',
        ],
        'kind' => [
            'conversation' => 'Nessun avviso di conversazione corrisponde a questa vista.',
            'ticket' => 'Nessun avviso di ticket corrisponde a questa vista.',
            'sla' => 'Nessun avviso SLA corrisponde a questa vista.',
            'detail' => 'Provi tutti i tipi di avviso per includere gli altri segnali di supporto a cui può ancora accedere.',
        ],
        'unread' => [
            'heading' => 'È tutto aggiornato.',
            'detail' => 'Le nuove risposte idonee dei visitatori e le assegnazioni dei ticket appariranno qui quando richiedono attenzione.',
        ],
        'all' => [
            'heading' => 'Nessun avviso visibile per ora.',
            'detail' => 'Le risposte dei visitatori e le assegnazioni dei ticket che può gestire appariranno qui quando richiedono attenzione.',
        ],
    ],

    'actions' => [
        'clear_search' => 'Cancella ricerca',
        'clear_all_filters' => 'Cancella tutti i filtri degli avvisi',
        'clear_type' => 'Cancella filtro del tipo',
        'show_recent' => 'Mostra avvisi recenti',
        'back_to_dashboard' => 'Torna alla dashboard',
        'review_preferences' => 'Controlla preferenze degli avvisi',
    ],

    'card' => [
        'status' => [
            'unread' => 'Non letto',
            'read' => 'Letto',
            'aria' => 'Stato avviso: :status',
            'read_at' => 'Letto :elapsed',
        ],
        'untitled_ticket' => 'Ticket senza titolo',
        'untitled_conversation' => 'Conversazione senza titolo',
        'ticket_assigned' => 'Ticket assegnato',
        'automation_matched' => 'L’automazione ha riconosciuto questa attività di assistenza',
        'automation_rule' => 'Regola:',
        'sla_warning' => 'Scadenza SLA vicina',
        'sla_breached' => 'Scadenza SLA violata',
        'sla_metric' => 'Obiettivo: :metric',
        'sla_warning_why' => 'Questo lavoro ha consumato gran parte dell’obiettivo calcolato nell’orario di supporto.',
        'sla_breach_why' => 'Questo lavoro ha superato l’obiettivo calcolato nell’orario di supporto.',
        'sla_warning_next' => 'Apra subito il lavoro e decida chi lo porterà avanti prima della scadenza.',
        'sla_breach_next' => 'Apra il lavoro, ne assuma la responsabilità e ristabilisca un prossimo passo chiaro.',
        'assigned_by' => ':name le ha assegnato questo ticket.',
        'someone' => 'Qualcuno',
        'why' => 'Perché questo avviso:',
        'next_move' => 'Prossima mossa:',
        'ticket_why' => 'Questo ticket le è stato assegnato. Apra il ticket o contrassegni questo avviso come letto dopo averlo esaminato.',
        'ticket_next' => 'Apra il ticket assegnato e decida il responsabile, la priorità o lo stato successivo.',
        'automation_why' => 'Una regola configurata ha chiesto esplicitamente a Wayfindr di avvisarla di questa attività.',
        'automation_next' => 'Apra l’attività, controlli le modifiche automatiche e proceda con il passaggio successivo appropriato.',
        'conversation_why' => 'Una risposta del visitatore attende in una conversazione che può gestire. Apra la conversazione o contrassegni questo avviso come letto dopo averla gestita.',
        'conversation_next' => 'Apra la conversazione e risponda mentre il visitatore attende.',
        'ticket_reference' => 'Ticket n. :id',
        'on_site' => 'su :site',
        'priority' => 'priorità :priority',
        'unknown_site' => 'Sito sconosciuto',
        'open_ticket' => 'Apri ticket',
        'open_conversation' => 'Apri conversazione',
        'mark_read' => 'Contrassegna come letto',
        'already_read' => 'Già letto.',
    ],
];
