<?php

/**
 * Die Unterhaltungs-Warteschlange. See lang/en/conversations.php for the two
 * rules this file follows.
 *
 * Worth noting for the next translator: several plural forms here are
 * deliberately identical on both sides of the `|`. German does not change the
 * verb for number the way English does -- "1 benötigt Aufmerksamkeit" and
 * "3 benötigen Aufmerksamkeit" differ, but "1 geschlossen" and "3 geschlossen"
 * do not. Identical halves are the correct translation, not a copy-paste slip.
 */
return [
    'document_title' => 'Unterhaltungen',
    'title' => 'Unterhaltungs-Warteschlange',
    'page_title_active' => 'Aktive Besucher-Unterhaltungen für :account.',
    'page_title_closed' => 'Geschlossene Besucher-Unterhaltungen für :account.',

    'search' => [
        'placeholder' => 'Betreff, Support-Code oder Besucher',
        'hint' => 'Suche nach Betreff, Support-Code, Besucher-ID, Besuchername oder E-Mail-Adresse des Besuchers.',
        'label' => 'Suche',
        'submit' => 'Unterhaltungen suchen',
    ],

    'sites' => [
        'any' => 'Alle Websites',
    ],

    'filters_label_presence' => 'Status',
    'filters' => [
        'all' => 'Alle offenen',
        'new_activity' => 'Neue Aktivität',
        'needs_reply' => 'Antwort nötig',
        'assigned_to_me' => 'Mir zugewiesen',
        'unassigned' => 'Nicht zugewiesen',
        'cobrowse_attention' => 'Cobrowse-Aufmerksamkeit',
        'closed' => 'Geschlossen',
    ],

    'lanes' => [
        'region_label' => 'Unterhaltungs-Spuren',
        'new_activity' => 'Benötigt Aufmerksamkeit',
        'needs_reply' => 'Antwort nötig',
        'assigned_to_me' => 'Mir zugewiesen',
        'unassigned' => 'Nicht zugewiesen',
        'active' => 'Aktive Besucher',
        'recent' => 'Vor Kurzem aktiv',
    ],

    'chips' => [
        'region_label' => 'Aktive Unterhaltungsfilter',
        'site' => 'Website: :name',
        'search' => 'Suche: :term',
        'presence' => 'Status: :label',
    ],

    'actions' => [
        'clear_filters' => 'Filter zurücksetzen',
        'clear_all' => 'Alle Unterhaltungsfilter zurücksetzen',
        'clear_search' => 'Suche zurücksetzen',
        'clear_support_lane' => 'Support-Spur zurücksetzen',
        'show_active' => 'Aktive Unterhaltungen anzeigen',
        'check_installs' => 'Widget-Installationen prüfen',
    ],

    'counts' => [
        'conversations' => '{1} 1 Unterhaltung|[2,*] :count Unterhaltungen',
        'needs_attention' => '{1} 1 benötigt Aufmerksamkeit|[2,*] :count benötigen Aufmerksamkeit',
        'cobrowse_attention' => '{1} 1 Cobrowse-Sitzung benötigt Aufmerksamkeit|[2,*] :count Cobrowse-Sitzungen benötigen Aufmerksamkeit',
        'closed' => '{1} 1 geschlossen|[2,*] :count geschlossen',
        'open_matching' => '{1} 1 offener Treffer|[2,*] :count offene Treffer',
        'matches' => '{1} 1 Unterhaltung entspricht|[2,*] :count Unterhaltungen entsprechen',
        // DATIVE, because both sentences that use it read "von :matching".
        // German inflects the adjective for case as well as number, and after a
        // bare numeral it takes strong endings -- so "von 1 passender
        // Unterhaltung" and "von 3 passenden Unterhaltungen", not the
        // nominative "passende" a word-for-word translation produces.
        'matching_conversations' => '{1} 1 passender Unterhaltung|[2,*] :count passenden Unterhaltungen',
    ],

    'summary' => [
        // The halves differ here, which is the point: German inflects both the
        // auxiliary (wird/werden) and the relative-clause verb
        // (entspricht/entsprechen) for number, so a sentence built around a
        // correctly pluralised count was still wrong on either side of it.
        'lane_narrowed_heading' => ':shown von :matching angezeigt',
        'lane_narrowed_attention_heading' => '{1} :shown von :matching benötigt Aufmerksamkeit|[2,*] :shown von :matching benötigen Aufmerksamkeit',
        'lane_narrowed_detail' => '{1} Es wird :shown nach dem Support-Spur-Filter „:lane“ angezeigt. :matching den übrigen Filtern.|[2,*] Es werden :shown nach dem Support-Spur-Filter „:lane“ angezeigt. :matching den übrigen Filtern.',
        'filtered_detail' => '{1} Es wird :shown angezeigt, die den aktuellen Filtern entspricht.|[2,*] Es werden :shown angezeigt, die den aktuellen Filtern entsprechen.',
        'open_heading' => ':open offen · :attention · :cobrowse',
    ],

    'empty' => [
        'no_match_filters' => 'Keine Unterhaltungen entsprechen diesen Filtern.',
        'no_new_activity' => 'Keine Unterhaltungen benötigen Aufmerksamkeit.',
        'no_cobrowse_attention' => 'Keine aktiven Cobrowse-Sitzungen benötigen Aufmerksamkeit.',
        'no_closed' => 'Noch keine geschlossenen Unterhaltungen.',
        'no_active' => 'Noch keine aktiven Unterhaltungen.',
        'no_search_match' => 'Keine Unterhaltungen entsprechen „:term“.',
        'search_covers' => 'Die Suche umfasst Betreff, Support-Code, Besucher-ID, Besuchername und E-Mail-Adresse des Besuchers.',
        'refine_detail' => 'Probieren Sie einen anderen Website- oder Statusfilter, oder setzen Sie die Filter zurück, um zur breiteren Warteschlange zurückzukehren.',
        'closed_detail' => 'Geschlossene Unterhaltungen erscheinen hier, sobald Agenten Support-Threads schließen.',
        'default_detail' => 'Neue Besucher-Unterhaltungen erscheinen hier, sobald der Support beginnt.',
        'first_run_detail' => 'Neue Besucher-Unterhaltungen erscheinen hier, sobald der Support beginnt. Unterhaltungen entstehen, wenn ein Besucher das Widget auf einer verbundenen Website öffnet.',
        'lane_detail' => 'Probieren Sie eine andere Support-Spur oder setzen Sie den Spurfilter zurück. :matching den übrigen Filtern.',
        'lane_assigned_to_me' => 'Ihnen sind in dieser Warteschlange keine Unterhaltungen zugewiesen.',
        'lane_cobrowse_attention' => 'Keine aktiven Cobrowse-Sitzungen benötigen Aufmerksamkeit.',
        'lane_needs_reply' => 'Derzeit benötigt keine Unterhaltung eine Antwort.',
        'lane_new_activity' => 'Derzeit benötigt keine Unterhaltung Aufmerksamkeit.',
        'lane_unassigned' => 'Keine nicht zugewiesenen Unterhaltungen in dieser Warteschlange.',
    ],

    'columns' => [
        'subject' => 'Betreff',
        'site' => 'Website',
        'visitor' => 'Besucher',
        'attention' => 'Aufmerksamkeit',
        'read' => 'Gelesen',
        'cobrowse' => 'Cobrowse',
        'assigned' => 'Zugewiesen',
        'timing' => 'Zeit',
    ],

    // See lang/en/conversations.php for why the detail page lives here and why
    // the cobrowse panel HEADINGS are extracted while their values are not.
    'detail' => [
        'untitled' => 'Unterhaltung ohne Betreff',
        'no_messages' => 'Noch keine Nachrichten.',
        'unknown_visitor' => 'Unbekannter Besucher',
        'not_reported' => 'Nicht gemeldet',
        'no_heartbeat' => 'Noch kein Besucher-Signal.',
        'visitor_actor' => 'Besucher',
        'ticket_from_conversation' => 'Aus Unterhaltung :code erstellt.',
        'ticket_subject_fallback' => 'Unterhaltung :code',
        'back' => 'Zurück zu den Unterhaltungen',
        'support_code' => 'Support-Code :code',

        'statuses' => [
            'open' => 'Offen',
            'closed' => 'Geschlossen',
        ],

        'tones' => [
            'attention' => 'Aufmerksamkeit',
            'ready' => 'Bereit',
            'manual' => 'Manuell',
        ],

        'headings' => [
            'messages' => 'Nachrichten',
            'context' => 'Kontext',
            'references' => 'Support-Referenzen',
            'ticket' => 'Ticket',
        ],

        'tabs' => [
            'workspace' => 'Unterhaltungs-Arbeitsbereich',
            'conversation' => 'Unterhaltung',
            'cobrowse' => 'Cobrowse',
            'visitor' => 'Besucher',
            'ticket' => 'Ticket',
            'linked_badge' => '{1} 1 verknüpft|[2,*] :count verknüpft',
            'not_created' => 'Nicht erstellt',
            'position' => ':position von :total',
            'transcript_total' => '{1} 1 insgesamt|[2,*] :count insgesamt',
        ],

        'nav' => [
            'move' => 'Durch die Unterhaltungs-Warteschlange navigieren',
            'previous' => 'Vorherige Unterhaltung in dieser Warteschlange',
            'next' => 'Nächste Unterhaltung in dieser Warteschlange',
        ],

        'next_action' => [
            'heading' => 'Nächster Schritt',
        ],

        'reply' => [
            'heading' => 'Antworten',
            'label' => 'Nachricht',
            'send' => 'Antwort senden',
            'shortcut' => 'Befehl oder Strg plus Enter sendet diese Antwort.',
            'attach' => 'Datei anhängen',
            'files' => 'Dateien, die mit dieser Antwort gesendet werden',
            'guidance' => 'Schreiben Sie eine klare, ruhige Antwort.',
            'privacy' => 'Halten Sie sensible Angaben aus Antworten heraus, sofern der Besucher sie nicht hier genannt hat.',
            'assist' => 'Antwortassistent',
            'helper' => 'Antworthilfe',
            'helper_note' => 'Antworthilfen bieten einen Ausgangspunkt, den Sie bearbeiten können. Wayfindr schreibt niemals eine Antwort für Sie.',
            'custom' => 'Eigene Antwort schreiben',
            'writing_own' => 'Diese schreiben Sie selbst',
            'context' => 'Antwortkontext',
            'visibility' => 'Antwortsichtbarkeit',
            'visibility_none' => 'Die Antwortsichtbarkeit beginnt, sobald dieses Ticket mit einer Unterhaltung verknüpft ist.',
            'visibility_label' => 'Sichtbarkeit',
            'typing' => 'Besucher schreibt …',
            'no_body' => 'Diese Nachricht hat weder Text noch Anhang.',
            'visitor_read' => 'Vom Besucher gelesen',
            'not_seen' => 'Noch nicht gesehen',
        ],

        'context' => [
            'heading' => 'Besucher auf einen Blick',
            'about' => 'Worum es geht',
            'visitor' => 'Besucher',
            'email' => 'E-Mail-Adresse',
            'site' => 'Website',
            'status' => 'Status',
            'presence' => 'Status',
            'opened' => 'Geöffnet',
            'last_seen' => 'Zuletzt gesehen',
            'latest_activity' => 'Letzte Aktivität',
            'entry_page' => 'Einstiegsseite',
            'latest_page' => 'Letzte Seite',
            'host_context' => 'Host-Kontext',
            'host_visitor_id' => 'Host-Besucher-ID',
            'field' => 'Feld',
            'value' => 'Wert',
            'timing' => 'Zeit',
            'owner' => 'Zuständig',
            'assigned_to' => 'Zugewiesen an',
            'owner_label' => 'Zuständig: :name',
            'previous_count' => '{1} 1 früher|[2,*] :count früher',
            'field_count' => '{1} 1 Feld|[2,*] :count Felder',
            'seen_recently' => 'In den letzten 2 Minuten gesehen',
            'seen_at' => 'Gesehen :elapsed',
            'unassigned' => 'Nicht zugewiesen',
            'open_profile' => 'Besucherprofil öffnen',
            'prior' => 'Frühere Unterhaltungen',
            'history' => 'Verlauf auf dieser Website',
            'safe_only' => 'Nur sicherer Kontext',
            'boundary' => 'Nutzen Sie diesen Kontext, um die aktuelle Anfrage zu beantworten. Sammeln, exportieren oder erschließen Sie ohne Einwilligung keine weiteren Besucherdaten.',
            'no_host_context' => 'Noch kein vom Host bereitgestellter Kontext.',
            'no_prior' => 'Keine früheren Unterhaltungen dieses Besuchers auf dieser Website.',
            'last_activity' => 'Letzte Aktivität :elapsed',
            'last_activity_none' => 'noch keine',
            'last_activity_label' => 'Letzte Aktivität: :elapsed',
            'close' => 'Unterhaltung schließen',
            'reopen' => 'Unterhaltung wieder öffnen',
            'not_reported' => 'Nicht gemeldet',
            'not_provided' => 'Nicht angegeben',
            'session_diagnostics' => 'Sitzungsdiagnose',
            'no_page_state' => 'Noch kein Seitenstatus des Besuchers.',
        ],

        'ticket' => [
            'heading' => 'Verknüpftes Ticket',
            'work' => 'Arbeit am verknüpften Ticket',
            'actions' => 'Ticket-Aktionen',
            'lede' => 'Halten Sie Zuständigkeit und Lebenszyklus nah an der Unterhaltung.',
            'create_hint' => 'Erstellen oder verknüpfen Sie ein Ticket, wenn der nächste Schritt dauerhafte Nachverfolgung braucht.',
            'create' => 'Ticket erstellen',
            'open' => 'Ticket öffnen',
            'assign' => 'Ticket zuweisen',
            'close' => 'Ticket schließen',
            'reopen' => 'Ticket wieder öffnen',
            'pending' => 'Als wartend markieren',
            'none' => 'Kein Ticket',
            'title' => 'Titel',
            'category' => 'Kategorie',
            'priority' => 'Priorität',
            'uncategorized' => 'Ohne Kategorie',
            'resolution_note' => 'Lösungsnotiz',
            'resolution_hint' => 'Was sich geändert hat oder warum dies geschlossen werden kann.',
            'claim' => 'Unterhaltung übernehmen',
            'release' => 'Unterhaltung freigeben',
        ],

        'references' => [
            'heading' => 'Support-Referenzen',
            'lede' => 'Nutzen Sie diese Referenzen, wenn der Besucher oder ein anderer Agent diesen Support-Verlauf wiederfinden muss.',
            'current' => 'Aktueller Support-Code',
            'same_visitor' => 'Support-Codes desselben Besuchers',
            'records' => 'Aktuelle Einträge und Einträge desselben Besuchers',
            'visitor_reference' => 'Besucherreferenz',
            'note' => 'Referenznotiz',
            'none' => 'Noch keine früheren Support-Codes.',
        ],

        'cobrowse' => [
            'heading' => 'Cobrowse',
            'request' => 'Cobrowse anfragen',
            'consent' => 'Einwilligung erteilt',
            'updates' => 'Cobrowse-Aktualisierungen',
            'waiting' => 'Warten auf Live-Cobrowse-Aktualisierungen.',
            'transport_health' => 'Übertragungszustand',
            'transport_detail' => 'Übertragungsdetails',
            'telemetry' => 'Verbindungstelemetrie',
            'no_telemetry' => 'Noch keine Cobrowse-Verbindungstelemetrie.',
            'session_timeline' => 'Sitzungsverlauf',
            'recovery_timeline' => 'Wiederherstellungsverlauf',
            'recovery_action' => 'Wiederherstellungsaktion',
            'guidance' => 'Agentenhinweise',
            'page_snapshot' => 'Seiten-Momentaufnahme',
            'no_snapshot' => 'Noch keine bereinigte Seiten-Momentaufnahme.',
            'snapshot_freshness' => 'Aktualität der Momentaufnahme',
            'snapshot_guidance' => 'Hinweise zur Aktualisierung der Momentaufnahme',
            'fresh_path' => 'Pfad für neue Momentaufnahme',
            'fresh_requested' => 'Neue Momentaufnahme bereits angefragt',
            'fresh_waiting' => 'Warten auf das Besucher-Widget, bevor eine weitere Momentaufnahme angefragt wird.',
            'replay_preview' => 'Wiedergabevorschau',
            'replay_heading' => 'Cobrowse-Wiedergabevorschau',
            'no_replay' => 'Noch keine Wiedergabevorschau.',
            'refresh_preview' => 'Vorschau aktualisieren',
            'mutation_stream' => 'Änderungsstrom',
            'no_mutations' => 'Noch keine Diagnose zum Änderungsstrom.',
            'visitor_page' => 'Besucherseite',
            'data_boundary' => 'Datengrenze',
            'masked' => 'Maskiert',
            'status_safety' => 'Statussicherheit',
            'requested_by' => 'Angefragt von',
            'stopped_by' => 'Beendet von',
            'requested' => 'Angefragt',
            'stopped' => 'Beendet',
            'reported' => 'Gemeldet',
            'state' => 'Zustand',
            'focus' => 'Fokus',
            'scroll' => 'Scrollposition',
            'viewport' => 'Ansichtsfenster',
            'url' => 'URL',
            'nodes' => 'Knoten',
            'samples' => 'Messwerte',
            'rtt' => 'Umlaufzeit',
            'max_rtt' => 'Maximale Umlaufzeit',
            'payload' => 'Nutzlast',
            'max_payload' => 'Maximale Nutzlast',
            'batches' => 'Stapel',
            'dropped' => 'Verworfen',
            'dropped_batches' => 'Verworfene Stapel',
            'mutations' => 'Änderungen',
            'skipped' => 'Übersprungen',
            'reconnects' => 'Neuverbindungen',
            'last_sequence' => 'Letzte Sequenz',
            'last_report' => 'Letzte Meldung',
            'pressure' => 'Auslastung',
        ],
    ],

    'validation' => [
        'reply_template' => 'Wählen Sie eine verfügbare Antworthilfe.',
        'body' => 'Bitte geben Sie eine Antwort ein oder hängen Sie eine Datei an.',
    ],

    'reply_templates' => [
        'looking_into_it' => [
            'label' => 'Wird geprüft',
            'body' => 'Danke für die Rückmeldung. Ich sehe mir das jetzt an und melde mich in Kürze.',
        ],
        'need_more_detail' => [
            'label' => 'Mehr Details nötig',
            'body' => 'Könnten Sie etwas genauer beschreiben, was Sie erwartet haben und was stattdessen passiert ist?',
        ],
        'confirm_resolution' => [
            'label' => 'Lösung bestätigen',
            'body' => 'Danke für Ihre Geduld. Ich gehe davon aus, dass das jetzt gelöst ist, schaue aber gerne weiter nach, falls noch etwas nicht stimmt.',
        ],
        'ticket_follow_up' => [
            'label' => 'Ticket-Nachverfolgung',
            'body' => 'Ich habe daraus ein Ticket gemacht, damit wir die Nachverfolgung im Blick behalten, ohne den Kontext aus dieser Unterhaltung zu verlieren.',
        ],
    ],

    'row' => [
        'attention_waiting_on_visitor' => 'Wartet auf Besucher',
        'attention_needs_reply' => 'Antwort nötig',
        'preview_none_body' => 'Es wurden noch keine Nachrichten gesendet.',
        'preview_none_label' => 'Noch keine Aktivitätsvorschau',
        'preview_no_text' => 'Die Nachricht hat keine Textvorschau.',
        'preview_visitor' => 'Letzte Besuchernachricht',
        'preview_agent' => 'Letzte Agentenantwort',
        'preview_message' => 'Letzte Nachricht',
        'opened' => 'Geöffnet :elapsed',
        'closed' => 'Geschlossen :elapsed',
        'waiting_on_reply' => 'Wartet seit :elapsed auf Antwort',
        'waiting_on_visitor' => 'Wartet seit :elapsed auf den Besucher',
        'waiting_on_update' => 'Wartet seit :elapsed auf eine Aktualisierung',
        'read_new_activity' => 'Neue Aktivität',
        'read_seen' => 'Gesehen',
        'unassigned_agent' => 'Nicht zugewiesen',
        'last_report' => 'Letzte Meldung :value',
        'pressure' => 'Auslastung :value',
        'activity' => 'Aktivität :elapsed',
        'untitled' => 'Unterhaltung ohne Betreff',
        'unknown_visitor' => 'Unbekannter Besucher',
        'no_messages' => 'Noch keine Nachrichten',
    ],
];
