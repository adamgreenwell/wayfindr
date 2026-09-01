<?php

// See lang/en/tickets.php. Several plural forms are deliberately identical on
// both sides of the `|`: German does not inflect the verb for number the way
// English does, so "1 geschlossen" and "3 geschlossen" are both correct.
return [
    'document_title' => 'Tickets',
    'title' => 'Ticket-Warteschlange',
    'subtitle' => 'Strukturierte Support-Arbeit für :account.',

    'search' => [
        'label' => 'Suche',
        'placeholder' => 'Ticket #123, Support-Code, Betreff, Anfragender',
        'hint' => 'Suche nach Ticketnummer, Betreff, Beschreibung, Support-Code, Anfragendem, E-Mail-Adresse oder anonymer Besucher-ID.',
        'submit' => 'Filter anwenden',
    ],

    'columns' => [
        'subject' => 'Betreff',
        'status' => 'Status',
        'priority' => 'Priorität',
        'category' => 'Kategorie',
        'labels' => 'Labels',
        'assignee' => 'Zugewiesen an',
        'next_step' => 'Nächster Schritt',
        'external_issue' => 'Externes Issue',
        'latest_activity' => 'Letzte Aktivität',
        'timing' => 'Zeit',
        'site' => 'Website',
        'label' => 'Label',
    ],

    'regions' => [
        'filters' => 'Aktive Ticketfilter',
        'lanes' => 'Ticket-Spuren',
        'next_steps' => 'Nächste Ticket-Schritte',
    ],

    'filters' => [
        'assignee' => [
            'all' => 'Beliebige Zuweisung',
            'assigned_to_me' => 'Mir zugewiesen',
            'unassigned' => 'Nicht zugewiesen',
        ],
        'status' => [
            'open' => 'Alle offenen',
            'pending' => 'Wartend',
            'closed' => 'Geschlossen',
            'all' => 'Alle Tickets',
        ],
        'priority_any' => 'Beliebige Priorität',
        'category_any' => 'Beliebige Kategorie',
        'category_uncategorized' => 'Ohne Kategorie',
        'label_any' => 'Beliebiges Label',
        'site_any' => 'Alle Websites',
        'attention' => [
            'all' => 'Beliebiger nächster Schritt',
            'escalated' => 'Kürzlich eskaliert',
            'needs_reply' => 'Antwort nötig',
            'needs_owner' => 'Zuständige Person nötig',
            'needs_agent' => 'Agentenaktualisierung nötig',
            'waiting_on_customer' => 'Wartet auf Kunden',
            'resolved' => 'Gelöst',
        ],
        'external' => [
            'all' => 'Beliebiges externes Issue',
            'failed' => 'Handlungsbedarf',
            'pending' => 'Synchronisierung ausstehend',
            'linked' => 'Verknüpft',
            'none' => 'Kein externes Issue',
        ],
    ],

    'categories' => [
        'question' => 'Frage',
        'bug' => 'Fehler',
        'billing' => 'Abrechnung',
        'access' => 'Zugriff',
        'task' => 'Aufgabe',
        'other' => 'Sonstiges',
    ],

    'statuses' => [
        'open' => 'Offen',
        'pending' => 'Wartend',
        'closed' => 'Geschlossen',
    ],

    'priorities' => [
        'low' => 'Niedrig',
        'normal' => 'Normal',
        'high' => 'Hoch',
        'urgent' => 'Dringend',
    ],

    'chips' => [
        'status' => 'Status: :value',
        'assignee' => 'Zuweisung: :value',
        'site' => 'Website: :value',
        'priority' => 'Priorität: :value',
        'category' => 'Kategorie: :value',
        'label' => 'Label: :value',
        'next_step' => 'Nächster Schritt: :value',
        'external' => 'Externes Issue: :value',
        'search' => 'Suche: :value',
    ],

    'actions' => [
        'clear_filters' => 'Filter zurücksetzen',
        'clear_all' => 'Alle Ticketfilter zurücksetzen',
        'clear_search' => 'Suche zurücksetzen',
        'clear_next_step' => 'Filter für nächsten Schritt zurücksetzen',
        'clear_external' => 'Filter für externes Issue zurücksetzen',
        'open_conversations' => 'Unterhaltungen öffnen',
        'show_all' => 'Alle Tickets anzeigen',
    ],

    'counts' => [
        'tickets' => '{1} 1 Ticket|[2,*] :count Tickets',
        'matches' => '{1} 1 Ticket entspricht|[2,*] :count Tickets entsprechen',
        // DATIVE, because `lane_narrowed_heading` reads "von :matching", and
        // after a bare numeral the adjective takes STRONG endings. Ticket is
        // neuter, so that is -em in the singular ('von 1 passendem Ticket') and
        // -en in the plural. Unterhaltung is feminine and takes -er, which is
        // why the conversation queue's equivalent reads differently.
        'matching_tickets' => '{1} 1 passendem Ticket|[2,*] :count passenden Tickets',
    ],

    'summary' => [
        'lane_narrowed_heading' => ':shown von :matching angezeigt',
        'heading' => [
            'open' => '{1} :count offen|[2,*] :count offen',
            'pending' => '{1} :count wartend|[2,*] :count wartend',
            'closed' => '{1} :count geschlossen|[2,*] :count geschlossen',
            'total' => '{1} :count insgesamt|[2,*] :count insgesamt',
        ],
        'filtered_detail' => '{1} Es wird :shown angezeigt, das den aktuellen Filtern entspricht.|[2,*] Es werden :shown angezeigt, die den aktuellen Filtern entsprechen.',
        'lane_narrowed_detail' => '{1} Es wird :shown nach dem Filter „:lane“ für den nächsten Schritt angezeigt. :matching den übrigen Filtern.|[2,*] Es werden :shown nach dem Filter „:lane“ für den nächsten Schritt angezeigt. :matching den übrigen Filtern.',
    ],

    'empty' => [
        'no_match_filters' => 'Keine Tickets entsprechen diesen Filtern.',
        'none_yet' => 'Noch keine Tickets.',
        'no_pending' => 'Noch keine wartenden Tickets.',
        'no_closed' => 'Noch keine geschlossenen Tickets.',
        'no_open' => 'Noch keine offenen Tickets.',
        'first_run_detail' => 'Tickets entstehen aus Unterhaltungen: Öffnen Sie einen Verlauf und machen Sie daraus über den Ticket-Tab ein dauerhaftes Ticket.',
        'waiting_detail' => 'Wenn Besuchende dauerhafte Nachverfolgung brauchen, erscheinen Tickets hier.',
        'search_detail' => 'Die Suche umfasst Ticketnummer, Betreff, Beschreibung, Support-Code, Anfragenden, E-Mail-Adresse und anonyme Besucher-IDs.',
        'search_heading' => 'Keine Tickets entsprechen „:term“.',
        'next_step_detail' => 'Probieren Sie eine andere Warteschlange für nächste Schritte oder setzen Sie den Filter zurück.',
        'next_step_heading' => 'Derzeit benötigt kein Ticket :phrase.',
        'external_detail' => 'Probieren Sie einen anderen Status für externe Issues oder setzen Sie den Filter zurück.',
        'external_heading' => 'Keine Tickets entsprechen diesem Status für externe Issues.',
        'refine_detail' => 'Versuchen Sie, einen Filter zu entfernen, den Status zu erweitern oder nach einem Support-Code zu suchen, falls Sie einen haben.',
    ],

    'attention_phrase' => [
        'escalated' => 'eine Eskalationsprüfung',
        'needs_reply' => 'eine Besucherantwort',
        'needs_owner' => 'eine zuständige Person',
        'needs_agent' => 'eine Agentenaktualisierung',
        'waiting_on_customer' => 'eine Kundennachverfolgung',
        'resolved' => 'eine Lösungsprüfung',
        'default' => 'diesen nächsten Schritt',
    ],

    'external_state' => [
        'failed' => 'Öffnen Sie das Ticket, um sichere Wiederholungsoptionen zu prüfen.',
        'pending' => 'Wartet auf Bestätigung des externen Trackers.',
        'linked' => 'Die Referenz des externen Trackers ist angehängt.',
        'none' => 'Wayfindr ist der einzige Tracker für dieses Ticket.',
    ],

    'lifecycle' => [
        'pending' => 'Ticket als wartend markiert',
        'closed' => 'Ticket geschlossen',
        'reopened' => 'Ticket wieder geöffnet',
        'unheld' => 'Ticket aus der Warteschleife genommen',
        'escalated' => 'Ticket eskaliert',
        'default' => 'Lebenszyklus-Aktualisierung',
    ],

    'read_state' => [
        'seen' => 'Besucher hat die Antwort gesehen',
        'unseen' => 'Noch nicht gesehen',
        'none' => 'Noch keine Agentenantwort',
        'detail_none' => 'Es wurde noch keine Agentenantwort gesendet.',
        'detail_seen' => 'Gesehen :elapsed',
        'detail_unseen' => 'Die letzte Agentenantwort wurde noch nicht gesehen.',
    ],

    'external_attempt' => [
        'none_label' => 'Noch kein externer Versuch',
        'none_body' => 'Erstellen oder verknüpfen Sie ein externes Issue, wenn dieses Ticket Arbeit in einem anderen Tracker braucht.',
        'failed_label' => ':provider-Synchronisierung fehlgeschlagen',
        'failed_body' => ':project erfordert Aufmerksamkeit. Anbieterdetails werden nicht angezeigt.',
        'pending_label' => ':provider-Synchronisierung ausstehend',
        'pending_body' => ':project wartet auf die Bestätigung des Anbieters.',
        'linked_label' => ':provider-Verknüpfung aktiv',
        'linked_body' => ':project ist mit :reference verknüpft.',
        'linked_body_bare' => ':project ist verknüpft.',
        'removed_label' => ':provider-Verknüpfung entfernt',
        'removed_body' => ':project ist nicht mehr mit :reference verknüpft.',
        'removed_body_bare' => 'Die externe Verknüpfung von :project wurde entfernt.',
        'created_label' => ':provider-Issue erstellt',
        'created_body' => ':project ist mit :reference verknüpft.',
        'created_body_bare' => ':project wurde im externen Tracker erstellt.',
        'project_unknown' => 'Projekt nicht erfasst',
    ],

    'next_action' => [
        'needs_reply' => [
            'title' => 'Dem Besucher antworten',
            'body' => 'Der Besucher hat zuletzt geantwortet. Senden Sie eine klare Antwort und markieren Sie das Ticket dann als wartend oder schließen Sie es, sobald das Ergebnis feststeht.',
            'cta' => 'Zur Antwort springen',
        ],
        'needs_owner' => [
            'title' => 'Zuständige Person zuweisen',
            'body' => 'Für dieses Ticket ist noch niemand zuständig. Weisen Sie es jemandem zu, bevor die Arbeit verloren geht.',
            'cta' => 'Ticket zuweisen',
        ],
        'waiting_on_customer' => [
            'title' => 'Auf den Kunden warten',
            'body' => 'Der Agent hat zuletzt geantwortet. Behalten Sie das Ticket im Blick und nehmen Sie den Faden wieder auf, sobald der Besucher antwortet.',
            'cta' => 'Statusaktionen prüfen',
        ],
        'resolved' => [
            'title' => 'Lösung prüfen',
            'body' => 'Dieses Ticket ist geschlossen. Öffnen Sie es nur wieder, wenn der Kunde zurückkommt oder sich das Ergebnis ändert.',
            'cta' => 'Statusaktionen prüfen',
        ],
        'needs_agent' => [
            'title' => 'Nächste Aktualisierung hinzufügen',
            'body' => 'Dieses Ticket ist zugewiesen und bereit für eine Agentenaktualisierung. Fügen Sie eine Antwort, eine interne Notiz oder eine Statusänderung hinzu.',
            'cta' => 'Aktionen prüfen',
        ],
    ],

    'status_readiness' => [
        'reply_before_closing' => [
            'title' => 'Vor dem Schließen antworten',
            'detail' => 'Der Besucher hat zuletzt geantwortet. Jetzt zu schließen könnte den Kunden warten lassen. Nutzen Sie „wartend“ oder schließen Sie erst nach einer Agentenaktualisierung oder einem bestätigten Ergebnis.',
            'cta' => 'Zur Antwort springen',
        ],
        'assign_first' => [
            'title' => 'Vor Statusänderungen zuweisen',
            'detail' => 'Weisen Sie eine zuständige Person zu, bevor Sie den Status ändern, damit die Nachverfolgung nicht abreißt.',
            'cta' => 'Ticket zuweisen',
        ],
        'pending' => [
            'title' => 'Wartendes Ticket',
            'detail' => 'Dieses Ticket wartet. Öffnen Sie es wieder, wenn der Besucher antwortet oder neue Arbeit ansteht.',
            'cta' => 'Option zum Wiederöffnen prüfen',
        ],
        'calm' => [
            'title' => 'Die Lebenszyklus-Optionen sind ruhig',
            'detail' => 'Der Agent hat zuletzt geantwortet. Markieren Sie das Ticket als wartend, wenn Sie auf den Besucher warten, oder schließen Sie es, sobald das Ergebnis feststeht.',
            'cta' => 'Statusaktionen prüfen',
        ],
        'closed' => [
            'title' => 'Geschlossenes Ticket',
            'detail' => 'Öffnen Sie es nur wieder, wenn der Kunde zurückkommt oder sich das Ergebnis ändert. Nutzen Sie die Notiz zum Wiederöffnen, damit die nächste Person genug Kontext hat.',
            'cta' => 'Option zum Wiederöffnen prüfen',
        ],
        'default' => [
            'title' => 'Die Lebenszyklus-Optionen sind ruhig',
            'detail' => 'Fügen Sie die nächste Aktualisierung, eine interne Notiz oder den Wartestatus hinzu, oder schließen Sie das Ticket, sobald das Ergebnis klar ist.',
            'cta' => 'Statusaktionen prüfen',
        ],
    ],

    'row' => [
        'attention_needs_reply' => 'Antwort nötig',
        'attention_needs_owner' => 'Zuständige Person nötig',
        'attention_waiting_on_customer' => 'Wartet auf Kunden',
        'attention_resolved' => 'Gelöst',
        'attention_needs_agent' => 'Agentenaktualisierung nötig',
        'description_needs_reply' => 'Der Besucher hat zuletzt geantwortet.',
        'description_needs_owner' => 'Weisen Sie dieses Ticket zu, damit es vorankommt.',
        'description_resolved' => 'Das Ticket ist geschlossen.',
        'description_needs_agent' => 'Bereit für eine Agentenaktualisierung.',
        'description_waiting_marked_pending' => 'Als wartend markiert.',
        'description_waiting_agent_replied' => 'Der Agent hat zuletzt geantwortet.',
        'preview_visitor' => 'Besuchernachricht',
        'preview_agent' => 'Agentenantwort',
        'preview_message' => 'Letzte Nachricht',
        'preview_no_text' => 'Die Nachricht hat keine Textvorschau.',
        'opened' => 'Geöffnet :elapsed',
        'closed' => 'Geschlossen :elapsed',
        'waiting_on_owner' => 'Wartet seit :elapsed auf die zuständige Person',
        'waiting_on_reply' => 'Wartet seit :elapsed auf Antwort',
        'waiting_on_customer' => 'Wartet seit :elapsed auf den Kunden',
        'waiting_on_update' => 'Wartet seit :elapsed auf eine Aktualisierung',
        'waiting_customer_since_open' => 'Wartet seit Ticketeröffnung auf den Kunden',
        'waiting_agent_since_open' => 'Wartet seit Ticketeröffnung auf eine Agentenaktualisierung',
        'not_linked' => 'Nicht verknüpft',
        'lifecycle_note' => 'Lebenszyklus-Notiz',
        'latest_attempt' => 'Letzter Versuch',
        'escalated_to_you' => 'An Sie eskaliert',
        'escalated_recent' => 'Kürzlich eskaliert',
        'actor_visitor' => 'Besucher',
        'actor_system' => 'System',
        'preview_summary' => 'Ticket-Zusammenfassung',
        'preview_none_body' => 'Öffnen Sie das Ticket, um Kontext zu ergänzen oder die nächste Aktualisierung zu senden.',
        'preview_none_label' => 'Noch keine Aktivitätsvorschau',
        'no_linked_conversation' => 'Keine verknüpfte Unterhaltung',
        'reply_visibility_none' => 'Die Antwortsichtbarkeit beginnt, sobald dieses Ticket mit einer Unterhaltung verknüpft ist.',
        'reply_visibility' => 'Antwortsichtbarkeit:',
        'none' => 'Keine',
        'unassigned' => 'Nicht zugewiesen',
    ],

    'guidance' => [
        'category_aria' => 'Kategorieleitfaden',
        'priority_aria' => 'Prioritätsleitfaden',
        'agent_move' => 'Nächster Schritt: :action',
    ],

    'category_help' => [
        'question' => [
            'description' => 'Allgemeine Frage oder Hilfe zur Anwendung.',
            'guidance' => 'Verwenden für: Klärung, Produktberatung oder „Wie mache ich …?“-Fragen.',
        ],
        'bug' => [
            'description' => 'Etwas ist defekt oder funktioniert nicht wie erwartet.',
            'guidance' => 'Verwenden für: defektes, unerwartetes oder reproduzierbares Verhalten.',
        ],
        'billing' => [
            'description' => 'Preise, Rechnung, Zahlung oder Abrechnung des Kontos.',
            'guidance' => 'Verwenden für: Preise, Rechnungen, Zahlungen, Verlängerungen oder Änderungen am Abrechnungskonto.',
        ],
        'access' => [
            'description' => 'Anmeldung, Berechtigungen oder Zugriff auf das Konto.',
            'guidance' => 'Verwenden für: Anmeldung, Rollen, gesperrte Konten, Berechtigungen oder Identitäts- und Zugriffsfragen.',
        ],
        'task' => [
            'description' => 'Folgearbeiten, Konfiguration oder betriebliche Anfrage.',
            'guidance' => 'Verwenden für: Einrichtung, Konfiguration, betriebliche Arbeiten oder geplante Folgeschritte.',
        ],
        'other' => [
            'description' => 'Alles, was in keine der anderen Kategorien passt.',
            'guidance' => 'Sparsam verwenden; Kontext ergänzen, damit später neu kategorisiert werden kann.',
        ],
    ],

    'priority_help' => [
        'low' => [
            'description' => 'Wünschenswerte Folgearbeit oder nicht blockierende Frage.',
            'agent_action' => 'nach aktiven Blockaden von Besuchenden bearbeiten.',
        ],
        'normal' => [
            'description' => 'Normale Supportanfrage ohne unmittelbare Frist.',
            'agent_action' => 'in normaler Reihenfolge der Warteschlange beantworten.',
        ],
        'high' => [
            'description' => 'Zeitkritisches Problem, das einen wichtigen Kundenprozess betrifft.',
            'agent_action' => 'heute noch vorantreiben.',
        ],
        'urgent' => [
            'description' => 'Geschäftskritisch, aktive Störung oder blockierter Produktivbetrieb.',
            'agent_action' => 'sofort zuweisen und den Besucher auf dem Laufenden halten.',
        ],
    ],

    'flash' => [
        'reply_sent' => 'Antwort gesendet.',
        'assignee_updated' => 'Ticket-Zuständigkeit aktualisiert.',
        'closed' => 'Ticket geschlossen.',
        'escalated' => 'Ticket eskaliert.',
        'label_added' => 'Ticket-Label hinzugefügt.',
        'label_removed' => 'Ticket-Label entfernt.',
        'marked_pending' => 'Ticket als wartend markiert.',
        'note_added' => 'Notiz hinzugefügt.',
        'note_added_posted' => 'Notiz hinzugefügt und im verknüpften Issue veröffentlicht.',
        'note_added_not_posted' => 'Notiz hinzugefügt, aber der externe Kommentar konnte nicht veröffentlicht werden. Siehe Ticket-Aktivität.',
        'reopened' => 'Ticket wieder geöffnet.',
        'updated' => 'Ticket aktualisiert.',
    ],

    'errors' => [
        'note_required' => 'Bitte geben Sie eine interne Notiz ein.',
        'label_needs_content' => 'Verwenden Sie mindestens einen Buchstaben oder eine Ziffer für das Label.',
        'label_reserved' => 'Dieser Label-Name ist für die Ticket-Filterung reserviert.',
        'reply_helper' => 'Wählen Sie eine verfügbare Antwortvorlage.',
        'reply_required' => 'Bitte geben Sie eine Antwort ein.',
        'assignee_not_on_site' => 'Wählen Sie einen Agenten, der dieser Website zugewiesen ist.',
        'escalate_other_agent' => 'Wählen Sie einen anderen Agenten, an den dieses Ticket eskaliert werden soll.',
    ],
];
