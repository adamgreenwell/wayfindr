<?php

return [
    // The browser tab and breadcrumb. Shipped as "Agent Profile" while the
    // page heading says "Agent profile" -- the same page named two ways on one
    // screen. Preserved exactly rather than tidied, because an extraction that
    // quietly edits copy is an extraction nobody can trust; filed to be fixed
    // on purpose instead.
    'document_title' => 'Agentenprofil',
    'title' => 'Agentenprofil',
    'subtitle' => 'Halten Sie Ihre Agentendaten und Ihr Anmeldepasswort aktuell.',

    'context' => [
        'email' => 'E-Mail',
        'account' => 'Konto',
        'role' => 'Rolle',
        'member_since' => 'Mitglied seit',
        'member_since_unknown' => 'Unbekannt',
    ],

    'roles' => [
        'owner' => 'Inhaber',
        'admin' => 'Administrator',
        'agent' => 'Agent',
    ],

    'details' => [
        'heading' => 'Ihr Profil',
        'lede' => 'Ihr Name und die Sprache, in der Sie dies lesen',
        'name' => 'Name',
        'email_help' => 'Ihre E-Mail-Adresse dient zur Anmeldung. Wenden Sie sich an einen Inhaber, wenn sie geändert werden muss.',
        'language' => 'Sprache des Dashboards',
        'language_default' => 'Voreinstellung der Installation verwenden',
        'language_help' => 'Gilt nur für Sie. Es ändert das Dashboard für Sie und niemanden sonst und hat keinen Einfluss darauf, welche Sprache das Widget mit Ihren Besuchern spricht — das wird pro Website festgelegt.',
        'save' => 'Profil speichern',
    ],

    'readiness' => [
        'heading' => 'Bereitschaft für Benachrichtigungen',
        'lede' => 'Ihr aktueller Weg für Support-Signale',
    ],

    'alerts' => [
        'heading' => 'Benachrichtigungseinstellungen',
        'lede' => 'Halten Sie Support-Signale nützlich',
        'guidance_heading' => 'Wie Benachrichtigungen funktionieren',
        'guidance_dashboard' => 'Benachrichtigungen im Dashboard sind maßgeblich für Support-Arbeit, die Aufmerksamkeit braucht.',
        'guidance_email' => 'E-Mail-Benachrichtigungen sind eine optionale Zustellung, keine eigene Warteschlange.',
        'guidance_quiet' => 'Der Ruhemodus pausiert neue Benachrichtigungen, ohne Zuweisungen, Website-Zugriff oder Support-Verantwortung zu ändern.',
        'mode' => 'Benachrichtigungsmodus',
        'email_alerts' => 'E-Mail-Benachrichtigungen',
        'cadence' => 'E-Mail-Rhythmus',
        'cadence_help' => 'Die Sammelzustellung bündelt geeignete E-Mail-Benachrichtigungen, wenn der Planer läuft. „Unbeantwortet" sendet nur dann eine E-Mail, wenn eine Besuchernachricht ungesehen wartet. Benachrichtigungen im Dashboard bleiben sofort.',
        'last_digest' => 'Letzte Sammelmeldung',
        'email_help' => 'E-Mail-Benachrichtigungen senden dieselben ruhigen Support-Signale an Ihren Posteingang, sofern E-Mail eingerichtet ist. Der Ruhemodus unterdrückt neue Benachrichtigungen weiterhin.',
        'delivery_ready' => 'E-Mail-Zustellung bereit',
        'delivery_attention' => 'E-Mail-Zustellung benötigt Aufmerksamkeit',
        'save' => 'Benachrichtigungseinstellungen speichern',

        'modes' => [
            'all' => 'Alle Website-Benachrichtigungen, die ich betreuen kann',
            'assigned' => 'Nur mir zugewiesene Konversationen und Tickets',
            'quiet' => 'Ruhemodus',
        ],

        'cadences' => [
            'immediate' => 'E-Mail-Benachrichtigungen sofort senden',
            'unattended' => 'Nur per E-Mail, wenn ein Besucher ungesehen wartet',
            'digest' => 'Sammelzustellung bevorzugen, wenn verfügbar',
        ],
    ],

    'password' => [
        'heading' => 'Passwort ändern',
        'lede' => 'Verwenden Sie dies, nachdem Sie ein temporäres Passwort erhalten haben',
        'current' => 'Aktuelles Passwort',
        'new' => 'Neues Passwort',
        'confirm' => 'Neues Passwort bestätigen',
        'save' => 'Passwort aktualisieren',
    ],
];
