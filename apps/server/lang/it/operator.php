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
];
