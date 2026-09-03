<?php

return [
    'shell' => [
        'sections_label' => 'Betreiberbereiche',
        'heading' => 'Betreiber',
        'back' => 'Zurück',
        'back_to_setup' => 'Zurück zur Einrichtungscheckliste',
        'back_to_console' => 'Zurück zur Betreiberkonsole',
        'sections' => [
            'console' => 'Konsole',
            'onboarding' => 'Einrichtungscheckliste',
            'mail' => 'E-Mail',
            'storage' => 'Speicher',
            'scanning' => 'Dateiprüfung',
            'backups' => 'Sicherungen',
            'localization' => 'Sprache und Region',
            'operator_access' => 'Betreiberzugriff',
        ],
    ],

    'localization' => [
        'document_title' => 'Sprache und Region',
        'title' => 'Sprache und Region',
        'subtitle' => 'Die Sprache des Dashboards für alle, die selbst keine ausgewählt haben. Änderungen gelten sofort, ohne Neustart.',
        'heading' => 'Installationsstandards',
        'lede' => 'Dies sind Vorgaben, keine Regeln. Agenten, die in ihrem Profil eine eigene Sprache oder Zeitzone auswählen, behalten diese Auswahl — die Vorgaben gelten für alle anderen, also bei einer neuen Installation für alle.',
        'language' => 'Sprache',
        'language_help' => 'Gilt für das Agenten-Dashboard. Was Besucher im Widget sehen, richtet sich nach ihrem eigenen Browser und wird hiervon nicht beeinflusst.',
        'timezone' => 'Zeitzone',
        'timezone_help' => 'Uhrzeiten und Berichtstage werden nach der eingestellten Zeitzone angezeigt. Datensätze werden immer in UTC gespeichert; eine Änderung liest den bestehenden Verlauf daher neu, statt bestehende Datensätze umzuschreiben.',
        'save' => 'Sprache und Region speichern',
        'flash' => [
            'saved' => 'Sprache und Region gespeichert. Agenten ohne eigene Auswahl lesen das Dashboard jetzt so.',
        ],
    ],

    'scanning' => [
        'document_title' => 'Einstellungen für die Dateiprüfung',
        'title' => 'Prüfung von Anhängen',
        'subtitle' => 'Hochgeladene Dateien vor dem Speichern auf Schadsoftware prüfen. Änderungen gelten sofort, ohne Neustart.',
        'heading' => 'Schadsoftware-Scanner',
        'lede' => 'Ohne Scanner werden Uploads weiterhin mit mehrschichtigem Schutz akzeptiert: einer anhand der Bytes geprüften Dateityp-Zulassungsliste, privatem Speicher, erzwungenem Download und nosniff — jedoch ohne Virenprüfung.',
        'driver' => 'Scanner',
        'external_driver_help' => 'Der aktuelle Scanner ist über die Umgebung konfiguriert: :driver. Beim Speichern anderer Einstellungen bleibt die Auswahl erhalten.',
        'none' => 'Ohne Scanner (mit mehrschichtigem Schutz akzeptieren)',
        'driver_help' => 'ClamAV wird lokal ausgeführt, sodass Dateien zur Prüfung nie den Server verlassen. Wählen Sie ClamAV aus und geben Sie unten den clamd-Socket an.',
        'socket' => 'ClamAV-Socket',
        'socket_help' => 'Eine TCP-Adresse (:tcp) oder ein Unix-Socket (:unix) für den laufenden clamd-Dienst.',
        'fail_closed' => 'Uploads ablehnen, wenn der Scanner nicht erreichbar ist (Fail-Closed — empfohlen). Ohne Auswahl werden Uploads ungeprüft akzeptiert, wenn der Scanner ausgefallen ist.',
        'save' => 'Einstellungen für die Dateiprüfung speichern',
        'test_heading' => 'Erreichbarkeit testen',
        'test_lede' => 'Prüfen, ob der konfigurierte Scanner ausgeführt wird und antwortet — kein Terminal erforderlich.',
        'test' => 'Scanner testen',
        'flash' => [
            'saved' => 'Einstellungen für die Dateiprüfung gespeichert. Testen Sie die Erreichbarkeit, um zu bestätigen, dass der Scanner antwortet.',
            'none' => 'Es ist kein Scanner konfiguriert — Uploads werden mit mehrschichtigem Schutz akzeptiert (Dateityp-Zulassungsliste, privater Speicher, erzwungener Download), aber nicht auf Viren geprüft. Wählen Sie ClamAV aus und speichern Sie, um die Prüfung zu aktivieren.',
            'misconfigured' => 'Der Scanner ist falsch konfiguriert: :message',
            'reachable' => 'Scanner erreichbar: Der Scanner :driver hat geantwortet. Uploads werden vor dem Speichern geprüft.',
            'unreachable' => 'Der Scanner :driver ist konfiguriert, aber unter :socket nicht erreichbar. Prüfen Sie, ob clamd ausgeführt wird und der Socket korrekt ist.',
        ],
    ],
];
