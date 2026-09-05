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
        'language_help' => 'Gilt nur für Sie. Es ändert das Dashboard für Sie und niemanden sonst und hat keinen Einfluss darauf, welche Sprache das Widget mit Ihren Besuchenden spricht — das wird pro Website festgelegt.',
        'timezone' => 'Zeitzone',
        'timezone_default' => 'Voreinstellung der Installation verwenden',
        'timezone_help' => 'Uhrzeiten und Daten im gesamten Dashboard werden in dieser Zone angezeigt, einschließlich der Frage, welchem Tag ein Bericht einen Vorgang zuordnet.',
        'save' => 'Profil speichern',
    ],

    'routing' => [
        'heading' => 'Verfügbarkeit für Zuweisungen',
        'label' => 'Status für automatische Zuweisungen',
        'online' => 'Verfügbar',
        'away' => 'Abwesend',
        'help' => 'Online erlaubt aktivierten Websites, Ihnen neue Arbeit zuzuweisen, solange Sie Kapazität haben. Abwesend stoppt neue automatische Zuweisungen; bereits zugewiesene Arbeit bleibt bestehen.',
        'save' => 'Verfügbarkeit speichern',
    ],

    'readiness' => [
        'heading' => 'Bereitschaft für Benachrichtigungen',
        'lede' => 'Ihr aktueller Weg für Support-Signale',
    ],

    'readiness_cards' => [
        'dashboard_label' => 'Dashboard-Benachrichtigungen',
        'paused' => 'Pausiert',
        'quiet_detail' => 'Der Ruhemodus unterdrückt neue Dashboard- und E-Mail-Benachrichtigungen.',
        'listening' => 'Aktiv',
        'listening_detail' => 'Sie erhalten Dashboard-Benachrichtigungen für infrage kommende Support-Arbeit.',
        'scope_label' => 'Benachrichtigungsumfang',
        'scope_assigned' => 'Mir zugewiesen',
        'scope_assigned_detail' => 'Nur Ihnen zugewiesene Unterhaltungen und Tickets erzeugen neue Benachrichtigungen.',
        'scope_quiet' => 'Ruhemodus',
        'scope_quiet_detail' => 'Ihr Umfang ist pausiert, bis der Ruhemodus ausgeschaltet wird.',
        'scope_all' => 'Gesamte Support-Arbeit',
        'scope_all_detail' => 'Unterhaltungen und Tickets, die Sie betreuen können, können neue Benachrichtigungen erzeugen.',
        'email_label' => 'E-Mail-Zustellung',
        'email_off' => 'Nur Dashboard',
        'email_off_detail' => 'E-Mail-Benachrichtigungen sind für Ihr Profil ausgeschaltet.',
        'email_ready' => 'Bereit',
        'email_ready_detail' => 'E-Mail-Benachrichtigungen sind aktiviert und der Postausgang scheint eingerichtet.',
        'email_setup' => 'Einrichtung erforderlich',
        'push_label' => 'Benachrichtigungen bei geschlossenem Dashboard',
        'push_off' => 'Aus',
        'push_off_detail' => 'Web-Push ist für Ihr Profil ausgeschaltet.',
        'push_paused' => 'Pausiert',
        'push_paused_detail' => 'Im Ruhemodus bleiben Benachrichtigungen bei geschlossenem Dashboard unterdrückt.',
        'push_setup' => 'Einrichtung durch Betreiber erforderlich',
        'push_setup_detail' => 'Ihre Einstellung ist aktiv, aber diese Installation hat keine vollständige Web-Push-Konfiguration.',
        'push_browser' => 'Diesen Browser aktivieren',
        'push_browser_detail' => 'Speichern Sie die Benachrichtigungseinstellungen in einem unterstützten Browser und erlauben Sie Benachrichtigungen, um die Anmeldung abzuschließen.',
        'push_ready' => 'Bereit',
        'push_ready_detail' => '{1} :count Browser kann nach dem Schließen des Dashboards Benachrichtigungen empfangen.|[2,*] :count Browser können nach dem Schließen des Dashboards Benachrichtigungen empfangen.',
        'cadence_label' => 'Rhythmus',
        'cadence_unattended' => 'Nur unbeantwortet',
        'cadence_immediate' => 'Sofort',
        'cadence_immediate_detail' => 'Neue infrage kommende Benachrichtigungen können sofort zugestellt werden, wenn E-Mail-Benachrichtigungen aktiviert sind.',
        'cadence_digest' => 'Sammelmeldung',
        'cadence_digest_off_detail' => 'Die Einstellung zur Sammelzustellung ist gespeichert, aber E-Mail-Benachrichtigungen sind ausgeschaltet.',
        'cadence_unattended_detail' => 'Eine E-Mail geht nur raus, wenn eine Besuchernachricht :minutes Minuten lang ungesehen bleibt.',
        'cadence_unattended_off_detail' => 'Die Einstellung „unbeantwortet“ ist gespeichert, aber E-Mail-Benachrichtigungen sind ausgeschaltet.',
        'cadence_digest_detail' => 'Sammelzustellung wird bevorzugt. Letzte Sammelmeldung: :latest.',
    ],

    'alerts' => [
        'heading' => 'Benachrichtigungseinstellungen',
        'lede' => 'Halten Sie Support-Signale nützlich',
        'guidance_heading' => 'Wie Benachrichtigungen funktionieren',
        'guidance_dashboard' => 'Benachrichtigungen im Dashboard sind maßgeblich für Support-Arbeit, die Aufmerksamkeit erfordert.',
        'guidance_email' => 'E-Mail-Benachrichtigungen sind eine optionale Zustellung, keine eigene Warteschlange.',
        'guidance_quiet' => 'Der Ruhemodus pausiert neue Benachrichtigungen, ohne Zuweisungen, Website-Zugriff oder Support-Verantwortung zu ändern.',
        'mode' => 'Benachrichtigungsmodus',
        'email_alerts' => 'E-Mail-Benachrichtigungen',
        'sound_alerts' => 'Bei neuen Dashboard-Benachrichtigungen einen Ton abspielen',
        'sound_help' => 'Ein kurzer lokaler Ton wird nur abgespielt, wenn dieses Dashboard im Hintergrund geöffnet ist. Je nach Browser ist vor der Tonwiedergabe möglicherweise eine Interaktion mit der Seite erforderlich.',
        'push_alerts' => 'Diesen Browser nach dem Schließen des Dashboards benachrichtigen',
        'push_help' => 'Beim Speichern fragt dieser Browser nach der Berechtigung für Benachrichtigungen und meldet sich an. Schalten Sie dies aus, um diesen Browser abzumelden; Ihre anderen angemeldeten Browser bleiben aktiv.',
        'push_unavailable' => 'Ein Plattformbetreiber muss Web-Push einrichten, bevor sich Browser anmelden können. Ihre aktuelle Einstellung bleibt erhalten.',
        'push_unsupported' => 'Dieser Browser oder diese Verbindung unterstützt Web-Push nicht. Ihre aktuelle Einstellung wurde beibehalten.',
        'push_failed' => 'Dieser Browser konnte nicht angemeldet werden. Prüfen Sie die Benachrichtigungsberechtigung und versuchen Sie es erneut.',
        'push_invalid_endpoint' => 'Dieser Browser hat eine Adresse des Push-Dienstes übermittelt, die Wayfindr nicht sicher kontaktieren kann.',
        'push_limit' => 'Dieses Profil hat bereits die Höchstzahl von 10 angemeldeten Browsern.',
        'push_owned_elsewhere' => 'Eine Anmeldung eines anderen angemeldeten Profils wurde aus diesem Browser entfernt, ohne ihren Kontoeintrag neu zuzuordnen.',
        'push_notification_title' => 'Neue Wayfindr-Benachrichtigung',
        'push_notification_body' => 'Öffnen Sie Wayfindr, um sie zu prüfen.',
        'cadence' => 'E-Mail-Rhythmus',
        'cadence_help' => 'Die Sammelzustellung bündelt geeignete E-Mail-Benachrichtigungen, wenn der Planer läuft. „Unbeantwortet“ sendet nur dann eine E-Mail, wenn eine Besuchernachricht ungesehen wartet. Benachrichtigungen im Dashboard bleiben sofort.',
        'last_digest' => 'Letzte Sammelmeldung',
        'email_help' => 'E-Mail-Benachrichtigungen senden dieselben ruhigen Support-Signale an Ihren Posteingang, sofern E-Mail eingerichtet ist. Der Ruhemodus unterdrückt neue Benachrichtigungen weiterhin.',
        'delivery_ready' => 'E-Mail-Zustellung bereit',
        'delivery_attention' => 'E-Mail-Zustellung erfordert Aufmerksamkeit',
        'save' => 'Benachrichtigungseinstellungen speichern',

        'modes' => [
            'all' => 'Alle Website-Benachrichtigungen, die ich betreuen kann',
            'assigned' => 'Nur mir zugewiesene Unterhaltungen und Tickets',
            'quiet' => 'Ruhemodus',
        ],

        'cadences' => [
            'immediate' => 'E-Mail-Benachrichtigungen sofort senden',
            'unattended' => 'Nur per E-Mail, wenn ein Besucher ungesehen wartet',
            'digest' => 'Sammelzustellung bevorzugen, wenn verfügbar',
        ],
    ],

    'digest' => [
        'no_alerts_message' => 'Keine für die Sammelmeldung geeigneten Benachrichtigungen gefunden.',
        'failed_message' => 'Die Sammel-E-Mail konnte nicht eingereiht werden.',
        'never_message' => 'Es wurde noch kein Sammellauf aufgezeichnet.',
        'queued_message' => '{1} Sammel-E-Mail mit :count Benachrichtigung eingereiht.|[2,*] Sammel-E-Mail mit :count Benachrichtigungen eingereiht.',
        'queued_label' => 'Sammel-E-Mail eingereiht',
        'no_alerts_label' => 'Keine geeigneten Benachrichtigungen',
        'failed_label' => 'Sammelzustellung fehlgeschlagen',
        'never_label' => 'Noch nicht ausgeführt',
    ],

    'flash' => [
        'profile_updated' => 'Profil aktualisiert.',
        'alerts_updated' => 'Benachrichtigungseinstellungen aktualisiert.',
        'password_updated' => 'Passwort aktualisiert.',
        'routing_status_updated' => 'Verfügbarkeit für Zuweisungen aktualisiert.',
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
