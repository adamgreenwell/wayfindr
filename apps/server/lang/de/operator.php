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
];
