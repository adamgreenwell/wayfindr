<?php

/* Entwurf aus lang/en/visitor_erasure.php. Geprüft gemäß Abschnitt 9 von docs/product/translation-policy.md. */
return [
    'section' => [
        'heading' => 'Diesen Kontakt löschen',
        'lede' => 'Für eine Anfrage, alle zu dieser Person gespeicherten Daten zu löschen',
        'body' => 'Beim Löschen werden Identität, Unterhaltungen, Nachrichten, hochgeladene Dateien, Kontaktnotizen und Bewertungen dieses Kontakts entfernt. Tickets bleiben als Arbeitsvorgänge erhalten, ohne Bezug zur Person. Dies kann nicht rückgängig gemacht werden.',
        'link' => 'Prüfen, was gelöscht wird',
    ],
    'page' => [
        'title' => 'Kontakt löschen',
        'heading' => 'Diesen Kontakt löschen',
        'lede' => 'Prüfen Sie, was entfernt wird, was bleibt und was Wayfindr nicht erreichen kann, und bestätigen Sie dann.',
        'back' => 'Zurück zum Kontakt',
    ],
    'deleted' => [
        'heading' => 'Gelöscht',
        'identity' => 'Name, E-Mail, Besucher-ID des Hostsystems, Browser-IDs und benutzerdefinierte Attribute',
        'conversations' => 'Unterhaltungen',
        'messages' => 'Nachrichten',
        'attachments' => 'Hochgeladene Dateien',
        'notes' => 'Kontaktnotizen',
        'ratings' => 'Bewertungen',
        'cobrowse_sessions' => 'Cobrowse-Sitzungen',
        'also' => 'Ebenfalls entfernt: Benachrichtigungen zu diesen Unterhaltungen, ausstehende Webhook-Zustellungen dafür und der Text aller Audit-Einträge dazu. Audit-Einträge behalten, wer wann was getan hat.',
    ],
    'tickets' => [
        'heading' => 'Tickets, die ohne Bezug zur Person erhalten bleiben',
        'body' => 'Jedes behält Status, Priorität, Labels, zuständige Person und die Verknüpfung zum externen Issue. Der Betreff wird zu „Ticket #N (anfragende Person gelöscht)“, und Beschreibung, Besucherangaben, Supportcode und Notiztext werden entfernt.',
        'empty' => 'Dieser Kontakt hat keine Tickets.',
    ],
    'unreachable' => [
        'heading' => 'Was das Löschen nicht erreicht',
        'backups' => 'Vor diesem Zeitpunkt erstellte Backups enthalten die Daten dieser Person weiterhin, und das Wiederherstellen eines Backups bringt sie zurück.',
        'external' => 'Verknüpfte externe Issues behalten, was in sie kopiert wurde:',
        'mail' => 'Bereits per E-Mail gesendete Antworten.',
        'ai' => 'Was der KI-Anbieter von Copilot-Anfragen gespeichert hat.',
        'api' => 'Was eine API-Integration bereits abgerufen hat.',
        'future' => 'Kommt die Person zurück, ist sie ein neuer Kontakt, und eine Hostseite, die ihre Besucher-ID sendet, erstellt den Datensatz neu.',
    ],
    'form' => [
        'confirm' => 'Geben Sie zur Bestätigung :word ein',
        'password' => 'Ihr aktuelles Passwort',
        'submit' => 'Diesen Kontakt endgültig löschen',
    ],
    'errors' => [
        'confirm' => 'Geben Sie zur Bestätigung genau :word ein.',
        'copilot_running' => 'Der KI-Assistent bearbeitet gerade eine Unterhaltung dieses Kontakts, daher wird das Transkript möglicherweise an den KI-Anbieter übertragen. Es wurde nichts gelöscht. Versuchen Sie es in ein paar Minuten erneut.',
        'note_posting' => 'Eine Notiz zu einem Ticket dieses Kontakts wird gerade an das verknüpfte Issue übertragen. Es wurde nichts gelöscht. Versuchen Sie es in ein paar Minuten erneut.',
    ],
    'ticket_subject' => 'Ticket #:number (anfragende Person gelöscht)',
    'flash' => [
        'erased' => 'Kontakt gelöscht.',
        'receipt' => 'Belegreferenz: :receipt',
    ],
];
