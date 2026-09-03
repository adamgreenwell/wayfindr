<?php

/*
 * Drafted from lang/en/account.php. NOT YET REVIEWED.
 *
 * Machine-assisted draft against the glossary and the formal-register rules
 * in docs/product/translation-policy.md. Product and provider names remain
 * language-neutral at the rendering boundary rather than being translated.
 */
return [
    'document_title' => 'Account',
    'title' => 'Account',
    'subtitle' => 'Il suo ruolo, l’elenco del team e l’ambito di supporto visibile.',
    'agent_count' => '{1} :count agente|[2,*] :count agenti',
    'flash' => [
        'created_and_welcome_sent' => 'Agente creato ed email di benvenuto inviata.',
        'created_welcome_failed' => 'Agente creato, ma non è stato possibile inviare l’email di benvenuto. Condivida la password temporanea in modo sicuro.',
        'created' => 'Agente creato. Condivida la password temporanea in modo sicuro.',
        'role_updated' => 'Ruolo dell’agente aggiornato.',
        'deactivated' => 'Agente disattivato.',
        'reactivated' => 'Agente riattivato.',
    ],
    'temporary_password' => [
        'heading' => 'Password temporanea',
        'help' => 'Condivida questa password in modo sicuro. Viene mostrata una sola volta e l’agente dovrebbe cambiarla dopo l’accesso.',
    ],
    'map' => [
        'heading' => 'Mappa dell’account',
        'count' => '{1} :count sezione|[2,*] :count sezioni',
        'open' => 'Apri',
        'items' => [
            'account' => ['label' => 'Confine dell’account', 'detail' => 'Ruolo, numero di siti, ambito visibile e assegnazioni di supporto.'],
            'role' => ['label' => 'Confine del ruolo', 'detail' => 'Come l’autorità dell’account differisce dall’accesso a livello di sito.'],
            'sites' => ['label' => 'Accesso ai siti', 'detail' => 'Quali siti e code di supporto sono visibili nell’elenco.'],
            'external' => ['label' => 'Prontezza delle segnalazioni esterne', 'detail' => 'Stato dell’instradamento dei provider per il passaggio dei ticket.'],
            'activity' => ['label' => 'Attività dell’account', 'detail' => 'Modifiche recenti all’accesso, all’elenco e all’ambito di supporto.'],
            'add_agent' => ['label' => 'Aggiungi agente', 'detail' => 'Inviti una persona del team con una password temporanea generata.'],
            'alerts' => ['label' => 'Prontezza degli avvisi del team', 'detail' => 'Se gli agenti attivi possono effettivamente ricevere notifiche.'],
            'agents' => ['label' => 'Agenti', 'detail' => 'Elenco, ruolo, ambito di supporto, carico di lavoro e stato di consegna.'],
        ],
    ],
    'context' => [
        'boundary' => 'Confine dell’account', 'your_role' => 'Il suo ruolo', 'sites' => 'Siti',
        'site_count' => '{1} :count sito|[2,*] :count siti', 'visible' => 'Visibili a lei', 'assignments' => 'Assegnazioni di supporto',
        'assignment_count' => '{1} :count assegnazione di supporto|[2,*] :count assegnazioni di supporto',
    ],
    'role_boundary' => [
        'heading' => 'Confine del ruolo', 'owner_enabled' => 'Gestione del titolare abilitata', 'read_only' => 'Sola lettura per il suo ruolo',
        'authority' => 'I ruoli dell’account descrivono l’autorità. L’accesso al sito decide comunque quali code di supporto può gestire un agente.',
        'changes' => 'Le modifiche dei ruoli sono riservate ai titolari dell’account. I titolari non possono cambiare qui il proprio ruolo e ogni modifica viene registrata.',
        'suspension' => 'Titolari e amministratori possono sospendere l’accesso senza eliminare la cronologia dell’account. Gli amministratori possono sospendere solo gli agenti; i titolari possono gestire qualsiasi altra persona dello stesso account.',
    ],
    'site_access' => [
        'heading' => 'Matrice di accesso ai siti', 'visible_count' => '{1} :count sito visibile|[2,*] :count siti visibili',
        'empty' => 'Nessun sito di supporto è ancora visibile al suo account.',
        'columns' => ['site' => 'Sito', 'model' => 'Modello di accesso', 'agents' => 'Agenti di supporto attivi', 'manage' => 'Gestisci'],
        'domain_missing' => 'Dominio non impostato', 'fallback' => 'Accesso predefinito dell’account', 'explicit' => 'Accesso esplicito',
        'all_active' => 'Tutti gli agenti attivi dell’account', 'eligible' => ':count idonei finché non vengono salvate le assegnazioni',
        'assigned' => '{1} :count agente attivo assegnato|[2,*] :count agenti attivi assegnati', 'manage' => 'Gestisci accesso',
    ],
    'external' => [
        'heading' => 'Prontezza delle segnalazioni esterne',
        'states' => ['not_configured' => 'Non configurato', 'needs_attention' => 'Richiede attenzione', 'sync_pending' => 'Sincronizzazione in attesa', 'ready' => 'Pronto'],
        'details' => [
            'no_connections' => 'Aggiunga una connessione provider quando i ticket devono uscire da Wayfindr.',
            'no_projects' => 'Associ almeno un progetto a un sito prima che i ticket possano uscire da Wayfindr.',
            'attention' => 'Controlli le connessioni disabilitate o le sincronizzazioni non riuscite prima di fare affidamento sul passaggio esterno.',
            'pending' => 'Alcuni collegamenti esterni attendono ancora conferma.',
            'ready' => 'L’instradamento delle segnalazioni esterne ha progetti associati e nessuna sincronizzazione non riuscita.',
        ],
        'tones' => ['ready' => 'Pronto', 'manual' => 'Manuale', 'attention' => 'Attenzione'],
        'metrics' => [
            'connections' => 'Connessioni provider', 'connection_count' => '{1} :count connessione provider|[2,*] :count connessioni provider',
            'projects' => 'Progetti associati', 'project_count' => '{1} :count progetto associato|[2,*] :count progetti associati',
            'disabled' => 'Disabilitate', 'disabled_count' => ':count disabilitate', 'failed' => 'Sincronizzazione non riuscita',
            'failed_count' => ':count sincronizzazioni non riuscite', 'pending' => 'Sincronizzazione in attesa', 'pending_count' => ':count sincronizzazioni in attesa',
            'review_failed' => 'Controlla ticket non riusciti', 'review_pending' => 'Controlla ticket in attesa',
        ],
        'projects' => [
            'empty' => 'Nessun progetto di segnalazioni esterne è ancora associato.',
            'columns' => ['site' => 'Sito', 'provider' => 'Provider', 'project' => 'Progetto', 'capabilities' => 'Funzioni', 'handoff' => 'Passaggio alla segnalazione esterna', 'manage' => 'Gestisci'],
            'connection_enabled' => 'Connessione abilitata', 'connection_disabled' => 'Connessione disabilitata', 'link_only' => 'Solo collegamento',
            'manage' => 'Gestisci instradamento', 'unknown_site' => 'Sito sconosciuto',
        ],
        'handoff' => [
            'blocked' => ['label' => 'Bloccato', 'detail' => 'La connessione provider è disabilitata.'],
            'unsupported' => ['label' => 'Solo collegamento', 'detail' => 'La creazione di segnalazioni Wayfindr non è ancora disponibile per questo provider.'],
            'ready' => ['label' => 'Passaggio pronto', 'detail' => 'Può creare segnalazioni esterne.'],
            'disabled' => ['label' => 'Solo collegamento', 'detail' => 'La creazione di segnalazioni esterne non è abilitata.'],
        ],
        'failures' => [
            'empty' => 'Nessun errore recente di sincronizzazione esterna per questo account.', 'last' => 'Ultimo errore di sincronizzazione esterna',
            'earlier' => 'Errore precedente di sincronizzazione esterna', 'body' => ':provider non ha potuto sincronizzare :project.',
            'status' => 'Stato :status', 'unknown_project' => 'Progetto sconosciuto', 'details_withheld' => 'Dettagli del provider omessi',
        ],
    ],
    'management' => [
        'heading' => 'Gestione', 'lede' => 'Impostazioni dell’intero account',
        'actions' => ['manage' => 'Gestisci', 'view' => 'Visualizza', 'open' => 'Apri', 'review' => 'Controlla'],
        'items' => [
            'integrations' => ['label' => 'Integrazioni', 'detail' => 'Provider di segnalazioni esterne e dove ogni sito passa i ticket.'],
            'sites' => ['label' => 'Siti', 'detail' => 'Siti collegati, stato di installazione del widget e impostazioni per sito.'],
            'articles' => ['label' => 'Articoli', 'detail' => 'Risposte che un visitatore può cercare autonomamente prima di chiedere.'],
            'replies' => ['label' => 'Assistenti di risposta', 'detail' => 'Risposte salvate che gli agenti possono inserire nelle conversazioni.'],
            'labels' => ['label' => 'Etichette dei ticket', 'detail' => 'Etichette condivise per organizzare e filtrare i ticket.'],
            'audit' => ['label' => 'Registro di audit', 'detail' => 'Cerchi nell’attività dell’account ed esporti record di audit sicuri.'],
            'tokens' => ['label' => 'Token API', 'detail' => 'Accesso programmatico in lettura a questo account per integrazioni create da lei o da altri.'],
            'operator_access' => ['label' => 'Accesso degli operatori', 'detail' => 'Richieste degli operatori della piattaforma per vedere i dati di supporto di questo account.'],
        ],
    ],
    'data_responsibility' => [
        'heading' => 'Responsabilità dei dati', 'label' => 'Promemoria per il gestore',
        'message' => 'La conservazione dei dati forniti dai visitatori può creare obblighi di privacy, sicurezza e conformità legale.',
        'guidance' => 'Conservi solo ciò che serve, stabilisca un periodo di conservazione giustificabile e si assicuri che l’informativa sulla privacy corrisponda all’uso di questa installazione Wayfindr.',
        'docs' => 'Leggi la documentazione sulla responsabilità dei dati',
    ],
    'activity' => [
        'heading' => 'Attività recente dell’account', 'shown' => ':count mostrati', 'view_audit' => 'Visualizza registro di audit',
        'empty' => 'Ancora nessuna attività dell’account.', 'scope' => 'Accesso all’account', 'system' => 'Sistema', 'account' => 'Account',
        'labels' => [
            'agent_created' => 'Agente creato', 'agent_deactivated' => 'Agente disattivato', 'password_changed' => 'Password modificata',
            'agent_reactivated' => 'Agente riattivato', 'role_changed' => 'Ruolo dell’agente modificato', 'site_access' => 'Accesso al sito aggiornato', 'default' => 'Attività dell’account',
        ],
        'bodies' => [
            'agent_created' => 'Account agente creato', 'agent_deactivated' => 'Accesso dell’agente sospeso', 'password_changed' => 'Password modificata',
            'agent_reactivated' => 'Accesso dell’agente ripristinato', 'role_changed' => 'Ruolo modificato da :old a :new',
            'role_changed_unknown' => 'Ruolo dell’account modificato', 'site_access' => 'Accesso al supporto aggiornato', 'default' => 'Attività dell’account registrata',
        ],
    ],
    'create' => [
        'heading' => 'Aggiungi agente', 'lede' => 'I nuovi agenti iniziano con il ruolo Agente', 'name' => 'Nome', 'email' => 'Email',
        'welcome' => 'Invia per email il messaggio di benvenuto e la password temporanea',
        'password_help' => 'Verrà generata una password temporanea. L’accesso ai siti segue l’attuale accesso predefinito dell’account finché non limita gli agenti su ciascun sito.',
        'email_help' => 'Usi l’opzione email dopo aver configurato la posta in uscita. Come alternativa, la password viene comunque mostrata qui una sola volta.',
        'submit' => 'Crea agente',
    ],
    'team_alert' => [
        'heading' => 'Prontezza degli avvisi del team',
        'labels' => [
            'attention' => '{1} :count agente richiede attenzione|[2,*] :count agenti richiedono attenzione',
            'baseline' => '{1} :count riferimento del riepilogo necessario|[2,*] :count riferimenti del riepilogo necessari',
            'ready' => 'La consegna degli avvisi sembra pronta',
        ],
        'details' => [
            'attention' => 'La consegna del riepilogo richiede un controllo della posta o del provider. Gli errori grezzi del provider restano nei log e non in questa pagina dell’account.',
            'baseline' => 'Esegua una volta il comando del riepilogo degli avvisi dopo aver configurato il pianificatore, così gli agenti abilitati hanno un riferimento registrato.',
            'none_active' => 'Nessun agente attivo può ancora ricevere avvisi.',
            'ready' => 'Gli agenti attivi hanno un percorso di notifica leggibile per le preferenze attuali.',
        ],
        'metrics' => [
            'active_agents' => 'Agenti attivi', 'active_count' => '{1} :count attivo|[2,*] :count attivi',
            'immediate' => 'Email immediata', 'immediate_count' => '{1} :count email immediata|[2,*] :count email immediate',
            'digest_ready' => 'Riepilogo pronto', 'digest_ready_count' => ':count riepiloghi pronti',
            'baseline' => 'Riferimento del riepilogo', 'baseline_count' => ':count riepiloghi richiedono un riferimento',
            'attention' => 'Richiede attenzione', 'attention_count' => ':count richiedono attenzione',
            'dashboard_only' => 'Solo dashboard', 'dashboard_only_count' => ':count solo dashboard o modalità silenziosa',
            'deactivated' => 'Disattivati', 'deactivated_count' => ':count disattivati',
        ],
    ],
    'agents' => [
        'heading' => 'Agenti', 'total' => ':count totali',
        'columns' => ['agent' => 'Agente', 'status' => 'Stato', 'role' => 'Ruolo', 'alerts' => 'Consegna avvisi', 'manage_role' => 'Gestisci ruolo', 'manage_access' => 'Gestisci accesso', 'scope' => 'Ambito di supporto', 'workload' => 'Carico di lavoro'],
        'unknown_alerts' => 'Sconosciuto', 'alerts_unavailable' => 'Lo stato di consegna degli avvisi non è disponibile.',
        'status' => ['deactivated' => 'Disattivato', 'active' => 'Attivo'], 'current_user' => 'Utente attuale',
        'save_role' => 'Salva ruolo', 'owner_only' => 'Solo titolare',
        'reactivate' => 'Riattiva', 'deactivate' => 'Disattiva', 'no_scope' => 'Nessun ambito di supporto attivo',
        'explicit_count' => '{1} :count sito esplicito|[2,*] :count siti espliciti', 'fallback_count' => '{1} :count sito predefinito|[2,*] :count siti predefiniti',
        'explicit' => 'Esplicito: :sites', 'fallback' => 'Predefinito: :sites', 'more' => '+ altri :count', 'review_access' => 'Controlla accesso ai siti',
        'open_conversations' => '{1} :count conversazione aperta|[2,*] :count conversazioni aperte',
        'open_tickets' => '{1} :count ticket aperto|[2,*] :count ticket aperti', 'no_work' => 'Nessun lavoro aperto assegnato',
        'alert_delivery' => [
            'deactivated' => 'Disattivato', 'deactivated_detail' => 'La consegna degli avvisi è in pausa mentre l’accesso è sospeso.',
            'quiet_mode' => 'Modalità silenziosa', 'quiet_detail' => 'I nuovi avvisi della dashboard e le email sono in pausa.',
            'email_off' => 'Email disattivata', 'digest_delivery' => 'Riepilogo', 'last_attempt' => 'Ultimo tentativo :elapsed',
            'unattended' => 'Solo non presidiato', 'unattended_detail' => 'Email solo quando un messaggio del visitatore resta non visualizzato per :minutes minuti.',
            'immediate' => 'Immediato', 'immediate_detail' => 'Avvisi email non appena si verificano.',
            'assigned_only' => 'Solo assegnati', 'assigned_detail' => 'Avvisi della dashboard solo per conversazioni e ticket assegnati.',
            'all' => 'Tutto il lavoro di supporto', 'all_detail' => 'Gli avvisi della dashboard possono provenire dal lavoro di qualsiasi sito che questo agente può supportare.',
        ],
    ],
];
