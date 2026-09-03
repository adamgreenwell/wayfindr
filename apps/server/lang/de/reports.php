<?php

return [
    'document_title' => 'Berichte',
    'title' => 'Berichte',
    'subtitle' => 'Wie viel Support einging, wie schnell Antworten kamen und wer die Arbeit übernommen hat.',

    'tabs' => [
        'region' => 'Berichtsbereiche',
        'volume' => 'Volumen',
        'speed' => 'Geschwindigkeit',
        'tickets' => 'Tickets',
        'agents' => 'Agenten',
        'satisfaction' => 'Zufriedenheit',
    ],

    'range' => [
        'heading' => 'Zeitraum',
        'one_site' => 'Eine Website',
        'all_sites' => 'Alle sichtbaren Websites',
        'period' => 'Zeitraum',
        'last_days' => '{1} Letzter :count Tag|[0,*] Letzte :count Tage',
        'site' => 'Website',
        'archived_sites' => 'Archivierte Websites',
        'report' => 'Bericht',
        'apply' => 'Anwenden',
        'reset' => 'Zurücksetzen',
    ],

    'history' => [
        'heading' => 'Wie weit diese Zahlen zurückreichen',
        'lede' => 'Nicht alle Daten reichen gleich weit zurück',
        'opened' => 'Geöffnete Unterhaltungen',
        'opened_detail' => 'und',
        'first_response' => 'erste Antwortzeiten',
        'first_response_detail' => 'lassen sich aus der gesamten Historie dieser Installation ermitteln.',
        'lifecycle' => 'Schließungen, Lösungszeiten und Wiedereröffnungen',
        'lifecycle_with_date' => 'stammen aus Lebenszyklusaufzeichnungen, die diese Installation seit dem :date führt. Alles davor ist nicht aufgezeichnet, nicht etwa ausgeblieben — Unterhaltungen wurden geschlossen, doch niemand hielt die Abfolge fest, und sie lässt sich im Nachhinein nicht rekonstruieren.',
        'lifecycle_without_date' => 'stammen aus Lebenszyklusaufzeichnungen, aber diese Installation hat nicht vermerkt, wann die Aufzeichnung begann. Führen Sie ausstehende Migrationen aus; bis dahin decken diese Zahlen nur vorhandene Aufzeichnungen ab.',
        'purge' => 'Beim Löschen einer Website wird auch deren Historie entfernt, deshalb kann eine Summe berechtigterweise sinken.',
    ],

    'counts' => [
        'opened' => '{1} :count geöffnet|[0,*] :count geöffnet',
        'closed' => '{1} :count geschlossen|[0,*] :count geschlossen',
        'created' => '{1} :count erstellt|[0,*] :count erstellt',
        'open_now' => '{1} :count derzeit offen|[0,*] :count derzeit offen',
        'tickets_created' => '{1} :count Ticket erstellt|[0,*] :count Tickets erstellt',
        'tickets_closed' => '{1} :count Ticket geschlossen|[0,*] :count Tickets geschlossen',
        'tickets_open_now' => '{1} :count Ticket derzeit offen|[0,*] :count Tickets derzeit offen',
        'opened_label' => 'Geöffnet',
        'closed_label' => 'Geschlossen',
        'created_label' => 'Erstellt',
        'tickets_closed_label' => 'Geschlossen',
        'measured' => '{1} :count gemessen|[0,*] :count gemessen',
        'closes_measured' => '{1} :count gemessene Schließung|[0,*] :count gemessene Schließungen',
        'agents' => '{1} :count Agent|[0,*] :count Agenten',
        'comments' => '{1} :count Kommentar|[0,*] :count Kommentare',
    ],

    'charts' => [
        'tallest_day' => 'Höchster Tageswert: :count',
    ],

    'metrics' => [
        'median' => 'Median',
        'p90' => '90. Perzentil',
        'slowest_tenth' => 'Das langsamste Zehntel benötigte mindestens so lange.',
        'unmeasured' => 'Gezählt, aber nicht gemessen',
        'reopened' => 'Wieder geöffnet',
        'reopened_detail' => 'Eine Lösung, die nicht gehalten hat.',
    ],

    'duration' => [
        'seconds' => '{1} :count Sekunde|[0,*] :count Sekunden',
        'minutes' => '{1} :count Minute|[0,*] :count Minuten',
        'hours' => '{1} :count Stunde|[0,*] :count Stunden',
        'days' => '{1} :count Tag|[0,*] :count Tage',
    ],

    'conversations' => [
        'volume' => [
            'heading' => 'Unterhaltungsvolumen',
            'empty' => 'In diesem Zeitraum wurden keine Unterhaltungen geöffnet oder geschlossen.',
            'chart_aria' => 'Unterhaltungen pro Tag. :opened geöffnet und :closed geschlossen in den :days Tagen bis zum :date. Der stärkste Tag hatte :busiest.',
            'day_title' => ':date: :opened geöffnet, :closed geschlossen',
            'export' => 'Tagesreihe als CSV exportieren',
        ],
        'queue' => [
            'heading' => 'Aktuell wartend',
            'lede' => 'Eine Live-Zahl, kein Trend',
            'empty' => 'Nichts wartet auf eine Antwort.',
            'waiting' => '{1} :count Unterhaltung wartet im Support, die älteste seit :duration.|[0,*] :count Unterhaltungen warten im Support, die älteste seit :duration.',
            'threshold' => '{1} Zur Orientierung: Eine Benachrichtigung wegen fehlender Bearbeitung wird ausgelöst, sobald eine Unterhaltung :count Minute lang ungesehen wartet. Diese Zahl umfasst jede wartende Unterhaltung, unabhängig von ihrem Alter.|[0,*] Zur Orientierung: Eine Benachrichtigung wegen fehlender Bearbeitung wird ausgelöst, sobald eine Unterhaltung :count Minuten lang ungesehen wartet. Diese Zahl umfasst jede wartende Unterhaltung, unabhängig von ihrem Alter.',
        ],
        'response' => [
            'heading' => 'Erste Antwort',
            'empty' => 'Keine in diesem Zeitraum geöffnete Unterhaltung hat bisher eine erste Antwort erhalten.',
            'median_detail' => 'Die Hälfte der Besucher wartete kürzer.',
            'p90_detail' => 'Das unglücklichste Zehntel wartete mindestens so lange.',
            'awaiting' => '{1} :count in diesem Zeitraum geöffnete Unterhaltung hat überhaupt keine Antwort erhalten und wird deshalb hier gezählt, statt in die obigen Werte einzufließen.|[0,*] :count in diesem Zeitraum geöffnete Unterhaltungen haben überhaupt keine Antwort erhalten und werden deshalb hier gezählt, statt in die obigen Werte einzufließen.',
        ],
        'resolution' => [
            'heading' => 'Lösung',
            'unmeasurable_empty' => '{1} :count Unterhaltung wurde in diesem Zeitraum geschlossen, aber vor Beginn der Aufzeichnung von Wiedereröffnungen geöffnet. Deshalb lässt sich die Bearbeitungsdauer nicht ermitteln. Lösungszeiten erscheinen, sobald seither geöffnete Unterhaltungen geschlossen werden.|[0,*] :count Unterhaltungen wurden in diesem Zeitraum geschlossen, aber vor Beginn der Aufzeichnung von Wiedereröffnungen geöffnet. Deshalb lässt sich die Bearbeitungsdauer nicht ermitteln. Lösungszeiten erscheinen, sobald seither geöffnete Unterhaltungen geschlossen werden.',
            'empty' => 'In diesem Zeitraum wurde keine Unterhaltung geschlossen.',
            'median_detail' => 'Ab der Öffnung oder ab der Wiedereröffnung, mit der die Arbeitsphase begann.',
            'unmeasured_detail' => 'Vor Beginn der Aufzeichnung von Wiedereröffnungen geschlossen, daher lässt sich die Bearbeitungsdauer nicht ermitteln. Oben als Schließung gezählt, hier aber aus beiden Werten ausgeschlossen, statt sie aufzublähen.',
            'reopened_by_visitor' => 'Von einem Besucher wieder geöffnet',
            'reopened_by_visitor_detail' => 'Der Besucher kam zurück, statt dass ein Agent die Unterhaltung wieder öffnete — das deutlichste Zeichen, dass die Antwort nicht angekommen ist.',
        ],
    ],

    'tickets' => [
        'volume' => [
            'heading' => 'Ticketvolumen',
            'empty_before_history' => 'In diesem Zeitraum wurde kein Ticket erstellt, und es ist keine Schließung verzeichnet. Diese Installation zeichnet Ticket-Schließungen seit dem :date auf, doch der gewählte Zeitraum reicht weiter zurück — früher geschlossene Tickets hinterließen keine zählbare Spur.',
            'empty' => 'In diesem Zeitraum wurde kein Ticket erstellt oder geschlossen.',
            'chart_aria' => 'Tickets pro Tag. :created erstellt und :closed geschlossen in den :days Tagen bis zum :date. Der stärkste Tag hatte :busiest.',
            'day_title' => ':date: :created erstellt, :closed geschlossen',
        ],
        'resolution' => [
            'heading' => 'Ticketlösung',
            'unmeasurable_empty' => '{1} :count Ticket wurde in diesem Zeitraum geschlossen, aber vor Beginn der Aufzeichnung von Ticket-Wiedereröffnungen geöffnet. Deshalb lässt sich die Bearbeitungsdauer nicht ermitteln. Lösungszeiten erscheinen, sobald seither geöffnete Tickets geschlossen werden.|[0,*] :count Tickets wurden in diesem Zeitraum geschlossen, aber vor Beginn der Aufzeichnung von Ticket-Wiedereröffnungen geöffnet. Deshalb lässt sich die Bearbeitungsdauer nicht ermitteln. Lösungszeiten erscheinen, sobald seither geöffnete Tickets geschlossen werden.',
            'reopened_unmeasurable' => '{1} :count Ticket wurde in diesem Zeitraum wieder geöffnet — eine Lösung, die nicht gehalten hat. Das ist auch ohne messbare Dauer zählbar.|[0,*] :count Tickets wurden in diesem Zeitraum wieder geöffnet — Lösungen, die nicht gehalten haben. Das ist auch ohne messbare Dauer zählbar.',
            'reopened_without_close' => 'Eine Lösung, die nicht gehalten hat. In diesem Zeitraum wurde nichts geschlossen, daher gibt es daneben keine Lösungszeit zu berichten.',
            'empty_before_history' => 'In diesem Zeitraum ist keine Ticket-Schließung verzeichnet. Diese Installation zeichnet Ticket-Schließungen seit dem :date auf, doch der gewählte Zeitraum reicht weiter zurück — früher geschlossene Tickets hinterließen keine zählbare Spur. Das ist nicht dasselbe wie keine Aktivität.',
            'empty' => 'In diesem Zeitraum wurde kein Ticket geschlossen.',
            'median_detail' => 'Die Hälfte der Tickets wurde schneller gelöst.',
            'unmeasured_detail' => 'Vor Beginn der Aufzeichnung von Ticket-Wiedereröffnungen geöffnet, daher lässt sich die Bearbeitungsdauer nicht ermitteln. Aus beiden obigen Werten ausgeschlossen, statt sie aufzublähen.',
            'reopened_detail' => 'Eine Lösung, die nicht gehalten hat. Jede Wiedereröffnung beginnt eine neue Phase, sodass ein dreimal geschlossenes Ticket drei Lösungen statt einer langen beiträgt.',
            'history' => 'Diese Installation zeichnet Ticket-Schließungen und -Wiedereröffnungen seit dem :date auf. Ein zuvor geöffnetes Ticket könnte geschlossen und wieder geöffnet worden sein, ohne dass dies aufgezeichnet wurde. Deshalb wird es als Schließung gezählt und aus den Zeitwerten ausgeschlossen.',
        ],
        'agents' => [
            'heading' => 'Wer die Ticketarbeit getragen hat',
            'empty' => 'Keine Ticketantworten oder -schließungen in diesem Zeitraum.',
        ],
    ],

    'tables' => [
        'agent' => 'Agent',
        'replies' => 'Gesendete Antworten',
        'tickets_closed' => 'Geschlossene Tickets',
        'conversations_closed' => 'Geschlossene Unterhaltungen',
    ],

    'agents' => [
        'heading' => 'Wer die Arbeit getragen hat',
        'empty' => 'In diesem Zeitraum hat kein Agent auf eine Unterhaltung geantwortet oder sie geschlossen.',
        'removed' => 'Entfernter Agent',
        'deactivated' => 'Deaktiviert',
        'deactivated_detail' => 'Deaktivierte Agenten bleiben aufgeführt: Sie haben die Arbeit erledigt, und eine Summe, die sich beim Ausscheiden einer Person ändert, ist keine verlässliche Summe.',
        'export' => 'Als CSV exportieren',
    ],

    'satisfaction' => [
        'heading' => 'Ob es geholfen hat',
        'summary' => '{1} :answered von :closed Schließung beantwortet|[0,*] :answered von :closed Schließungen beantwortet',
        'no_closes' => 'In diesem Zeitraum wurde keine Unterhaltung geschlossen, daher wurde niemand gefragt.',
        'no_answers_before' => 'In diesem Zeitraum hat niemand geantwortet. Das ist keine schlechte Bewertung, sondern keine Bewertung; beides darf nicht verwechselt werden. Wenn Ihre Websites nicht fragen, aktivieren Sie die Abfrage unter',
        'setting' => 'Nach der Zufriedenheit fragen',
        'no_answers_after' => 'in den Einstellungen einer Website.',
        'good' => 'Gut',
        'good_detail' => ':percentage der Personen, die geantwortet haben.',
        'ok' => 'Okay',
        'ok_detail' => 'Es hat geholfen, aber niemand wird davon erzählen.',
        'bad' => 'Schlecht',
        'bad_detail' => 'Die Antwort, für die dieser ganze Bereich existiert.',
        'answered' => 'Beantwortet',
        'answered_detail' => '{1} Von :count Schließung. Jeder obige Wert ist ein Anteil dieser Zahl, niemals der Schließungen — Personen ohne Antwort gelten nicht als zufrieden.|[0,*] Von :count Schließungen. Jeder obige Wert ist ein Anteil dieser Zahl, niemals der Schließungen — Personen ohne Antwort gelten nicht als zufrieden.',
        'small_sample' => 'So wenige Antworten, dass eine weitere den Prozentsatz merklich verändern würde. Lesen Sie dies als Richtung, nicht als Messwert.',
    ],

    'comments' => [
        'heading' => 'Was die Menschen gesagt haben',
        'empty' => 'In diesem Zeitraum hat niemand einen Kommentar hinterlassen. Das Kommentarfeld ist optional und wird meist übersprungen — eine Bewertung ohne Worte ist trotzdem eine Antwort.',
        'score' => 'Bewertung',
        'said' => 'Aussage',
        'conversation' => 'Unterhaltung',
        'when' => 'Zeitpunkt',
        'latest' => '{1} Der neueste :count Kommentar. Eine Bewertung zeigt, dass etwas schiefging; nur hier steht, was es war.|[0,*] Die neuesten :count Kommentare. Eine Bewertung zeigt, dass etwas schiefging; nur hier steht, was es war.',
    ],
];
