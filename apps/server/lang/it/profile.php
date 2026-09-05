<?php

/*
 * Drafted from lang/en/profile.php. NOT YET REVIEWED.
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
 * 1 plural string(s) need their segment count checked against it.
 * /
 * /
 * /
 * /
 */
return [
    'document_title' => 'Profilo agente',
    'title' => 'Profilo agente',
    'subtitle' => 'Mantenga aggiornata la sua identità di agente e la password di accesso.',
    'context' => [
        'email' => 'Email',
        'account' => 'Account',
        'role' => 'Ruolo',
        'member_since' => 'Membro dal',
        'member_since_unknown' => 'Sconosciuto',
    ],
    'roles' => [
        'owner' => 'Titolare',
        'admin' => 'Admin',
        'agent' => 'Agente',
    ],
    'details' => [
        'heading' => 'Il suo profilo',
        'lede' => 'Il suo nome e la lingua in cui legge questo',
        'name' => 'Nome',
        'email_help' => 'La sua email viene utilizzata per l’accesso. Chieda a un titolare se deve essere modificata.',
        'language' => 'Lingua della dashboard',
        'language_default' => 'Usa l\'impostazione predefinita di installazione',
        'language_help' => 'Solo suo. Cambia la dashboard solo per lei e per nessun altro, e non influisce sulla lingua in cui il widget parla ai suoi visitatori — quella è impostata per ogni sito.',
        'timezone' => 'Fuso orario',
        'timezone_default' => 'Usa l\'impostazione predefinita dell\'installazione',
        'timezone_help' => 'Orari e date in tutta la dashboard sono mostrati in questo fuso, compreso il giorno a cui un report attribuisce un evento.',
        'save' => 'Salva profilo',
    ],

    'routing' => [
        'heading' => 'Disponibilità per le assegnazioni',
        'label' => 'Stato per l’assegnazione automatica',
        'online' => 'Disponibile',
        'away' => 'Assente',
        'help' => 'Disponibile consente ai siti abilitati di assegnarle nuovo lavoro finché può riceverne altro. Assente interrompe le nuove assegnazioni automatiche; il lavoro già assegnato resta invariato.',
        'save' => 'Salva disponibilità',
    ],
    'readiness' => [
        'heading' => 'Prontezza allerta',
        'lede' => 'Il suo attuale percorso del segnale di supporto',
    ],
    'readiness_cards' => [
        'dashboard_label' => 'Avvisi dashboard',
        'paused' => 'In pausa',
        'quiet_detail' => 'La modalità silenziosa sopprime i nuovi avvisi sulla dashboard e le notifiche email.',
        'listening' => 'Ascolto',
        'listening_detail' => 'Riceverà avvisi sulla dashboard per il lavoro di supporto idoneo.',
        'scope_label' => 'Ambito degli avvisi',
        'scope_assigned' => 'Assegnato a me',
        'scope_assigned_detail' => 'Solo le conversazioni e i ticket assegnati a lei generano nuovi avvisi.',
        'scope_quiet' => 'Modalità silenziosa',
        'scope_quiet_detail' => 'Il suo ambito è in pausa finché la modalità silenziosa non viene disattivata.',
        'scope_all' => 'Tutto il lavoro di supporto',
        'scope_all_detail' => 'Le conversazioni e i ticket che può gestire possono generare nuovi avvisi.',
        'email_label' => 'Consegna email',
        'email_off' => 'Solo dashboard',
        'email_off_detail' => 'Le notifiche email sono disattivate per il suo profilo.',
        'email_ready' => 'Pronto',
        'email_ready_detail' => 'Gli avvisi email sono abilitati e la posta in uscita sembra configurata.',
        'email_setup' => 'Richiede configurazione',
        'push_label' => 'Avvisi a dashboard chiusa',
        'push_off' => 'Disattivati',
        'push_off_detail' => 'Web Push è disattivato per il suo profilo.',
        'push_paused' => 'In pausa',
        'push_paused_detail' => 'La modalità silenziosa sopprime gli avvisi a dashboard chiusa finché non viene disattivata.',
        'push_setup' => 'Configurazione del gestore richiesta',
        'push_setup_detail' => 'La preferenza è attiva, ma questa installazione non dispone di una configurazione Web Push completa.',
        'push_browser' => 'Abiliti questo browser',
        'push_browser_detail' => 'Salvi le preferenze degli avvisi in un browser supportato e consenta le notifiche per completare l’iscrizione.',
        'push_ready' => 'Pronto',
        'push_ready_detail' => '{1} :count browser può ricevere avvisi dopo la chiusura della dashboard.|[2,*] :count browser possono ricevere avvisi dopo la chiusura della dashboard.',
        'cadence_label' => 'Cadenza',
        'cadence_unattended' => 'Solo non presidiato',
        'cadence_immediate' => 'Immediato',
        'cadence_immediate_detail' => 'I nuovi avvisi idonei possono notificare immediatamente quando gli avvisi email sono abilitati.',
        'cadence_digest' => 'Riepilogo',
        'cadence_digest_off_detail' => 'La preferenza per il riepilogo è stata salvata, ma le notifiche email sono disattivate.',
        'cadence_unattended_detail' => 'L\'email viene inviata solo quando un messaggio del visitatore rimane non visualizzato per :minutes minuti.',
        'cadence_unattended_off_detail' => 'La preferenza per le notifiche non presidiate è stata salvata, ma gli avvisi email sono disattivati.',
        'cadence_digest_detail' => 'La consegna del riepilogo è preferita. Ultimo riepilogo: :latest.',
    ],
    'alerts' => [
        'heading' => 'Preferenze di avviso',
        'lede' => 'Mantieni utili i segnali di supporto',
        'guidance_heading' => 'Come si comportano gli avvisi',
        'guidance_dashboard' => 'Gli avvisi della dashboard sono la fonte principale di verità per il lavoro di supporto che richiede attenzione.',
        'guidance_email' => 'Gli avvisi email sono una modalità di consegna opzionale, non una coda separata.',
        'guidance_quiet' => 'La modalità silenziosa mette in pausa i nuovi avvisi senza modificare le assegnazioni, l’accesso al sito o la responsabilità di supporto.',
        'mode' => 'Modalità avviso',
        'email_alerts' => 'Avvisi email',
        'sound_alerts' => 'Riproduci un suono per i nuovi avvisi della dashboard',
        'sound_help' => 'Un breve tono locale viene riprodotto solo mentre questa dashboard è aperta in background. Il browser potrebbe attendere un’interazione con la pagina prima di consentire l’audio.',
        'push_alerts' => 'Invia notifiche a questo browser dopo la chiusura della dashboard',
        'push_help' => 'Al salvataggio questo browser chiede il permesso per le notifiche e completa l’iscrizione. Disattivi l’opzione per annullare l’iscrizione di questo browser; gli altri browser iscritti restano attivi.',
        'push_unavailable' => 'Un gestore della piattaforma deve configurare Web Push prima che i browser possano iscriversi. La preferenza attuale viene mantenuta.',
        'push_unsupported' => 'Questo browser o questa connessione non può usare Web Push. La preferenza attuale è stata mantenuta.',
        'push_failed' => 'Non è stato possibile iscrivere questo browser. Controlli il permesso per le notifiche e riprovi.',
        'push_invalid_endpoint' => 'Questo browser ha fornito un indirizzo del servizio push che Wayfindr non può contattare in sicurezza.',
        'push_limit' => 'Questo profilo ha già il massimo di 10 browser iscritti.',
        'push_owned_elsewhere' => 'L’iscrizione di un altro profilo connesso è stata rimossa da questo browser senza riassegnarne il record dell’account.',
        'push_owned_elsewhere_cleanup_failed' => 'L’iscrizione di un altro profilo connesso è ancora attiva in questo browser. Ricarichi la dashboard per riprovare prima di abbandonarla.',
        'push_notification_title' => 'Nuovo avviso Wayfindr',
        'push_notification_body' => 'Apra Wayfindr per controllarlo.',
        'cadence' => 'Cadenza email',
        'cadence_help' => 'La consegna del riepilogo raggruppa le email di avviso idonee quando viene eseguito il pianificatore. La modalità non presidiata invia email solo quando un messaggio di un visitatore resta non visualizzato. Gli avvisi della dashboard restano immediati.',
        'last_digest' => 'Ultimo riepilogo',
        'email_help' => 'Gli avvisi email inviano gli stessi segnali di supporto tranquilli alla sua casella di posta quando la posta è configurata. La modalità silenziosa continua comunque a sopprimere i nuovi avvisi.',
        'delivery_ready' => 'Consegna email pronta',
        'delivery_attention' => 'La consegna delle email richiede attenzione',
        'save' => 'Salva le preferenze degli avvisi',
        'modes' => [
            'all' => 'Tutti gli avvisi del sito che posso supportare',
            'assigned' => 'Solo conversazioni e ticket assegnati a me',
            'quiet' => 'Modalità silenziosa',
        ],
        'cadences' => [
            'immediate' => 'Invia avvisi email non appena si verificano',
            'unattended' => 'Invia email solo quando un visitatore aspetta senza essere visto',
            'digest' => 'Preferisci la consegna in formato riepilogativo quando disponibile',
        ],
    ],
    'digest' => [
        'no_alerts_message' => 'Nessun avviso pronto per il riepilogo trovato.',
        'failed_message' => 'Impossibile mettere in coda l\'email riepilogativa.',
        'never_message' => 'Nessuna esecuzione del riepilogo è stata ancora registrata.',
        'queued_message' => '{1} Email riepilogativa in coda con avviso :count.|[2,*] Email riepilogativa in coda con avvisi :count.',
        'queued_label' => 'Email riepilogativa in coda',
        'no_alerts_label' => 'Nessun avviso pronto per il riepilogo',
        'failed_label' => 'Consegna del riepilogo non riuscita',
        'never_label' => 'Non ancora eseguito',
    ],
    'flash' => [
        'profile_updated' => 'Profilo aggiornato.',
        'alerts_updated' => 'Preferenze di avviso aggiornate.',
        'password_updated' => 'Password aggiornata.',
        'routing_status_updated' => 'Disponibilità per le assegnazioni aggiornata.',
    ],
    'password' => [
        'heading' => 'Cambia password',
        'lede' => 'Usi questo dopo aver ricevuto una password temporanea',
        'current' => 'Password attuale',
        'new' => 'Nuova password',
        'confirm' => 'Conferma nuova password',
        'save' => 'Aggiorna password',
    ],
];
