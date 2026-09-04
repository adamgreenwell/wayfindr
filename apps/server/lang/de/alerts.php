<?php

return [
    'document_title' => 'Benachrichtigungen',
    'title' => 'Benachrichtigungszentrale',
    'subtitle' => 'Sichtbare Support-Benachrichtigungen für :account.',

    'center' => [
        'heading' => 'Neueste Benachrichtigungen',
        'lede' => 'Ungelesene Benachrichtigungen bleiben hier, bis die zugehörige Arbeit geöffnet oder als gelesen markiert wird.',
        'lanes' => 'Benachrichtigungsspuren',
        'all' => 'Alle Benachrichtigungen',
        'unread_only' => 'Nur ungelesene',
        'bulk_matching' => 'Passende als gelesen markieren',
        'bulk_unread' => 'Ungelesene als gelesen markieren',
        'bulk_matching_help' => 'Alle ungelesenen Benachrichtigungen, die dieser Ansicht entsprechen, werden als gelesen markiert, auch außerhalb der aktuellen Anzeige.',
        'bulk_unread_help' => 'Alle ungelesenen Benachrichtigungen, auf die Sie noch zugreifen können, werden als gelesen markiert, auch außerhalb der aktuellen Anzeige.',
        'privacy' => 'Benachrichtigungen, auf die Sie nicht mehr zugreifen können, werden ausgeblendet, damit alte Meldungen keine eingeschränkte Support-Arbeit offenlegen.',
    ],

    'delivery' => [
        'heading' => 'Kontext der Benachrichtigungszustellung',
        'region' => 'Persönlicher Kontext der Benachrichtigungszustellung',
        'source_detail' => 'Dashboard-Benachrichtigungen bleiben maßgeblich für Support-Arbeit, die Aufmerksamkeit erfordert.',
        'change_preferences' => 'Benachrichtigungseinstellungen ändern',
        'mode' => [
            'label' => 'Aktueller Modus',
            'assigned_detail' => 'Nur zugewiesene Unterhaltungen und Tickets erzeugen neue Benachrichtigungen für Sie.',
            'quiet_detail' => 'Der Ruhemodus pausiert neue Benachrichtigungen, ohne vorhandene sichtbare Benachrichtigungen zu ändern.',
            'all_detail' => 'Geeignete Support-Arbeit von Websites, die Sie betreuen können, kann Benachrichtigungen erzeugen.',
        ],
        'email' => [
            'label' => 'E-Mail-Zustellung',
            'off' => 'E-Mail aus',
            'digest' => 'Zusammenfassung bevorzugt',
            'unattended' => 'Nur unbeantwortet',
            'immediate' => 'Sofortige E-Mail',
            'off_detail' => 'E-Mail-Benachrichtigungen sind für Ihr Profil ausgeschaltet. Die Benachrichtigungszentrale bleibt hier verfügbar.',
            'digest_detail' => 'Die Sammelzustellung wird bevorzugt, wenn der Planer läuft. Dashboard-Benachrichtigungen erscheinen hier weiterhin sofort.',
            'unattended_detail' => '{1} Eine E-Mail wird nur gesendet, wenn eine Besuchernachricht 1 Minute ungesehen bleibt. Dashboard-Benachrichtigungen erscheinen hier weiterhin sofort.|[2,*] Eine E-Mail wird nur gesendet, wenn eine Besuchernachricht :count Minuten ungesehen bleibt. Dashboard-Benachrichtigungen erscheinen hier weiterhin sofort.',
            'immediate_detail' => 'Die sofortige E-Mail-Zustellung ist aktiviert, wenn E-Mail eingerichtet ist. Dashboard-Benachrichtigungen erscheinen hier weiterhin sofort.',
        ],
    ],

    'filters' => [
        'region' => 'Benachrichtigungen filtern',
        'search_label' => 'Benachrichtigungen durchsuchen',
        'search_placeholder' => 'Support-Code, Ticket-Nr., Betreff, Website oder Besucher',
        'search_help' => 'Durchsucht nur sichtbaren Benachrichtigungskontext; eingeschränkte Support-Arbeit bleibt verborgen.',
        'kind_label' => 'Benachrichtigungstyp',
        'apply' => 'Anwenden',
        'clear' => 'Filter zurücksetzen',
        'active_region' => 'Aktive Benachrichtigungsfilter',
        'active_heading' => 'Aktive Benachrichtigungsfilter',
        'active_detail' => 'Benachrichtigungen sind auf die zu dieser Ansicht passende Support-Arbeit eingegrenzt.',
    ],

    'kinds' => [
        'all' => 'Alle Benachrichtigungen',
        'conversation' => 'Unterhaltungsbenachrichtigungen',
        'ticket' => 'Ticketbenachrichtigungen',
        'sla' => 'SLA-Benachrichtigungen',
    ],

    'focus' => [
        'region' => 'Fokus der Benachrichtigungszentrale',
        'heading' => 'Benachrichtigungsfokus',
        'detail' => 'Was diese Benachrichtigungszentrale vor der Bearbeitung der Einträge zeigt.',
        'view' => 'Ansicht',
        'type' => 'Typ',
        'visible' => 'Sichtbar',
        'unread' => 'Ungelesen',
        'search' => 'Suchen',
    ],

    'chips' => [
        'type' => 'Typ: :value',
        'search' => 'Suche: :value',
    ],

    'counts' => [
        'visible' => '{1} :count sichtbar|[2,*] :count sichtbar',
        'unread' => '{1} :count ungelesen|[2,*] :count ungelesen',
        'conversations' => '{1} :count Unterhaltung|[2,*] :count Unterhaltungen',
        'tickets' => '{1} :count Ticket|[2,*] :count Tickets',
        'sla' => '{1} :count SLA-Benachrichtigung|[2,*] :count SLA-Benachrichtigungen',
        'new_messages' => '{1} 1 neue Nachricht|[2,*] :count neue Nachrichten',
    ],

    'snapshot' => [
        'region' => 'Benachrichtigungsübersicht',
        'visible' => [
            'label' => 'Sichtbare Benachrichtigungen',
            'present' => 'Aktuelle Benachrichtigungen, die Sie noch öffnen können.',
            'empty' => 'In dieser Benachrichtigungsansicht erfordert derzeit nichts Aufmerksamkeit.',
        ],
        'unread' => [
            'label' => 'Ungelesene Benachrichtigungen',
            'present' => 'Warten noch auf Prüfung oder Markierung als gelesen.',
            'empty' => 'Keine ungelesenen Benachrichtigungen warten auf Prüfung.',
        ],
        'conversations' => [
            'label' => 'Unterhaltungsbenachrichtigungen',
            'present' => 'Besucherantworten und Chat-Nachverfolgung.',
            'empty' => 'Keine Benachrichtigungen zu Besucherantworten in dieser Ansicht.',
        ],
        'tickets' => [
            'label' => 'Ticketbenachrichtigungen',
            'present' => 'Ticketzuweisungen und dauerhafte Arbeit.',
            'empty' => 'Keine Benachrichtigungen zu Ticketzuweisungen in dieser Ansicht.',
        ],
        'sla' => [
            'label' => 'SLA-Benachrichtigungen',
            'present' => 'Fristen nähern sich oder wurden bereits überschritten.',
            'empty' => 'Keine SLA-Frist erfordert in dieser Ansicht Aufmerksamkeit.',
        ],
    ],

    'summary' => [
        'unread_heading' => 'Sichtbare ungelesene Benachrichtigungen werden angezeigt.',
        'latest' => '{1} Die neueste sichtbare Benachrichtigung wird angezeigt.|[2,*] Die neuesten :count sichtbaren Benachrichtigungen werden angezeigt.',
        'matching_heading' => [
            'all' => '{1} 1 passende Benachrichtigung wird angezeigt.|[2,*] :count passende Benachrichtigungen werden angezeigt.',
            'unread' => '{1} 1 passende ungelesene Benachrichtigung wird angezeigt.|[2,*] :count passende ungelesene Benachrichtigungen werden angezeigt.',
            'conversation' => '{1} 1 passende Unterhaltungsbenachrichtigung wird angezeigt.|[2,*] :count passende Unterhaltungsbenachrichtigungen werden angezeigt.',
            'ticket' => '{1} 1 passende Ticketbenachrichtigung wird angezeigt.|[2,*] :count passende Ticketbenachrichtigungen werden angezeigt.',
            'sla' => '{1} 1 passende SLA-Benachrichtigung wird angezeigt.|[2,*] :count passende SLA-Benachrichtigungen werden angezeigt.',
        ],
        'capped_heading' => [
            'all' => '{1} :shown von 1 passender Benachrichtigung werden angezeigt.|[2,*] :shown von :count passenden Benachrichtigungen werden angezeigt.',
            'unread' => '{1} :shown von 1 passender ungelesener Benachrichtigung werden angezeigt.|[2,*] :shown von :count passenden ungelesenen Benachrichtigungen werden angezeigt.',
            'conversation' => '{1} :shown von 1 passender Unterhaltungsbenachrichtigung werden angezeigt.|[2,*] :shown von :count passenden Unterhaltungsbenachrichtigungen werden angezeigt.',
            'ticket' => '{1} :shown von 1 passender Ticketbenachrichtigung werden angezeigt.|[2,*] :shown von :count passenden Ticketbenachrichtigungen werden angezeigt.',
            'sla' => '{1} :shown von 1 passender SLA-Benachrichtigung werden angezeigt.|[2,*] :shown von :count passenden SLA-Benachrichtigungen werden angezeigt.',
        ],
        'capped_detail' => [
            'all' => '{1} Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. 1 Benachrichtigung passt zu dieser Ansicht.|[2,*] Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. :count Benachrichtigungen passen zu dieser Ansicht.',
            'unread' => '{1} Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. 1 ungelesene Benachrichtigung passt zu dieser Ansicht.|[2,*] Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. :count ungelesene Benachrichtigungen passen zu dieser Ansicht.',
            'conversation' => '{1} Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. 1 Unterhaltungsbenachrichtigung passt zu dieser Ansicht.|[2,*] Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. :count Unterhaltungsbenachrichtigungen passen zu dieser Ansicht.',
            'ticket' => '{1} Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. 1 Ticketbenachrichtigung passt zu dieser Ansicht.|[2,*] Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. :count Ticketbenachrichtigungen passen zu dieser Ansicht.',
            'sla' => '{1} Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. 1 SLA-Benachrichtigung passt zu dieser Ansicht.|[2,*] Nach der aktuellen Anzeigegrenze werden :shown Benachrichtigungen gezeigt. :count SLA-Benachrichtigungen passen zu dieser Ansicht.',
        ],
    ],

    'empty' => [
        'search' => [
            'heading' => 'Keine Benachrichtigungen entsprechen „:search“.',
            'detail' => 'Die Suche prüft Support-Codes, Ticketnummern, Betreffe, Websites, Besucher und Nachrichtenvorschauen, auf die Sie noch zugreifen können.',
        ],
        'kind' => [
            'conversation' => 'Keine Unterhaltungsbenachrichtigungen passen zu dieser Ansicht.',
            'ticket' => 'Keine Ticketbenachrichtigungen passen zu dieser Ansicht.',
            'sla' => 'Keine SLA-Benachrichtigungen passen zu dieser Ansicht.',
            'detail' => 'Versuchen Sie es mit allen Benachrichtigungstypen, um weitere zugängliche Support-Signale einzubeziehen.',
        ],
        'unread' => [
            'heading' => 'Sie sind auf dem neuesten Stand.',
            'detail' => 'Neue geeignete Besucherantworten und Ticketzuweisungen erscheinen hier, wenn sie Aufmerksamkeit benötigen.',
        ],
        'all' => [
            'heading' => 'Noch keine sichtbaren Benachrichtigungen.',
            'detail' => 'Besucherantworten und Ticketzuweisungen, die Sie betreuen können, erscheinen hier, sobald sie Aufmerksamkeit benötigen.',
        ],
    ],

    'actions' => [
        'clear_search' => 'Suche zurücksetzen',
        'clear_all_filters' => 'Alle Benachrichtigungsfilter zurücksetzen',
        'clear_type' => 'Typfilter zurücksetzen',
        'show_recent' => 'Neueste Benachrichtigungen anzeigen',
        'back_to_dashboard' => 'Zurück zum Dashboard',
        'review_preferences' => 'Benachrichtigungseinstellungen prüfen',
    ],

    'card' => [
        'status' => [
            'unread' => 'Ungelesen',
            'read' => 'Gelesen',
            'aria' => 'Benachrichtigungsstatus: :status',
            'read_at' => 'Gelesen :elapsed',
        ],
        'untitled_ticket' => 'Ticket ohne Titel',
        'untitled_conversation' => 'Unterhaltung ohne Titel',
        'ticket_assigned' => 'Ticket zugewiesen',
        'automation_matched' => 'Automatisierung hat diesen Supportvorgang erkannt',
        'automation_rule' => 'Regel:',
        'sla_warning' => 'SLA-Frist nähert sich',
        'sla_breached' => 'SLA-Frist überschritten',
        'sla_metric' => 'Ziel: :metric',
        'sla_warning_why' => 'Diese Arbeit hat den größten Teil ihres Ziels innerhalb der Supportzeiten verbraucht.',
        'sla_breach_why' => 'Diese Arbeit hat ihr Ziel innerhalb der Supportzeiten überschritten.',
        'sla_warning_next' => 'Öffnen Sie die Arbeit jetzt und klären Sie, wer sie vor Ablauf des Ziels voranbringt.',
        'sla_breach_next' => 'Öffnen Sie die Arbeit, übernehmen Sie Verantwortung und stellen Sie einen klaren nächsten Schritt her.',
        'assigned_by' => ':name hat Ihnen dieses Ticket zugewiesen.',
        'someone' => 'Jemand',
        'why' => 'Grund für diese Benachrichtigung:',
        'next_move' => 'Nächster Schritt:',
        'ticket_why' => 'Dieses Ticket wurde Ihnen zugewiesen. Öffnen Sie es oder markieren Sie diese Benachrichtigung nach der Prüfung als gelesen.',
        'ticket_next' => 'Öffnen Sie das zugewiesene Ticket und legen Sie Zuständigkeit, Priorität oder nächsten Status fest.',
        'automation_why' => 'Eine konfigurierte Regel hat Wayfindr ausdrücklich angewiesen, Sie über diesen Vorgang zu informieren.',
        'automation_next' => 'Öffnen Sie den Vorgang, prüfen Sie die automatischen Änderungen und führen Sie den passenden nächsten Schritt aus.',
        'conversation_why' => 'Eine Besucherantwort wartet in einer Unterhaltung, die Sie betreuen können. Öffnen Sie die Unterhaltung oder markieren Sie diese Benachrichtigung nach der Bearbeitung als gelesen.',
        'conversation_next' => 'Öffnen Sie die Unterhaltung und antworten Sie, während der Besucher wartet.',
        'ticket_reference' => 'Ticket-Nr. :id',
        'on_site' => 'auf :site',
        'priority' => 'Priorität :priority',
        'unknown_site' => 'Unbekannte Website',
        'open_ticket' => 'Ticket öffnen',
        'open_conversation' => 'Unterhaltung öffnen',
        'mark_read' => 'Als gelesen markieren',
        'already_read' => 'Bereits gelesen.',
    ],
];
