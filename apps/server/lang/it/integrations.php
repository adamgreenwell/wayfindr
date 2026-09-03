<?php

/*
 * Bozza tratta da lang/en/integrations.php. NON ANCORA REVISIONATA.
 *
 * Scritta a mano seguendo il glossario in resources/translation/glossary.php
 * e le regole in docs/product/translation-policy.md. Nomi dell’account, URL,
 * chiavi di progetto e diciture dell’interfaccia del provider sono dati; la
 * view li contrassegna con una lingua sconosciuta.
 *
 * I controlli usano l’imperativo diretto; la prosa esplicativa usa il registro
 * formale. «Provider» segue la terminologia già usata nei ticket.
 */

return [
    'title' => 'Integrazioni',
    'subtitle' => 'Connessioni provider per l’intero account e destinazione delle segnalazioni esterne per ogni sito.',
    'back' => 'Torna all’account',

    'flash' => [
        'connection_saved' => 'Connessione provider salvata.',
        'secret_cleared' => 'Segreto del webhook in ingresso eliminato.',
        'secret_saved' => 'Segreto del webhook in ingresso salvato.',
        'capabilities_updated' => 'Funzioni del provider aggiornate.',
    ],

    'connections' => [
        'heading' => 'Connessioni provider',
        'count' => '{1} :count connessione|[2,*] :count connessioni',
        'account_owned' => 'di proprietà dell’account',
        'admin_hint' => 'Le connessioni provider sono gestite da un amministratore dell’account. Chieda a un amministratore di aggiungere o modificare le connessioni; dopo la configurazione ogni agente può usarle dai ticket.',
        'empty' => 'Ancora nessuna connessione provider.',
        'empty_admin' => 'Colleghi :providers qui sotto con un token API per consentire agli agenti di trasferire i ticket come segnalazioni esterne.',
        'enabled' => 'Abilitata',
        'disabled' => 'Disabilitata',

        'setup' => [
            'heading' => 'Ordine di configurazione della connessione',
            'save_title' => '1. Salvi prima la connessione provider.',
            'save_body' => 'Wayfindr crea il suo URL univoco per il webhook in ingresso solo dopo che la connessione esiste.',
            'copy_title' => '2. Copi l’URL del webhook generato.',
            'copy_body' => 'Appare con la connessione salvata sopra questo modulo.',
            'configure_title' => '3. Configuri il webhook del provider.',
            'configure_body' => 'Incolli l’URL in :providers, riutilizzi lo stesso segreto del webhook e selezioni gli eventi di stato e commento della segnalazione.',
            'map_title' => '4. Associ un sito a un progetto.',
            'map_body' => 'Torni qui e apra il sito in Associazioni fra siti e progetti, così i ticket sanno quale repository o progetto deve riceverli.',
            'outbound_only' => 'I provider senza un destinatario per webhook in ingresso possono comunque essere usati per le funzioni in uscita che supportano.',
        ],
    ],

    'capabilities' => [
        'heading' => 'Funzioni della connessione',
        'help' => 'Scelga cosa possono inviare gli agenti tramite questa connessione salvata. I webhook in ingresso firmati vengono autenticati separatamente con il segreto condiviso.',
        'aria' => 'Funzioni della connessione :connection',
        'update' => 'Aggiorna funzioni',
        'labels' => [
            'create_issue' => 'Crea segnalazioni',
            'add_comment' => 'Aggiunge commenti',
            'sync_status' => 'Sincronizza stato',
        ],
        'permissions' => [
            'create_issue' => 'Il provider può creare segnalazioni',
            'add_comment' => 'Il provider può aggiungere commenti',
            'sync_status' => 'Il provider può sincronizzare lo stato',
        ],
    ],

    'webhook' => [
        'verified_title' => 'Sincronizzazione in ingresso verificata.',
        'verified_body' => 'Wayfindr ha accettato una consegna firmata del provider :elapsed.',
        'latest' => 'Ultimo evento verificato: :event · Stato HTTP: :status',
        'unknown' => 'sconosciuto',
        'configured_title' => 'Sincronizzazione in ingresso configurata, non verificata.',
        'configured_body' => 'È stato salvato un segreto, ma Wayfindr non ha ancora accettato una consegna firmata del provider.',
        'missing_title' => 'Sincronizzazione in ingresso non configurata.',
        'missing_body' => 'Imposti un segreto webhook per questa connessione e indirizzi il provider all’URL seguente per sincronizzare lo stato della segnalazione.',
        'generated_url' => 'URL del webhook generato',
        'settings_aria' => 'Impostazioni del webhook in ingresso per :connection',
        'provider_destination_title' => 'Destinazione del provider:',
        'provider_destination_body' => 'Incolli l’URL generato nelle impostazioni webhook di questa connessione.',
        'github_title' => 'Impostazioni GitHub:',
        'github_body' => 'Usi :content_type, mantenga abilitata la verifica SSL e selezioni i singoli eventi :issues e :comments.',
        'gitlab_title' => 'Impostazioni GitLab:',
        'gitlab_body' => 'Usi l’URL generato, inserisca lo stesso valore in :secret_token e abiliti :issues e :comments.',
        'jira_title' => 'Impostazioni Jira:',
        'jira_body' => 'Usi l’URL generato e lo stesso segreto, quindi si iscriva alle modifiche di stato della segnalazione e agli eventi di creazione dei commenti.',
        'shared_secret_title' => 'Segreto condiviso:',
        'shared_secret_body' => 'Il segreto deve coincidere in Wayfindr e nel provider. Se lo sostituisce qui, lo sostituisca anche lì.',
        'replace_secret' => 'Sostituisci segreto webhook',
        'set_secret' => 'Imposta segreto webhook',
        'update_secret' => 'Aggiorna segreto',
        'enable' => 'Abilita sincronizzazione in ingresso',
    ],

    'create' => [
        'heading' => 'Aggiungi connessione provider',
        'available' => 'Disponibile per ogni sito di questo account',
        'provider' => 'Provider',
        'name' => 'Nome della connessione',
        'name_placeholder' => 'GitHub ingegneria',
        'base_url' => 'URL di base',
        'credential' => 'Token o segnaposto della credenziale',
        'webhook_secret' => 'Segreto del webhook in ingresso',
        'webhook_help' => 'Ora è facoltativo. Salvi prima questa connessione per generarne l’URL webhook. Può inserire subito un segreto e riutilizzarlo nel provider, oppure lasciare vuoto il campo e impostare entrambi i lati quando appare l’URL. :github firma con :github_header, :jira con :jira_header e :gitlab lo invia nell’header :gitlab_header.',
        'submit' => 'Salva connessione provider',
    ],

    'mappings' => [
        'heading' => 'Associazioni fra siti e progetti',
        'count' => '{1} :mapped su :total sito con associazioni|[2,*] :mapped su :total siti con associazioni',
        'help' => 'Le associazioni ai progetti valgono per ogni sito: ciascun sito sceglie il progetto esterno a cui trasferire i propri ticket. Associ i progetti dalla pagina del sito.',
        'empty' => 'Ancora nessun sito.',
        'unmapped' => 'Ancora nessun progetto esterno associato.',
        'map' => 'Associa un progetto',
        'manage' => 'Gestisci',
    ],

    'providers' => [
        'setup_list' => ':github, :gitlab o :jira',
        'other' => 'Altro',
        'external_tracker' => 'Sistema esterno',
    ],
];
