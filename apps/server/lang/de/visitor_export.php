<?php

/* Entwurf aus lang/en/visitor_export.php. Geprüft gemäß Abschnitt 9 von docs/product/translation-policy.md. */
return [
    'section' => [
        'heading' => 'Diesen Kontakt exportieren',
        'lede' => 'Für eine Anfrage, alle zu dieser Person gespeicherten Daten einzusehen',
        'body' => 'Laden Sie ein ZIP mit allem herunter, was Wayfindr zu diesem Kontakt speichert: Identität, Unterhaltungen, Dateien und Tickets, die Benachrichtigungen und Audit-Einträge dazu sowie nebenbei gespeicherten Text, der die Person zitiert. Agents werden mit ihrer Rolle genannt, nicht mit Namen. Prüfen Sie das Archiv vor dem Versand: Es enthält Kontaktnotizen, die Sie nach geltendem Recht womöglich zurückhalten dürfen.',
        'link' => 'Export herunterladen',
    ],
    'before_erasing' => 'Wenn die Person auch eine Kopie ihrer Daten angefordert hat, laden Sie diese vor dem Löschen herunter: Das Löschen kann nicht rückgängig gemacht werden.',
    'errors' => [
        'gone' => 'Dieser Kontakt wurde gelöscht oder zusammengeführt, während der Export vorbereitet wurde. Es wurde nichts heruntergeladen.',
        'too_large' => 'Der Verlauf dieses Kontakts ist größer, als ein Exportarchiv fassen kann. Es wurde nichts heruntergeladen.',
    ],
    'readme' => [
        'title' => 'Alles, was Wayfindr zu einem Kontakt speichert',
        'intro' => 'Dieses Archiv wurde aus Wayfindr, einer Kundensupport-Plattform, exportiert, um eine Anfrage der beschriebenen Person zu beantworten. Jede Datei darin beschreibt denselben Zeitpunkt.',
        'moment' => 'Exportiert am :time (UTC).',
        'contents_heading' => 'Was enthalten ist',
        'contents' => [
            'visitor.json: der Kontaktdatensatz mit allen Attributen und der letzten Seitenadresse, die Browser-IDs und die Kontaktnotizen des Teams.',
            'conversations/: eine Datei je Unterhaltung mit Nachrichten, Bewertungen, Dateiangaben, Cobrowse-Sitzungen, Ausgaben des KI-Assistenten und den per E-Mail gesendeten Antworten.',
            'cobrowse.json: Cobrowse-Sitzungen der Person in einer Unterhaltung, die nicht ihre eigene ist, sofern vorhanden.',
            'proactive.json: die proaktiven Nachrichten, die der Person angezeigt wurden, und wann jede angezeigt, genutzt oder geschlossen wurde.',
            'tickets/: die Tickets der Person mit Notizen, wohin jede Notiz gesendet wurde und mit welchem Ergebnis, sowie die verknüpften Issues.',
            'alerts.json: die Benachrichtigungen, die Agents zu der Person erhalten haben.',
            'audit.json: der Verlauf dessen, was mit der Person, ihren Unterhaltungen und ihren Tickets geschehen ist.',
            'incidental.json: nebenbei gespeicherter Text, der die Person zitieren kann: Webhook-Antworten, Automatisierungsfehler, gespeicherte Suchen in der Warteschlange und fehlgeschlagene Jobs.',
            'break_glass.json: Zugriffe des Plattformbetreibers auf die Daten der Person, mit Begründung.',
            'attachments/: die in den Unterhaltungen geteilten Dateien.',
        ],
        'roles' => 'Personen werden mit ihrer Rolle genannt, nicht mit Namen: „visitor“ ist die Person selbst, und „agent“, „platform operator“, „integration“ und „system“ geben an, wer sonst gehandelt hat. Wer von den Mitarbeitenden beteiligt war, ist eine Angabe über diese Mitarbeitenden; die Installation ergänzt sie, wo das Recht verlangt, zu nennen, wer die Daten gesehen hat.',
        'review' => 'Der Betreiber der Installation prüft dieses Archiv vor dem Versand und darf alles entfernen, was nach geltendem Recht zurückgehalten werden darf.',
        'not_reached_heading' => 'Was dieses Archiv nicht enthalten kann',
        'not_reached' => [
            'Backups;',
            'externe Issue-Tracker;',
            'bereits versendete E-Mails;',
            'was der KI-Anbieter gespeichert hat;',
            'was API-Integrationen bereits abgerufen haben;',
            'Dateien, die ein Agent für eine noch nicht gesendete Antwort hochgeladen hat;',
            'Logs, Text fehlgeschlagener Jobs, der die Person nicht direkt nennt, und die Infrastruktur des Betreibers.',
        ],
        'counts_heading' => 'Wie viel enthalten ist',
        'pruned' => 'Diese Dateien wurden nach Beginn des Exports durch die Aufbewahrungsregeln entfernt. Sie sind deshalb aufgeführt, aber nicht enthalten:',
        'withheld' => 'Diese Dateien wurden nicht vollständig hochgeladen oder vom Malware-Scanner zurückgehalten. Sie sind deshalb aufgeführt, aber nicht enthalten:',
    ],
];
