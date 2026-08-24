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
    ],

    'summary' => [
        'lane_narrowed_heading' => ':shown von :matching passenden Unterhaltungen angezeigt',
        'lane_narrowed_detail' => 'Es werden :shown nach dem Support-Spur-Filter „:lane“ angezeigt. :matching entsprechen den übrigen Filtern.',
        'filtered_detail' => 'Es werden :shown angezeigt, die den aktuellen Filtern entsprechen.',
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
        'no_messages' => 'Noch keine Nachrichten',
    ],
];
