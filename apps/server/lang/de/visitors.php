<?php

/*
 * Entwurf aus lang/en/visitors.php. NOCH NICHT GEPRÜFT.
 *
 * Von Hand anhand des Glossars und der Übersetzungsrichtlinie erstellt. Jeder
 * Wert ist ein Vorschlag. `Besucher` bezeichnet den Singular und die nackte
 * Spaltenüberschrift; `Besuchende` bezeichnet den Plural.
 */
return [
    'document_title' => 'Besuchende',
    'title' => 'Besuchende',
    'subtitle' => [
        'browsers' => 'Alle, die dieser Support gesehen hat, unabhängig davon, ob sie Kontakt aufgenommen haben, zuletzt Gesehene zuerst.',
        'contacts' => 'Alle, von denen dieser Support gehört hat, zuletzt Gesehene zuerst.',
    ],

    'filters' => [
        'heading' => 'Suche',
        'hint' => 'Nach Name, E-Mail-Adresse oder der Kennung, die Ihre Website vergeben hat.',
        'search' => 'Suchen',
        'placeholder' => 'Name, E-Mail-Adresse oder ID',
        'site' => 'Website',
        'any_site' => 'Beliebige Website',
        'last_seen' => 'Zuletzt gesehen',
        'any_time' => 'Beliebiger Zeitpunkt',
        'submit' => 'Besuchende suchen',
        'clear' => 'Zurücksetzen',
    ],

    'list' => [
        'heading' => 'Besuchende',
        'columns' => [
            'visitor' => 'Besucher',
            'site' => 'Website',
            'last_seen' => 'Zuletzt gesehen',
            'conversations' => 'Unterhaltungen',
        ],
        'unknown_site' => 'Unbekannte Website',
    ],

    'empty' => [
        'browsers' => 'Keine Besuchenden entsprechen dieser Suche. Auf den hier gezeigten Websites zeichnet Wayfindr eine Person beim Laden einer Seite auf, sodass auch Personen aufgeführt werden, die nur gestöbert haben.',
        'contacts' => 'Keine Besuchenden entsprechen dieser Suche. Wayfindr zeichnet eine Person beim Öffnen des Chats auf, nicht beim Laden einer Seite, sodass hier Personen aufgeführt werden, die Kontakt aufgenommen haben.',
    ],

    'presence' => [
        'seen_recently' => 'In den letzten 2 Minuten gesehen',
        'seen_at' => 'Gesehen :elapsed',
        'no_heartbeat' => 'Noch kein Lebenszeichen des Besuchers.',
    ],

    'counts' => [
        'visitors' => '{1} :count Besucher|[2,*] :count Besuchende',
        'conversations' => '{1} :count Unterhaltung|[2,*] :count Unterhaltungen',
        'tickets' => '{1} :count Ticket|[2,*] :count Tickets',
        'active_conversations' => '{1} :count aktive Unterhaltung|[2,*] :count aktive Unterhaltungen',
        'active_tickets' => '{1} :count aktives Ticket|[2,*] :count aktive Tickets',
        'fields' => '{1} :count Feld|[2,*] :count Felder',
        'shown_conversations' => '{1} :count angezeigt|[2,*] :count angezeigt',
        'shown_tickets' => '{1} :count angezeigt|[2,*] :count angezeigt',
    ],

    'common' => [
        'not_provided' => 'Nicht angegeben',
        'not_reported' => 'Nicht gemeldet',
    ],

    'profile' => [
        'document_title' => 'Besucherprofil',
        'title' => 'Besucherprofil',
        'back' => 'Zurück zu Besuchern',
        'glance' => [
            'heading' => 'Besucher auf einen Blick',
            'safe_only' => 'Nur sichere Kontextdaten',
            'visitor' => 'Besucher',
            'host_visitor_id' => 'Host-Besucher-ID',
            'last_seen' => 'Zuletzt gesehen',
            'latest_page' => 'Letzte Seite',
            'entry_page' => 'Erste erfasste Einstiegsseite',
            'support_history' => 'Supportverlauf',
        ],
    ],

    'snapshot' => [
        'heading' => 'Support-Übersicht',
        'conversations' => 'Unterhaltungen',
        'tickets' => 'Tickets',
        'next_step' => 'Nächster Schritt',
        'agent_cue' => 'Hinweis für Agenten',
        'status' => [
            'needs_reply' => 'Antwort nötig',
            'review_context' => 'Kontext prüfen',
            'waiting' => 'Wartend',
            'in_progress' => 'In Bearbeitung',
            'clear' => 'Alles klar',
        ],
        'reply' => [
            'body' => 'Der Besucher hat zuletzt geantwortet. Öffnen Sie den neuesten Support-Vorgang, bevor Sie den älteren Verlauf durchsuchen.',
            'cta' => 'Besucher antworten',
            'title' => 'Besucher antworten',
        ],
        'empty_conversation' => [
            'body' => 'Noch sind keine Nachrichten eingegangen. Entscheiden Sie anhand des aktuellen Besucherkontexts, ob Sie begrüßen, warten oder ein Ticket erstellen.',
            'cta' => 'Kontext prüfen',
            'title' => 'Unterhaltung beginnen',
        ],
        'waiting_conversation' => [
            'body' => 'Im Moment wartet keine Besucherantwort. Behalten Sie den Verlauf im Blick und antworten Sie, wenn der Besucher zurückkehrt.',
            'cta' => 'Unterhaltung prüfen',
            'title' => 'Wartet auf Besucher',
        ],
        'waiting_ticket' => [
            'body' => 'Im Moment wartet keine Besucherantwort. Prüfen Sie das aktive Ticket, wenn die Nachverfolgung fällig ist.',
            'cta' => 'Ticket prüfen',
            'title' => 'Ticket in Bearbeitung',
        ],
        'clear' => [
            'body' => 'Diesem Besucher ist keine aktive Support-Arbeit zugeordnet.',
            'title' => 'Keine aktive Arbeit',
        ],
    ],

    'references' => [
        'heading' => 'Support-Referenzen',
        'lede' => 'Stabile Anhaltspunkte für Suche, Übergabe und Nachverfolgung.',
        'visitor' => 'Suchreferenz des Besuchers',
        'latest_support_code' => 'Neuester Support-Code',
        'latest_ticket' => 'Neuestes Ticket',
        'ticket' => 'Ticket Nr. :id',
        'no_conversations' => 'Noch keine Unterhaltungen',
        'no_tickets' => 'Noch keine Tickets',
    ],

    'boundary' => [
        'heading' => 'Datengrenze',
        'body' => 'Nutzen Sie diese Seite, um den Supportverlauf zu verstehen. Erfassen, exportieren oder erschließen Sie ohne Einwilligung keine zusätzlichen Besucherdaten.',
    ],

    'context' => [
        'heading' => 'Host-Kontext',
        'field' => 'Feld',
        'value' => 'Wert',
        'empty_heading' => 'Noch kein vom Host bereitgestellter Kontext.',
        'empty_body' => 'Wayfindr hat nur die anonyme Besucherreferenz, bis die Host-Website sichere Kunden- oder Kontodaten übermittelt.',
    ],

    'history' => [
        'heading' => 'Supportverlauf',
        'conversations' => 'Unterhaltungen',
        'tickets' => 'Tickets',
        'no_conversations_heading' => 'Noch keine Unterhaltungen für diesen Besucher.',
        'no_conversations_body' => 'Neue Unterhaltungen erscheinen hier, sobald dieser Besucher auf dieser Website einen Support-Thread beginnt.',
        'no_tickets_heading' => 'Noch keine Tickets für diesen Besucher.',
        'no_tickets_body' => 'Erstellen Sie aus einer Unterhaltung ein Ticket, wenn der nächste Schritt eine dauerhafte Nachverfolgung erfordert.',
        'untitled_conversation' => 'Unterhaltung ohne Titel',
        'owner' => 'Zuständig',
        'unassigned' => 'Nicht zugewiesen',
        'last_activity' => 'Letzte Aktivität: :elapsed',
        'support_code' => 'Support-Code',
        'updated' => 'Aktualisiert: :elapsed',
    ],
];
