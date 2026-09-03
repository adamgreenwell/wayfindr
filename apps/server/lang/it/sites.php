<?php

/*
 * Bozza da lang/en/sites.php. NON ANCORA REVISIONATA.
 *
 * Scritta a mano seguendo il glossario e le regole della politica di
 * traduzione. Ogni valore è una proposta; nomi, domini, URL e termini di
 * ricerca restano dati dell'utente e sono marcati come privi di lingua.
 */
return [
    'document_title' => 'Siti',
    'title' => 'Siti',
    'subtitle' => 'Gestisca installazioni del widget, accesso all’assistenza, regole sulla privacy e instradamento dei ticket.',
    'add_site' => 'Aggiungi sito',

    'flash' => [
        'created' => 'Sito creato. Copi il frammento di installazione per completare il collegamento.',
        'purged' => 'Il sito “:site” è stato eliminato definitivamente insieme a :conversations, :tickets e :attachments.',
        'purge_counts' => [
            'conversations' => '{1} :count conversazione|[0,*] :count conversazioni',
            'tickets' => '{1} :count ticket|[0,*] :count ticket',
            'attachments' => '{1} :count allegato|[0,*] :count allegati',
        ],
    ],

    'index' => [
        'snapshot' => [
            'heading' => 'Quadro operativo dei siti',
            'lede' => 'Una panoramica rapida dei siti attualmente accessibili al Suo ruolo di assistenza.',
            'aria' => 'Metriche operative dei siti',
            'visible' => [
                'label' => 'Siti visibili',
                'value' => '{1} :count sito visibile|[0,*] :count siti visibili',
                'detail' => 'Visibili al Suo ruolo di assistenza prima dei filtri.',
                'action' => 'Esamina i siti',
            ],
            'workload' => [
                'label' => 'Attività di assistenza in corso',
                'value' => '{1} :count sito attivo|[0,*] :count siti attivi',
                'detail' => ':conversations, :open_tickets, :pending_tickets nei siti visibili.',
                'action' => 'Esamina i siti attivi',
            ],
            'install' => [
                'label' => 'Installazioni da verificare',
                'value' => '{1} :count sito richiede una verifica dell’installazione|[0,*] :count siti richiedono una verifica dell’installazione',
                'detail' => 'Installazioni del widget che non hanno inviato segnali di recente o non ne hanno ancora inviati.',
                'action' => 'Esamina le installazioni',
            ],
            'access' => [
                'label' => 'Accesso all’assistenza',
                'value' => '{1} :count sito con accesso esplicito|[0,*] :count siti con accesso esplicito',
                'detail' => '{1} :count usa l’accesso alternativo dell’intero account.|[0,*] :count usano l’accesso alternativo dell’intero account.',
            ],
        ],

        'filters' => [
            'heading' => 'Filtri dei siti',
            'lede' => 'Restringa i siti collegati per attività di assistenza, stato dell’installazione o nome.',
            'clear' => 'Azzera filtri',
            'search' => 'Cerca',
            'placeholder' => 'Nome del sito o dominio',
            'workload' => 'Attività',
            'install' => 'Installazione',
            'install_health' => 'Stato dell’installazione',
            'state' => 'Stato',
            'apply' => 'Applica filtri',
            'active_aria' => 'Filtri attivi dei siti',
            'filtered' => 'Siti filtrati',
            'all_visible' => 'Tutti i siti visibili',
            'none' => 'Nessun filtro applicato',
            'options' => [
                'workload' => [
                    'all' => 'Tutte le attività',
                    'active' => 'Attività di assistenza in corso',
                    'without_work' => 'Nessuna attività',
                ],
                'install' => [
                    'all' => 'Tutti gli stati di installazione',
                    'needs_attention' => 'Richiede attenzione',
                    'live' => 'Live',
                ],
                'state' => [
                    'active_sites' => 'Siti attivi',
                    'archived' => 'Archiviati',
                    'all' => 'Tutti gli stati',
                ],
            ],
            'summary' => [
                'shown' => '{1} :shown visualizzato su :visible visibili|[0,*] :shown visualizzati su :visible visibili',
                'visible' => '{1} :count visibile|[0,*] :count visibili',
            ],
        ],

        'list' => [
            'heading' => 'Siti collegati',
            'lede' => 'Visibili al Suo ruolo di assistenza',
            'open_tester' => 'Apri pagina di prova',
            'columns' => [
                'site' => 'Sito',
                'workload' => 'Attività',
                'access' => 'Accesso',
                'install_health' => 'Stato dell’installazione',
                'last_page' => 'Ultima pagina',
            ],
        ],

        'state' => [
            'archived' => 'Archiviato',
        ],
        'common' => [
            'not_set' => 'Non impostato',
            'not_reported' => 'Non segnalata',
        ],
        'counts' => [
            'open_conversations' => '{1} :count conversazione aperta|[0,*] :count conversazioni aperte',
            'open_tickets' => '{1} :count ticket aperto|[0,*] :count ticket aperti',
            'pending_tickets' => '{1} :count ticket in attesa|[0,*] :count ticket in attesa',
            'assigned' => '{1} :count assegnato|[0,*] :count assegnati',
            'more' => '{1} + :count altra persona|[0,*] + :count altre persone',
        ],
        'workload' => [
            'none' => 'Nessuna attività di assistenza in corso',
        ],
        'access' => [
            'explicit' => 'Accesso esplicito',
            'assigned_support' => 'Assistenza assegnata',
            'fallback' => 'Accesso alternativo dell’intero account',
            'all_agents' => 'Tutti gli agenti dell’account',
        ],
        'install' => [
            'not_installed' => 'Non installato',
            'no_check_in' => 'Ancora nessun segnale',
            'finish' => 'Completa l’installazione',
            'live' => 'Live',
            'needs_check' => 'Da verificare',
            'seen' => 'Visto :elapsed',
            'review' => 'Esamina l’installazione',
        ],
        'empty' => [
            'actions' => [
                'clear_all' => 'Azzera tutti i filtri dei siti',
                'clear_search' => 'Azzera ricerca',
                'clear_install' => 'Azzera stato dell’installazione',
                'clear_workload' => 'Azzera filtro delle attività',
                'back_to_active' => 'Torna ai siti attivi',
            ],
            'search' => [
                'heading' => 'Nessun sito corrisponde a “:search”.',
                'detail' => 'La ricerca controlla il nome e il dominio del sito. Azzerri il termine di ricerca o riduca gli altri filtri per esaminare più siti visibili.',
            ],
            'install_attention' => [
                'heading' => 'Al momento nessun sito richiede una verifica dell’installazione.',
                'detail' => 'Ogni sito visibile ha inviato un segnale recente del widget. Azzerri il filtro dello stato dell’installazione per esaminare tutti i siti collegati.',
            ],
            'live' => [
                'heading' => 'Nessuna installazione operativa del widget corrisponde a questi filtri.',
                'detail' => 'Azzerri il filtro dello stato dell’installazione per vedere i siti che attendono ancora il primo segnale del widget.',
            ],
            'workload_active' => [
                'heading' => 'Al momento nessun sito ha attività di assistenza in corso.',
                'detail' => 'Azzerri il filtro delle attività per includere i siti senza attività che potrebbero comunque richiedere una verifica dell’installazione o dell’accesso.',
            ],
            'workload_quiet' => [
                'heading' => 'Nessun sito senza attività corrisponde a questi filtri.',
                'detail' => 'Azzerri il filtro delle attività per includere i siti con conversazioni o ticket attivi.',
            ],
            'archived' => [
                'heading' => 'Non ci sono siti archiviati.',
                'detail' => 'L’archiviazione mette un sito fuori servizio senza eliminare nulla, quindi può essere annullata in qualsiasi momento. L’assenza di voci qui indica che tutti i siti che può vedere stanno ancora servendo il proprio widget.',
            ],
            'default' => [
                'heading' => 'Non ci sono ancora siti visibili.',
                'detail' => 'Aggiunga il primo sito per ottenere una chiave pubblica e un frammento di installazione del widget.',
            ],
        ],
    ],

    'create' => [
        'document_title' => 'Aggiungi sito',
        'title' => 'Aggiungi sito',
        'subtitle' => 'Crei una nuova destinazione di installazione Wayfindr per :account.',
        'back' => 'Torna alla dashboard',
        'details' => [
            'heading' => 'Dettagli del sito',
            'public_key' => 'Chiave pubblica generata automaticamente',
        ],
        'fields' => [
            'name' => 'Nome del sito',
            'domain' => 'Dominio',
            'domain_help' => 'Può incollare qui un URL completo. Wayfindr memorizza il nome host per mantenere ordinata la destinazione di installazione.',
        ],
        'submit' => 'Crea sito',
    ],
];
