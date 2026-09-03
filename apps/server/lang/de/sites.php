<?php

/*
 * Entwurf aus lang/en/sites.php. NOCH NICHT GEPRÜFT.
 *
 * Von Hand anhand des Glossars und der Übersetzungsrichtlinie erstellt. Jeder
 * Wert ist ein Vorschlag. Namen, Domains, URLs und Suchbegriffe bleiben
 * verfasste Daten und werden in den Ansichten sprachneutral markiert.
 */
return [
    'document_title' => 'Websites',
    'title' => 'Websites',
    'subtitle' => 'Widget-Installationen, Supportzugriff, Datenschutzregeln und Ticketweiterleitung verwalten.',
    'add_site' => 'Website hinzufügen',

    'flash' => [
        'created' => 'Website erstellt. Kopieren Sie das Installations-Snippet, um die Verbindung abzuschließen.',
        'purged' => 'Website „:site“ wurde zusammen mit :conversations, :tickets und :attachments dauerhaft gelöscht.',
        'purge_counts' => [
            'conversations' => '{1} :count Unterhaltung|[0,*] :count Unterhaltungen',
            'tickets' => '{1} :count Ticket|[0,*] :count Tickets',
            'attachments' => '{1} :count Anhang|[0,*] :count Anhängen',
        ],
    ],

    'index' => [
        'snapshot' => [
            'heading' => 'Überblick zum Websitebetrieb',
            'lede' => 'Ein schneller Überblick über die Websites, auf die Ihre Supportrolle derzeit zugreifen kann.',
            'aria' => 'Kennzahlen zum Websitebetrieb',
            'visible' => [
                'label' => 'Sichtbare Websites',
                'value' => '{1} :count sichtbare Website|[0,*] :count sichtbare Websites',
                'detail' => 'Für Ihre Supportrolle vor dem Filtern sichtbar.',
                'action' => 'Websites prüfen',
            ],
            'workload' => [
                'label' => 'Aktive Supportarbeit',
                'value' => '{1} :count aktive Website|[0,*] :count aktive Websites',
                'detail' => ':conversations, :open_tickets, :pending_tickets auf sichtbaren Websites.',
                'action' => 'Aktive Websites prüfen',
            ],
            'install' => [
                'label' => 'Installationsprüfung',
                'value' => '{1} :count Website benötigt eine Installationsprüfung|[0,*] :count Websites benötigen eine Installationsprüfung',
                'detail' => 'Widget-Installationen, die sich in letzter Zeit nicht gemeldet haben oder noch nie etwas gemeldet haben.',
                'action' => 'Installationen prüfen',
            ],
            'access' => [
                'label' => 'Supportzugriff',
                'value' => '{1} :count Website mit ausdrücklichem Zugriff|[0,*] :count Websites mit ausdrücklichem Zugriff',
                'detail' => '{1} :count verwendet den kontoweiten Ausweichzugriff.|[0,*] :count verwenden den kontoweiten Ausweichzugriff.',
            ],
        ],

        'filters' => [
            'heading' => 'Websitefilter',
            'lede' => 'Verbundene Websites nach Supportarbeit, Installationsstatus oder Name eingrenzen.',
            'clear' => 'Filter zurücksetzen',
            'search' => 'Suchen',
            'placeholder' => 'Websitename oder Domainname',
            'workload' => 'Supportarbeit',
            'install' => 'Installation',
            'install_health' => 'Installationsstatus',
            'state' => 'Zustand',
            'apply' => 'Filter anwenden',
            'active_aria' => 'Aktive Websitefilter',
            'filtered' => 'Gefilterte Websites',
            'all_visible' => 'Alle sichtbaren Websites',
            'none' => 'Keine Filter angewendet',
            'options' => [
                'workload' => [
                    'all' => 'Alle Arbeitslasten',
                    'active' => 'Aktive Supportarbeit',
                    'without_work' => 'Ruhig',
                ],
                'install' => [
                    'all' => 'Alle Installationszustände',
                    'needs_attention' => 'Prüfung erforderlich',
                    'live' => 'Live',
                ],
                'state' => [
                    'active_sites' => 'Aktive Websites',
                    'archived' => 'Archiviert',
                    'all' => 'Alle Zustände',
                ],
            ],
            'summary' => [
                'shown' => '{1} :shown von :visible sichtbar angezeigt|[0,*] :shown von :visible sichtbar angezeigt',
                'visible' => '{1} :count sichtbar|[0,*] :count sichtbar',
            ],
        ],

        'list' => [
            'heading' => 'Verbundene Websites',
            'lede' => 'Für Ihre Supportrolle sichtbar',
            'open_tester' => 'Tester öffnen',
            'columns' => [
                'site' => 'Website',
                'workload' => 'Supportarbeit',
                'access' => 'Zugriff',
                'install_health' => 'Installationsstatus',
                'last_page' => 'Letzte Seite',
            ],
        ],

        'state' => [
            'archived' => 'Archiviert',
        ],
        'common' => [
            'not_set' => 'Nicht festgelegt',
            'not_reported' => 'Nicht gemeldet',
        ],
        'counts' => [
            'open_conversations' => '{1} :count offene Unterhaltung|[0,*] :count offene Unterhaltungen',
            'open_tickets' => '{1} :count offenes Ticket|[0,*] :count offene Tickets',
            'pending_tickets' => '{1} :count wartendes Ticket|[0,*] :count wartende Tickets',
            'assigned' => '{1} :count zugewiesen|[0,*] :count zugewiesen',
            'more' => '{1} + :count weitere Person|[0,*] + :count weitere Personen',
        ],
        'workload' => [
            'none' => 'Keine aktive Supportarbeit',
        ],
        'access' => [
            'explicit' => 'Ausdrücklicher Zugriff',
            'assigned_support' => 'Zugewiesener Support',
            'fallback' => 'Kontoweiter Ausweichzugriff',
            'all_agents' => 'Alle Agenten des Kontos',
        ],
        'install' => [
            'not_installed' => 'Nicht installiert',
            'no_check_in' => 'Noch keine Meldung',
            'finish' => 'Installation abschließen',
            'live' => 'Live',
            'needs_check' => 'Prüfung nötig',
            'seen' => 'Gesehen :elapsed',
            'review' => 'Installation prüfen',
        ],
        'empty' => [
            'actions' => [
                'clear_all' => 'Alle Websitefilter zurücksetzen',
                'clear_search' => 'Suche zurücksetzen',
                'clear_install' => 'Installationsstatus zurücksetzen',
                'clear_workload' => 'Supportarbeitsfilter zurücksetzen',
                'back_to_active' => 'Zurück zu aktiven Websites',
            ],
            'search' => [
                'heading' => 'Keine Website entspricht „:search“.',
                'detail' => 'Die Suche prüft Websitenamen und Domains. Setzen Sie den Suchbegriff zurück oder lockern Sie die anderen Websitefilter, um mehr sichtbare Websites zu prüfen.',
            ],
            'install_attention' => [
                'heading' => 'Im Moment benötigt keine Website eine Installationsprüfung.',
                'detail' => 'Jede sichtbare Website hat kürzlich ein Widget-Signal gesendet. Setzen Sie den Installationsstatus zurück, um alle verbundenen Websites zu prüfen.',
            ],
            'live' => [
                'heading' => 'Keine Live-Widget-Installation entspricht diesen Filtern.',
                'detail' => 'Setzen Sie den Installationsstatus zurück, um Websites anzuzeigen, deren erstes Widget-Signal noch aussteht.',
            ],
            'workload_active' => [
                'heading' => 'Im Moment hat keine Website aktive Supportarbeit.',
                'detail' => 'Setzen Sie den Supportarbeitsfilter zurück, um ruhige Websites einzubeziehen, deren Installation oder Zugriff dennoch geprüft werden sollte.',
            ],
            'workload_quiet' => [
                'heading' => 'Keine ruhige Website entspricht diesen Filtern.',
                'detail' => 'Setzen Sie den Supportarbeitsfilter zurück, um Websites mit aktiven Unterhaltungen oder Tickets einzubeziehen.',
            ],
            'archived' => [
                'heading' => 'Es sind keine Websites archiviert.',
                'detail' => 'Durch die Archivierung wird eine Website außer Betrieb genommen, ohne etwas zu löschen, sodass sie jederzeit rückgängig gemacht werden kann. Wenn hier nichts steht, stellen alle für Sie sichtbaren Websites weiterhin ihr Widget bereit.',
            ],
            'default' => [
                'heading' => 'Für Sie sind noch keine Websites sichtbar.',
                'detail' => 'Fügen Sie die erste Website hinzu, um einen öffentlichen Schlüssel und ein Widget-Installations-Snippet zu erhalten.',
            ],
        ],
    ],

    'create' => [
        'document_title' => 'Website hinzufügen',
        'title' => 'Website hinzufügen',
        'subtitle' => 'Neues Wayfindr-Installationsziel für :account erstellen.',
        'back' => 'Zurück zur Übersicht',
        'details' => [
            'heading' => 'Websitedetails',
            'public_key' => 'Öffentlicher Schlüssel wird automatisch erzeugt',
        ],
        'fields' => [
            'name' => 'Websitename',
            'domain' => 'Domainname',
            'domain_help' => 'Sie können hier eine vollständige URL einfügen. Wayfindr speichert den Hostnamen, damit das Installationsziel übersichtlich bleibt.',
        ],
        'submit' => 'Website erstellen',
    ],

    'tester' => [
        'document_title' => 'Website-Tester',
        'title' => 'Tester für :site',
        'subtitle' => 'Gehostete Beispielseite für das Widget, die Chat-Schleife und die Cobrowsing-Maskierung dieser Website.',
        'back' => 'Zurück zu den Websiteeinstellungen',
        'context' => [
            'heading' => 'Testoberfläche',
            'lede' => 'Websitebezogene Widget-Installation',
            'site' => 'Website',
            'domain' => 'Domainname',
            'not_set' => 'Nicht festgelegt',
            'public_key' => 'Öffentlicher Schlüssel',
            'visitor' => 'Tester-Besucher',
            'inbox' => 'Posteingang',
            'open_conversations' => 'Unterhaltungen öffnen',
        ],
        'run' => [
            'heading' => 'Prüflauf',
            'lede' => 'Schleife vom Widget zum Agenten',
            'widget' => 'Widget-Ansicht',
            'launcher' => 'Starter in der unteren Ecke',
            'agent_view' => 'Agentenansicht',
            'conversations' => 'Unterhaltungen',
            'cobrowse' => 'Cobrowsing-Beispiel',
            'masked_fields' => 'Maskierte Beispielfelder',
        ],
        'sample' => [
            'heading' => 'Beispielseite',
            'lede' => 'Nur sichere Beispieldaten',
            'detail' => 'Üblicher Supportkontext mit fiktiven sensiblen Feldern für den Datenschutzpfad.',
            'current_task' => 'Aktuelle Aufgabe',
            'install_verification' => 'Installationsprüfung',
            'example_route' => 'Beispielroute',
            'safe_context' => 'Sicherer Kontext',
            'form_aria' => 'Fiktives Bestellformular',
            'email' => 'E-Mail-Adresse',
            'password' => 'Passwort',
            'card_number' => 'Kartennummer',
            'support_note' => 'Supportnotiz',
        ],
    ],
];
