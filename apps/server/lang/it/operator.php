<?php

/*
 * Drafted from lang/en/operator.php. NOT YET REVIEWED.
 *
 * Machine-assisted draft against the glossary and the formal-register rules
 * in docs/product/translation-policy.md. Product names, language autonyms and
 * IANA timezone identifiers keep their own language at the rendering boundary.
 */
return [
    'shell' => [
        'sections_label' => 'Sezioni del gestore',
        'heading' => 'Gestore',
        'back' => 'Indietro',
        'back_to_setup' => 'Torna alla checklist di configurazione',
        'back_to_console' => 'Torna alla console del gestore',
        'sections' => [
            'console' => 'Pannello di controllo',
            'onboarding' => 'Checklist di configurazione',
            'mail' => 'Posta',
            'storage' => 'Archiviazione',
            'scanning' => 'Scansione',
            'backups' => 'Backup',
            'localization' => 'Lingua e area geografica',
            'operator_access' => 'Accesso del gestore',
        ],
    ],

    'localization' => [
        'document_title' => 'Lingua e area geografica',
        'title' => 'Lingua e area geografica',
        'subtitle' => 'La lingua usata dalla dashboard per chi non ne ha scelta una. Le modifiche vengono applicate subito, senza riavvio.',
        'heading' => 'Impostazioni predefinite dell’installazione',
        'lede' => 'Sono valori predefiniti, non regole. Un agente che sceglie nel profilo la propria lingua o il proprio fuso orario mantiene la scelta; questi valori si applicano a tutti gli altri, cioè a tutti in una nuova installazione.',
        'language' => 'Lingua',
        'language_help' => 'Si applica alla dashboard degli agenti. Ciò che un visitatore vede nel widget dipende dal suo browser e non viene modificato da questa impostazione.',
        'timezone' => 'Fuso orario',
        'timezone_help' => 'Gli orari e i giorni dei report seguono questo fuso. I record vengono sempre memorizzati in UTC, quindi la modifica rilegge la cronologia esistente senza riscriverla.',
        'save' => 'Salva lingua e area geografica',
        'flash' => [
            'saved' => 'Lingua e area geografica salvate. Gli agenti che non hanno effettuato una scelta ora leggono la dashboard in questo modo.',
        ],
    ],

    'scanning' => [
        'document_title' => 'Impostazioni di scansione',
        'title' => 'Scansione degli allegati',
        'subtitle' => 'Esegue la scansione antimalware dei file caricati prima dell’archiviazione. Le modifiche vengono applicate subito, senza riavvio.',
        'heading' => 'Scanner antimalware',
        'lede' => 'Senza uno scanner, i caricamenti vengono comunque accettati con una protezione a più livelli: elenco dei tipi consentiti verificato dai byte, archiviazione privata, download forzato e nosniff, ma senza scansione antivirus.',
        'driver' => 'Scanner',
        'external_driver_help' => 'Lo scanner attuale è configurato nell’ambiente: :driver. Il salvataggio delle altre impostazioni lo manterrà selezionato.',
        'none' => 'Nessuno (accetta con protezione a più livelli)',
        'driver_help' => 'ClamAV viene eseguito localmente, quindi i file non lasciano mai il server per la scansione. Selezioni ClamAV e imposti il socket clamd qui sotto.',
        'socket' => 'Socket ClamAV',
        'socket_help' => 'Un indirizzo TCP (:tcp) o un socket Unix (:unix) per il servizio clamd in esecuzione.',
        'fail_closed' => 'Rifiuta i caricamenti quando lo scanner non è raggiungibile (fail-closed — consigliato). Se l’opzione non è selezionata, i caricamenti vengono accettati senza scansione quando lo scanner non è disponibile.',
        'save' => 'Salva le impostazioni di scansione',
        'test_heading' => 'Verifica della raggiungibilità',
        'test_lede' => 'Consente di confermare che lo scanner configurato sia in esecuzione e risponda, senza usare il terminale.',
        'test' => 'Verifica lo scanner',
        'flash' => [
            'saved' => 'Impostazioni di scansione salvate. Verifichi la raggiungibilità per confermare che lo scanner risponda.',
            'none' => 'Non è configurato alcuno scanner: i caricamenti vengono accettati con una protezione a più livelli (elenco dei tipi consentiti, archiviazione privata e download forzato), ma senza scansione antivirus. Selezioni ClamAV e salvi per abilitare la scansione.',
            'misconfigured' => 'Lo scanner non è configurato correttamente: :message',
            'reachable' => 'Scanner raggiungibile: lo scanner :driver ha risposto. I caricamenti verranno sottoposti a scansione prima dell’archiviazione.',
            'unreachable' => 'Lo scanner :driver è configurato ma non è raggiungibile all’indirizzo :socket. Verifichi che clamd sia in esecuzione e che il socket sia corretto.',
        ],
    ],

    'mail' => [
        'document_title' => 'Impostazioni email',
        'title' => 'Impostazioni email',
        'subtitle' => 'Qui è possibile configurare la posta in uscita senza modificare la configurazione del server o riavviare. Le modifiche vengono applicate subito.',
        'heading' => 'Posta in uscita',
        'lede' => 'Le email di avviso e di reimpostazione della password non vengono recapitate finché non viene impostato un trasporto reale.',
        'transport' => 'Trasporto',
        'external_transport_help' => 'Il trasporto attuale è configurato nell’ambiente: :transport. Il salvataggio delle altre impostazioni lo manterrà selezionato.',
        'log_only' => 'Solo registro (nessun recapito)',
        'transport_help' => 'Selezioni SMTP e compili i dettagli qui sotto per inviare email reali.',
        'host' => 'Host SMTP',
        'port' => 'Porta SMTP',
        'encryption' => 'Crittografia',
        'encryption_auto' => 'Automatica (in base alla porta)',
        'username' => 'Nome utente SMTP',
        'password' => 'Password SMTP',
        'password_placeholder_unreadable' => 'Impossibile leggere la password salvata — la inserisca di nuovo',
        'password_placeholder_configured' => '•••••••• (è configurata una password)',
        'password_placeholder_none' => 'Nessuna password salvata',
        'password_unreadable' => 'Non è stato possibile decifrare la password salvata. Questo può accadere dopo una modifica di APP_KEY. La inserisca di nuovo qui sotto oppure selezioni “Nessuna password” se il server non ne richiede una.',
        'password_help' => 'Lasci il campo vuoto per mantenere la password salvata. Viene archiviata in forma crittografata e non viene mai mostrata.',
        'no_password' => 'Questo server non richiede una password (relay non autenticato)',
        'from_address' => 'Indirizzo mittente',
        'from_name' => 'Nome mittente',
        'save' => 'Salva le impostazioni email',
        'test_heading' => 'Invio di un’email di prova',
        'test_lede' => 'Consente di verificare il recapito con le impostazioni salvate, senza usare il terminale.',
        'recipient' => 'Destinatario',
        'send_test' => 'Invia email di prova',
        'flash' => [
            'saved' => 'Impostazioni email salvate. Invii un’email di prova per confermare il recapito.',
            'non_delivering_unset' => 'Il trasporto email non è ancora impostato: un messaggio di prova non verrebbe recapitato. Selezioni SMTP qui sopra, salvi e poi esegua la prova.',
            'non_delivering' => 'Il trasporto email è ancora :transport: un messaggio di prova non verrebbe recapitato. Selezioni SMTP qui sopra, salvi e poi esegua la prova.',
            'failed' => 'Invio dell’email di prova tramite :transport non riuscito: :message',
            'may_fall_back' => 'Messaggio di prova inviato tramite la catena :transport. Se il trasporto primario non era disponibile, potrebbe essere stato scritto in un registro locale invece di essere recapitato: confermi che sia arrivato nella casella di posta.',
            'sent' => 'Email di prova inviata a :recipient tramite :transport. Controlli la casella di posta.',
        ],
    ],
];
