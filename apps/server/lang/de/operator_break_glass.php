<?php

/*
 * Entwurf auf Grundlage von lang/en/operator_break_glass.php. NOCH NICHT
 * GEPRÜFT. Von Hand anhand des Glossars und der Übersetzungsrichtlinie erstellt.
 * Kundendaten bleiben außerhalb dieses Katalogs und werden in den Views mit
 * einer unbekannten Sprache gekennzeichnet.
 */

return [
    'document_title' => 'Betreiberzugriff',
    'title' => 'Betreiberzugriff',
    'introduction' => 'Standardmäßig können Sie keine Unterhaltungen oder Tickets eines Kontos sehen. Fordern Sie den Zugriff hier bei Bedarf für eine Unterhaltung, eine Website oder ein Konto an. Das Konto sieht Ihren Grund, genehmigt oder verweigert die Anfrage und kann den Zugriff jederzeit beenden. Der Zugriff ist nur lesend, läuft selbstständig ab und jede von Ihnen geöffnete Seite wird für das Konto protokolliert.',

    'request' => [
        'heading' => 'Zugriff anfordern',
        'subtitle' => 'Fordern Sie nur den kleinsten Bereich an, der Ihre Frage beantwortet',
        'scope' => [
            'label' => 'Was müssen Sie sehen?',
            'options' => [
                'conversation' => 'Eine Unterhaltung (Support-Code)',
                'site' => 'Eine Website',
                'account' => 'Gesamtes Konto',
            ],
        ],
        'support_code' => [
            'label' => 'Support-Code',
            'help' => 'Füllen Sie dieses Feld aus, wenn Sie eine Unterhaltung gewählt haben.',
        ],
        'site' => [
            'label' => 'Website',
            'choose' => 'Website auswählen',
            'help' => 'Füllen Sie dieses Feld aus, wenn Sie eine Website gewählt haben.',
        ],
        'account' => [
            'label' => 'Konto',
            'choose' => 'Konto auswählen',
            'help' => 'Füllen Sie dieses Feld aus, wenn Sie ein gesamtes Konto gewählt haben.',
        ],
        'duration' => [
            'label' => 'Wie lange benötigen Sie den Zugriff?',
            'options' => [
                'fifteen_minutes' => '15 Minuten',
                'one_hour' => '1 Stunde',
                'four_hours' => '4 Stunden',
                'one_day_maximum' => '24 Stunden (Maximum)',
            ],
        ],
        'reason' => [
            'label' => 'Warum benötigen Sie den Zugriff?',
            'placeholder' => 'Was untersuchen Sie und warum benötigen Sie dafür die Inhalte dieses Kontos?',
        ],
        'submit' => 'Zugriff anfordern',
    ],

    'requests' => [
        'heading' => 'Ihre Anfragen',
        'count' => '{1} :count neuerer Eintrag|[2,*] :count neuere Einträge',
        'empty' => 'Sie haben noch keinen Zugriff auf ein Konto angefordert. Supportdaten bleiben für Betreiber geschlossen, bis ein Konto sie freigibt.',
        'scope_status' => 'Bereich: :scope — Status: :status',
        'requested' => 'Angefordert :elapsed',
        'expires' => 'läuft :elapsed ab',
        'waiting_on' => 'wartet auf :people',
        'waiting_on_fallback' => 'wartet auf eine Kontoinhaberin, einen Kontoinhaber oder eine Admin-Person',
        'self_approve' => 'Selbst genehmigen',
        'open' => 'Zugriff öffnen',
        'close' => 'Jetzt beenden',
    ],

    'grant' => [
        'document_title' => 'Betreiberzugriff',
        'back' => 'Zurück zum Betreiberzugriff',
        'summary' => 'Nur lesender Zugriff bis :until (:elapsed). Jeder Aufruf wird protokolliert und ist für :account sichtbar.',
        'conversations' => [
            'heading' => 'Unterhaltungen',
            'count' => '{1} :count abgedeckt|[2,*] :count abgedeckt',
            'empty' => 'Keine Unterhaltungen in diesem Bereich.',
            'row' => ':site · begonnen :elapsed',
            'view' => 'Protokoll anzeigen',
        ],
        'tickets' => [
            'heading' => 'Tickets',
            'count' => '{1} :count abgedeckt|[2,*] :count abgedeckt',
            'empty' => 'Keine Tickets in diesem Bereich.',
            'row' => ':status · geöffnet :elapsed',
            'view' => 'Anzeigen',
        ],
    ],

    'conversation' => [
        'document_title' => 'Unterhaltungsprotokoll',
        'back' => 'Zurück zur Zugriffsfreigabe',
        'summary' => 'Nur lesendes Protokoll · :site · Zugriff läuft :elapsed ab.',
        'transcript' => [
            'heading' => 'Protokoll',
            'count' => '{1} :count Nachricht|[2,*] :count Nachrichten',
            'empty' => 'Keine Nachrichten in dieser Unterhaltung.',
            'message_heading' => 'Von :sender · :time',
        ],
        'senders' => [
            'visitor' => 'Besucher',
            'agent' => ':name (Agent)',
            'integration' => ':name (Integration)',
            'system' => 'System',
        ],
        'attachment' => [
            'summary' => 'Anhang: :filename (:mime, :size KB, Scan: :scan)',
            'boundary' => 'nur Namen und Größen; der Betreiberzugriff öffnet niemals eine Datei.',
        ],
        'tickets' => [
            'heading' => 'Tickets aus dieser Unterhaltung',
            'count' => '{1} :count verknüpft|[2,*] :count verknüpft',
            'view' => 'Anzeigen',
        ],
    ],

    'ticket' => [
        'document_title' => 'Ticketdatensatz',
        'reference' => 'Ticket Nr. :id',
        'back' => 'Zurück zur Zugriffsfreigabe',
        'heading' => 'Ticket Nr. :id — :subject',
        'summary' => 'Nur lesend · :site · Zugriff läuft :elapsed ab.',
        'record' => [
            'heading' => 'Ticketdatensatz',
            'status' => 'Status',
            'priority' => 'Priorität',
            'category' => 'Kategorie',
            'opened' => 'Geöffnet',
            'conversation' => 'Unterhaltung',
            'out_of_scope' => '(außerhalb des Bereichs)',
        ],
    ],

    'values' => [
        'not_set' => '—',
        'not_available' => 'k. A.',
    ],

    'flash' => [
        'requested' => 'Zugriff für :scope angefordert. Das Konto entscheidet.',
        'requested_generic' => 'Zugriff angefordert. Das Konto entscheidet.',
        'self_approved' => 'Selbst genehmigt — Zugriff auf :scope bis :until.',
        'self_approved_generic' => 'Zugriff selbst genehmigt.',
        'already_expired' => 'Diese Zugriffsfreigabe war bereits abgelaufen; sie wird als abgelaufen erfasst.',
        'closed' => 'Zugriffsfreigabe beendet. Der Zugriff wurde widerrufen.',
    ],

    'validation' => [
        'account_required' => 'Wählen Sie ein Konto für den kontoweiten Zugriff aus.',
        'site_required' => 'Wählen Sie eine Website für den Website-Zugriff aus.',
        'support_code_required' => 'Geben Sie einen Support-Code für den Zugriff auf eine Unterhaltung ein.',
        'conversation_not_found' => 'Für diesen Support-Code wurde keine Unterhaltung gefunden.',
    ],

    'errors' => [
        'grant_not_active' => 'Diese Zugriffsfreigabe ist nicht aktiv.',
        'not_awaiting_approval' => 'Für diese Zugriffsfreigabe steht keine Genehmigung mehr aus.',
        'self_approval_requires_standing' => 'Die Selbstgenehmigung erfordert die Rolle als Inhaber oder Admin des Zielkontos.',
        'account_decides' => 'Dieses Konto hat eine Inhaberin, einen Inhaber oder eine Admin-Person. Diese Person entscheidet; Ihre Anfrage wartet auf sie.',
        'only_active_can_close' => 'Nur eine aktive Zugriffsfreigabe kann beendet werden.',
    ],
];
