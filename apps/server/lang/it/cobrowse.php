<?php

/*
 * Drafted from lang/en/cobrowse.php. NOT YET REVIEWED.
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
 * 5 plural string(s) need their segment count checked against it.
 * /
 * /
 * /
 * /
 */
return [
    'transport' => [
        'exhausted' => [
            'label' => 'Limite di tentativi raggiunto',
            'message' => 'Limite massimo di tentativi per snapshot aggiornato raggiunto.',
            'guidance' => 'Richieda un altro nuovo snapshot quando il trasporto del visitatore si stabilizza.',
            'recovery_action' => 'Richieda un altro nuovo snapshot quando il trasporto del visitatore si stabilizza.',
        ],
        'recovery_locked' => 'Snapshot aggiornato già richiesto. Attenda il widget del visitatore prima di riprovare.',
        'inactive' => [
            'label' => 'Non disponibile',
            'message' => 'Il trasporto Cobrowse non è attivo.',
            'guidance' => 'Attenda una sessione cobrowse attiva prima di fare affidamento su cobrowse.',
            'recovery_action' => 'Attenda che la pagina del visitatore segnali prima di richiedere il ripristino.',
        ],
        'no_reports' => [
            'label' => 'Non disponibile',
            'message' => 'Non sono ancora arrivati report di trasporto cobrowse.',
            'guidance' => 'Attenda che la pagina del visitatore invii un report prima di fare affidamento su cobrowse.',
            'recovery_action' => 'Attenda che la pagina del visitatore invii il report prima di richiedere il recupero.',
        ],
        'stale' => [
            'label' => 'Obsoleto',
            'message' => 'Nessun report cobrowse è arrivato negli ultimi 2 minuti.',
            'guidance' => 'Chieda al visitatore di confermare ciò che vede prima di fare affidamento sull\'anteprima.',
            'recovery_action' => 'Richieda un nuovo snapshot se l\'anteprima sembra non aggiornata e confermi i dettagli tramite chat.',
        ],
        'reconnecting' => [
            'label' => 'Riconnessione',
            'message' => 'Il trasporto del visitatore si è riconnesso di recente; i dati dell\'anteprima potrebbero subire un breve ritardo.',
            'guidance' => 'Usi la chat per confermare qualsiasi cosa che dipenda da uno stato della pagina in rapido cambiamento.',
            'recovery_action' => 'Attenda un momento che il widget visitatore si stabilizzi, poi richieda un nuovo snapshot se l’anteprima è ancora in ritardo.',
        ],
        'degraded' => [
            'label' => 'Degradato',
            'message' => 'I report Cobrowse stanno arrivando, ma la pagina del visitatore sta cambiando più velocemente di quanto Wayfindr possa riprodurre completamente.',
            'guidance' => 'Usi l’anteprima per orientarsi e confermi i dettagli che cambiano rapidamente tramite chat.',
            'recovery_action' => 'Richieda un nuovo snapshot una volta che il widget visitatore si è stabilizzato e usi la chat per i dettagli che cambiano rapidamente.',
        ],
        'live' => [
            'label' => 'Live',
            'message' => 'I report Cobrowse stanno arrivando normalmente.',
            'guidance' => 'L\'anteprima è abbastanza aggiornata da poter essere utilizzata insieme alla chat.',
            'guidance_pressure' => 'Usi la chat per confermare tutto ciò che dipende da uno stato della pagina che cambia rapidamente.',
            'recovery_action' => 'Nessuna azione di ripristino necessaria.',
        ],
    ],
    'consent' => [
        'unavailable' => [
            'label' => 'Non disponibile',
            'message' => 'Il visitatore non ha concesso il consenso al cobrowsing.',
        ],
        'pending' => [
            'label' => 'Consenso in attesa',
            'message' => 'In attesa del consenso del visitatore prima che la cobrowse possa iniziare.',
        ],
        'granted' => [
            'label' => 'Concesso',
            'message' => 'Il visitatore ha concesso il consenso al cobrowsing.',
        ],
        'revoked' => [
            'label' => 'Revocato',
            'message' => 'Il visitatore ha revocato il consenso al cobrowse.',
        ],
        'ended' => [
            'label' => 'Terminato',
            'message' => 'Sessione Cobrowse terminata.',
        ],
    ],
    'actions' => [
        'cancel_request' => 'Annulla richiesta',
        'end' => 'Termina cobrowse',
    ],
    'resync' => [
        'fulfilled' => [
            'label' => 'Nuovo snapshot ricevuto',
            'message' => 'Il widget visitatore ha inviato uno snapshot pulito e mascherato.',
        ],
        'exhausted' => [
            'label' => 'Limite massimo di tentativi per il fresh snapshot raggiunto',
            'message' => 'Il widget visitatore ha provato a inviare uno snapshot pulito ma non è riuscito a completarlo. Richieda un altro snapshot pulito o confermi lo stato della pagina tramite chat.',
        ],
        'expired' => [
            'label' => 'Snapshot recente scaduto',
            'message' => 'Il widget visitatore non ha risposto in tempo. Richieda un altro snapshot pulito o continua tramite chat.',
        ],
        'delayed' => [
            'label' => 'Snapshot recente in ritardo',
            'message' => 'Il widget visitatore non ha ancora risposto. Richieda un altro snapshot pulito o confermi lo stato della pagina tramite chat.',
        ],
        'pending' => [
            'label' => 'Snapshot aggiornato richiesto',
            'message' => 'In attesa che il widget visitatore invii uno snapshot pulito della pagina.',
        ],
    ],
    'snapshot_recovery' => [
        'pending' => [
            'label' => 'Aggiornamento dello snapshot già richiesto',
            'message' => 'Una nuova richiesta di snapshot è già in attesa sul widget del visitatore. Usi la chat mentre si aggiorna.',
        ],
        'unknown' => [
            'label' => 'L\'orario dello snapshot necessita di conferma',
            'message' => 'Chieda al visitatore cosa vede oppure richieda un nuovo snapshot prima di fare affidamento su questa anteprima.',
        ],
        'needs_refresh' => [
            'label' => 'Potrebbe essere necessario aggiornare lo snapshot',
            'message' => 'Richieda un nuovo snapshot prima di fare affidamento su questa anteprima, oppure confermi la pagina tramite chat.',
        ],
    ],
    'timeline' => [
        'requested' => [
            'label' => 'Snapshot richiesto',
            'detail' => ':actor ha richiesto al widget del visitatore uno snapshot pulito e mascherato.',
            'badge' => 'Richiesto',
        ],
        'responded' => [
            'label' => 'Il widget visitatore ha risposto',
            'detail' => 'È arrivata una nuova risposta di snapshot cobrowse dalla pagina del visitatore.',
            'badge' => 'Recuperato',
        ],
        'refreshed' => [
            'label' => 'Snapshot mascherato aggiornato',
            'detail' => 'La snapshot della pagina pulita è disponibile nell\'anteprima dell\'agente.',
            'badge' => 'Anteprima aggiornata',
        ],
        'exhausted' => [
            'label' => 'Limite di tentativi raggiunto',
            'detail' => 'Il widget visitatore ha smesso di riprovare questa richiesta ID dopo ripetuti fallimenti.',
            'badge' => 'Esausto',
        ],
        'expired' => [
            'label' => 'Richiesta scaduta',
            'detail' => 'Nessuna risposta dal widget è arrivata prima che la finestra di recupero si chiudesse.',
            'badge' => 'Scaduto',
        ],
        'retry_available' => [
            'label' => 'Riprova disponibile',
            'detail' => 'Il supporto può richiedere un\'altra snapshot pulita senza dover attendere la prima richiesta.',
            'badge' => 'Riprova',
        ],
        'expires' => [
            'label' => 'La richiesta scade',
            'detail' => 'Wayfindr smetterà di pubblicizzare questa richiesta obsoleta dopo la finestra di scadenza.',
            'badge' => 'Guardrail',
        ],
        'waiting' => [
            'label' => 'In attesa del widget visitatore',
            'detail' => 'Riprova apre :elapsed.',
            'detail_unknown' => 'Retry si apre quando si apre la finestra di ripetizione.',
            'badge' => 'In sospeso',
        ],
        'ignored' => [
            'label' => 'Risposta snapshot ignorata',
            'badge' => 'Ignorato',
            'expired' => 'È arrivata una risposta del widget dopo la chiusura della finestra di ripristino.',
            'mismatched' => 'È arrivata una risposta del widget per una richiesta di recupero diversa.',
            'already_fulfilled' => 'È arrivata una risposta duplicata del widget dopo che Wayfindr aveva già accettato un nuovo snapshot.',
            'unmatched' => 'Non è stato possibile associare una risposta del widget alla richiesta di ripristino attiva.',
        ],
    ],
    'freshness' => [
        'unknown' => [
            'label' => 'Ora sconosciuta',
            'message' => 'Usi la chat per confermare cosa vede il visitatore prima di fare affidamento su questa anteprima.',
        ],
        'stale' => [
            'label' => 'Stantio',
            'message' => 'Lo snapshot ha più di 5 minuti. Confermi tramite chat o richieda uno snapshot aggiornato.',
        ],
        'aging' => [
            'label' => 'Invecchiamento',
            'message' => 'Lo snapshot ha alcuni minuti. Richieda un nuovo snapshot se questa pagina sta cambiando.',
        ],
        'fresh' => [
            'label' => 'Recente',
            'message' => 'Lo snapshot è stato segnalato di recente.',
        ],
        'reported' => 'Segnalato :elapsed',
        'reported_unknown' => 'Orario del report non disponibile',
    ],
    'units' => [
        'untitled_page' => 'Pagina senza titolo',
        'applied' => ':count applicato',
        'milliseconds' => ':count ms',
        'bytes' => ':count byte',
        'no_text_preview' => 'Nessuna anteprima del testo segnalata.',
        'viewport' => 'Viewport visitatore :widthpx',
        'still_active' => 'Ancora attivo',
        'not_granted_yet' => 'Non ancora concesso',
        'batches' => '{1} 1 lotto|[2,*] :count lotti',
        'mutations' => '{1} 1 mutazione|[2,*] :count mutazioni',
        'dropped' => ':count interrotto',
        'skipped' => ':count saltato',
        'sequence' => 'Sequenza :count',
        'nodes' => '{1} 1 nodo|[2,*] :count nodi',
        'masked' => ':count mascherato',
        'not_reported' => 'Non riportato',
        'focused' => 'Concentrato',
        'not_focused' => 'Non focalizzato',
        'unknown_agent' => 'Agente sconosciuto',
        'visitor' => 'Visitatore',
        'not_recorded' => 'Non registrato',
    ],
    'drift' => [
        'steady' => [
            'label' => 'Allineato',
            'message' => 'Gli aggiornamenti di replay stanno arrivando sui nodi previsti.',
        ],
        'watch' => [
            'label' => 'Leggera deviazione',
            'message' => 'Alcuni aggiornamenti di replay non corrispondono a questa anteprima. Confermi le aree che cambiano rapidamente tramite chat.',
        ],
        'drifting' => [
            'label' => 'Deriva',
            'message' => 'Molti aggiornamenti di replay non corrispondono più a questa anteprima. Richieda un nuovo snapshot per risincronizzare.',
        ],
        'summary' => ':unresolved di :addressable ha subito uno spostamento',
    ],
    'pressure' => [
        'dropped' => '{1} 1 lotto scartato|[2,*] :count lotti scartati',
        'skipped' => '{1} 1 mutazione saltata|[2,*] :count mutazioni saltate',
        'separator' => ', ',
        'none' => 'Nessuna perdita segnalata',
        'none_recent' => 'Nessuna recente interruzione segnalata',
    ],
    'labels' => [
        'retry_ready_help' => 'In attesa. Può richiedere subito un nuovo snapshot aggiornato.',
        'retry_ready_recovery' => 'Richieda un altro nuovo snapshot se l\'anteprima sembra ancora non aggiornata.',
        'request_snapshot' => 'Richiedi un nuovo snapshot',
        'request_another_snapshot' => 'Richiedi un altro snapshot aggiornato',
        'requested_by' => 'Richiesto da :actor',
        'received' => 'Ricevuto :elapsed',
        'expires' => 'Scade :elapsed',
        'expired' => 'Scaduto :elapsed',
    ],
    'realtime' => [
        'listening' => 'In ascolto degli aggiornamenti in tempo reale sulla conversazione.',
        'disconnected' => 'Aggiornamenti in tempo reale disconnessi. Riconnessione in corso…',
        'unavailable' => 'Gli aggiornamenti live di cobrowse non sono disponibili in questo browser.',
        'failed' => 'Impossibile connettersi agli aggiornamenti live di cobrowse.',
        'unauthorized' => 'Autorizzazione alla trasmissione non riuscita.',
        'telemetry_updated' => 'Telemetria della connessione aggiornata in tempo reale.',
        'snapshot_received' => 'Nuovo snapshot ricevuto. Aggiornamento dell\'anteprima in corso…',
        'snapshot_received_idle' => 'Snapshot live ricevuto. Aggiorni l\'anteprima quando è pronto.',
        'changes_received' => 'Nuove modifiche di cobrowse ricevute. Aggiornamento dell\'anteprima in corso…',
        'update_available' => 'Nuovo aggiornamento cobrowse disponibile. Aggiorni l\'anteprima quando è pronto.',
        'preview_updated' => 'Anteprima aggiornata con le ultime modifiche di cobrowse.',
        'preview_refreshing' => 'Aggiornamento dell\'anteprima in corso…',
        'preview_refresh_failed' => 'Impossibile aggiornare automaticamente l\'anteprima. Usi Aggiorna anteprima per riprovare.',
        'preview_failed' => 'Aggiornamento dell\'anteprima non riuscito: :reason',
        'transcript_failed' => 'Aggiornamento della trascrizione non riuscito: :reason',
        'retry_limit' => 'Limite massimo di tentativi per un nuovo snapshot raggiunto. Richieda un altro nuovo snapshot quando è pronto.',
    ],
    'visibility' => [
        'visible' => 'Visibile',
        'hidden' => 'Nascosto',
        'prerender' => 'Prerendering',
        'unknown' => 'Non segnalato',
    ],
];
