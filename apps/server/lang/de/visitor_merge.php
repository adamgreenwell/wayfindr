<?php

/* Entwurf aus lang/en/visitor_merge.php. NOCH NICHT GEPRÜFT. */
return [
    'heading' => 'Doppelten Kontakt zusammenführen',
    'lede' => 'Wählen Sie den Kontaktdatensatz, den das Team behalten soll',
    'boundary' => [
        'heading' => 'Dauerhafte Identitätsentscheidung',
        'body' => 'Der aktuelle Kontakt wird gelöscht, nachdem Unterhaltungen, Tickets, Kontaktnotizen, Uploads und Browser-IDs zum ausgewählten Kontakt verschoben wurden.',
        'precedence' => 'Beim ausgewählten Kontakt bleiben ausgefüllte Identitäts- und benutzerdefinierte Attributwerte erhalten; der aktuelle Kontakt füllt Lücken. Unterschiedliche ausgefüllte Besucher-IDs des Hostsystems können nicht zusammengeführt werden.',
        'continuity' => 'Alte Browser-IDs bleiben private Aliasse des ausgewählten Kontakts, damit offene Tabs und wiederkehrende Browser das Duplikat nicht neu erstellen.',
    ],
    'search' => [
        'label' => 'Zu behaltenden Kontakt suchen',
        'placeholder' => 'Name, E-Mail, Host-ID oder Browser-ID',
        'submit' => 'Kontakte suchen',
        'clear' => 'Löschen',
        'empty' => 'Keine anderen Kontakte auf dieser Website entsprechen dieser Suche.',
        'limit' => 'Es werden bis zu 10 Treffer dieser Website angezeigt.',
    ],
    'candidate' => [
        'contact' => 'Zu behaltender Kontakt',
        'email' => 'E-Mail',
        'host_id' => 'Besucher-ID des Hostsystems',
        'browser_id' => 'Browser-ID',
        'last_seen' => 'Zuletzt gesehen',
        'not_provided' => 'Nicht angegeben',
        'confirm' => 'Ich habe geprüft, dass dies dieselbe Person ist, und verstehe, dass die Zusammenführung nicht rückgängig gemacht werden kann.',
        'submit' => 'Mit diesem Kontakt zusammenführen',
    ],
    'errors' => [
        'target_required' => 'Wählen Sie einen gültigen Kontakt zum Behalten aus.',
        'same_contact' => 'Wählen Sie einen anderen Kontakt zum Behalten aus.',
        'external_id_conflict' => 'Diese Kontakte haben unterschiedliche Besucher-IDs des Hostsystems. Klären Sie diesen Identitätskonflikt vor der Zusammenführung.',
        'alias_conflict' => 'Eine Browser-ID gehört bereits zu einem anderen Kontakt. Es wurden keine Datensätze geändert.',
    ],
    'flash' => [
        'merged' => 'Doppelter Kontakt zusammengeführt. Supportverlauf und Browser-IDs gehören jetzt zu diesem Kontakt.',
    ],
];
