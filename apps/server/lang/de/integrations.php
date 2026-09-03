<?php

/*
 * Entwurf auf Grundlage von lang/en/integrations.php. NOCH NICHT GEPRÜFT.
 *
 * Von Hand anhand des Glossars in resources/translation/glossary.php und der
 * Regeln in docs/product/translation-policy.md erstellt. Kontoeigene Namen,
 * URLs, Projektschlüssel und Begriffe aus der Oberfläche eines Anbieters sind
 * Daten; die View kennzeichnet sie mit einer unbekannten Sprache.
 *
 * Aktionen verwenden den Infinitiv, die erklärende Prosa die formelle Anrede
 * „Sie“. Website und Seite bleiben entsprechend dem Glossar getrennt.
 */

return [
    'title' => 'Integrationen',
    'subtitle' => 'Kontoweite Anbieter-Verbindungen und die externen Issues, an die jede Website übergibt.',
    'back' => 'Zurück zum Konto',

    'flash' => [
        'connection_saved' => 'Anbieter-Verbindung gespeichert.',
        'secret_cleared' => 'Geheimnis für eingehende Webhooks gelöscht.',
        'secret_saved' => 'Geheimnis für eingehende Webhooks gespeichert.',
        'capabilities_updated' => 'Anbieterfunktionen aktualisiert.',
    ],

    'connections' => [
        'heading' => 'Anbieter-Verbindungen',
        'count' => '{1} :count Verbindung|[2,*] :count Verbindungen',
        'account_owned' => 'kontoeigen',
        'admin_hint' => 'Anbieter-Verbindungen werden von einer Admin-Person des Kontos verwaltet. Bitten Sie eine Admin-Person, Verbindungen hinzuzufügen oder zu ändern; nach der Einrichtung können alle Agenten sie in Tickets verwenden.',
        'empty' => 'Noch keine Anbieter-Verbindungen.',
        'empty_admin' => 'Verbinden Sie unten :providers mit einem API-Token, damit Agenten Tickets als externe Issues übergeben können.',
        'enabled' => 'Aktiviert',
        'disabled' => 'Deaktiviert',

        'setup' => [
            'heading' => 'Reihenfolge der Verbindungseinrichtung',
            'save_title' => '1. Zuerst die Anbieter-Verbindung speichern.',
            'save_body' => 'Wayfindr erstellt die eindeutige URL für eingehende Webhooks erst, nachdem die Verbindung vorhanden ist.',
            'copy_title' => '2. Die erzeugte Webhook-URL kopieren.',
            'copy_body' => 'Sie erscheint bei der gespeicherten Verbindung oberhalb dieses Formulars.',
            'configure_title' => '3. Den Webhook beim Anbieter einrichten.',
            'configure_body' => 'Fügen Sie diese URL bei :providers ein, verwenden Sie dasselbe Webhook-Geheimnis und wählen Sie Ereignisse für Issue-Status und Issue-Kommentare aus.',
            'map_title' => '4. Eine Website einem Projekt zuordnen.',
            'map_body' => 'Kehren Sie hierher zurück und öffnen Sie die Website unter Website-Projektzuordnungen, damit Tickets wissen, an welches Repository oder Projekt sie übergeben werden sollen.',
            'outbound_only' => 'Anbieter ohne Empfänger für eingehende Webhooks können weiterhin für die unterstützten ausgehenden Funktionen verwendet werden.',
        ],
    ],

    'capabilities' => [
        'heading' => 'Verbindungsfunktionen',
        'help' => 'Wählen Sie aus, was Agenten über diese gespeicherte Verbindung senden dürfen. Signierte eingehende Webhooks werden getrennt über das gemeinsame Geheimnis authentifiziert.',
        'update' => 'Funktionen aktualisieren',
        'labels' => [
            'create_issue' => 'Issues erstellen',
            'add_comment' => 'Kommentare hinzufügen',
            'sync_status' => 'Status abgleichen',
        ],
        'permissions' => [
            'create_issue' => 'Anbieter kann Issues erstellen',
            'add_comment' => 'Anbieter kann Kommentare hinzufügen',
            'sync_status' => 'Anbieter kann den Status abgleichen',
        ],
    ],

    'webhook' => [
        'verified_title' => 'Eingehender Abgleich bestätigt.',
        'verified_body' => 'Wayfindr hat eine signierte Anbieter-Zustellung :elapsed angenommen.',
        'latest' => 'Letztes bestätigtes Ereignis: :event · HTTP-Status: :status',
        'unknown' => 'unbekannt',
        'configured_title' => 'Eingehender Abgleich eingerichtet, nicht bestätigt.',
        'configured_body' => 'Ein Geheimnis ist gespeichert, aber Wayfindr hat noch keine signierte Anbieter-Zustellung angenommen.',
        'missing_title' => 'Eingehender Abgleich nicht eingerichtet.',
        'missing_body' => 'Legen Sie für diese Verbindung ein Webhook-Geheimnis fest und richten Sie den Anbieter auf die folgende URL aus, um den Issue-Status zurückzusynchronisieren.',
        'generated_url' => 'Erzeugte Webhook-URL',
        'settings_aria' => 'Einstellungen für eingehende Webhooks',
        'provider_destination_title' => 'Ziel beim Anbieter:',
        'provider_destination_body' => 'Fügen Sie die erzeugte URL in die Webhook-Einstellungen dieser Verbindung ein.',
        'github_title' => 'GitHub-Einstellungen:',
        'github_body' => 'Verwenden Sie :content_type, lassen Sie die SSL-Prüfung aktiviert und wählen Sie die einzelnen Ereignisse :issues und :comments aus.',
        'gitlab_title' => 'GitLab-Einstellungen:',
        'gitlab_body' => 'Verwenden Sie die erzeugte URL, tragen Sie denselben Wert unter :secret_token ein und aktivieren Sie :issues und :comments.',
        'jira_title' => 'Jira-Einstellungen:',
        'jira_body' => 'Verwenden Sie die erzeugte URL und dasselbe Geheimnis und abonnieren Sie dann Änderungen des Issue-Status sowie Ereignisse für neu erstellte Kommentare.',
        'shared_secret_title' => 'Gemeinsames Geheimnis:',
        'shared_secret_body' => 'Das Geheimnis muss in Wayfindr und beim Anbieter übereinstimmen. Wenn Sie es hier ersetzen, ersetzen Sie es auch dort.',
        'replace_secret' => 'Webhook-Geheimnis ersetzen',
        'set_secret' => 'Webhook-Geheimnis festlegen',
        'update_secret' => 'Geheimnis aktualisieren',
        'enable' => 'Eingehenden Abgleich aktivieren',
    ],

    'create' => [
        'heading' => 'Anbieter-Verbindung hinzufügen',
        'available' => 'Für jede Website dieses Kontos verfügbar',
        'provider' => 'Anbieter',
        'name' => 'Verbindungsname',
        'name_placeholder' => 'GitHub Technik',
        'base_url' => 'Basis-URL',
        'credential' => 'Token oder Platzhalter für Zugangsdaten',
        'webhook_secret' => 'Geheimnis für eingehende Webhooks',
        'webhook_help' => 'Jetzt optional. Speichern Sie zuerst diese Verbindung, um ihre Webhook-URL zu erzeugen. Sie können jetzt ein Geheimnis eingeben und beim Anbieter wiederverwenden oder das Feld leer lassen und beide Seiten festlegen, nachdem die URL erscheint. :github signiert mit :github_header, :jira mit :jira_header und :gitlab sendet es im Header :gitlab_header.',
        'submit' => 'Anbieter-Verbindung speichern',
    ],

    'mappings' => [
        'heading' => 'Website-Projektzuordnungen',
        'count' => '{1} :mapped von :total Website zugeordnet|[2,*] :mapped von :total Websites zugeordnet',
        'help' => 'Projektzuordnungen gelten pro Website: Jede Website wählt aus, an welches externe Projekt ihre Tickets übergeben werden. Ordnen Sie Projekte auf der eigenen Seite der Website zu.',
        'empty' => 'Noch keine Websites.',
        'unmapped' => 'Noch keine externen Projekte zugeordnet.',
        'map' => 'Projekt zuordnen',
        'manage' => 'Verwalten',
    ],

    'providers' => [
        'setup_list' => ':github, :gitlab oder :jira',
        'other' => 'Andere',
        'external_tracker' => 'Externer Tracker',
    ],
];
