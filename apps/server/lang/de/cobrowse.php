<?php

declare(strict_types=1);

return [
    'transport' => [
        'exhausted' => [
            'label' => 'Wiederholungslimit erreicht',
            'message' => 'Wiederholungslimit für neue Snapshots erreicht.',
            'guidance' => 'Fordern Sie einen weiteren Snapshot an, sobald sich die Besucherübertragung beruhigt hat.',
            'recovery_action' => 'Fordern Sie einen weiteren Snapshot an, sobald sich die Besucherübertragung beruhigt hat.',
        ],
        'recovery_locked' => 'Es wurde bereits ein neuer Snapshot angefordert. Warten Sie auf das Besucher-Widget, bevor Sie es erneut versuchen.',
        'inactive' => [
            'label' => 'Nicht verfügbar',
            'message' => 'Die Cobrowse-Übertragung ist nicht aktiv.',
            'guidance' => 'Warten Sie auf eine aktive Cobrowse-Sitzung, bevor Sie sich auf Cobrowse verlassen.',
            'recovery_action' => 'Warten Sie auf einen Bericht der Besucherseite, bevor Sie eine Wiederherstellung anfordern.',
        ],
        'no_reports' => [
            'label' => 'Nicht verfügbar',
            'message' => 'Es sind noch keine Cobrowse-Übertragungsberichte eingegangen.',
            'guidance' => 'Warten Sie auf einen Bericht der Besucherseite, bevor Sie sich auf Cobrowse verlassen.',
            'recovery_action' => 'Warten Sie auf einen Bericht der Besucherseite, bevor Sie eine Wiederherstellung anfordern.',
        ],
        'stale' => [
            'label' => 'Veraltet',
            'message' => 'In den letzten 2 Minuten ist kein Cobrowse-Bericht eingegangen.',
            'guidance' => 'Bitten Sie den Besucher zu bestätigen, was dort zu sehen ist, bevor Sie sich auf die Vorschau verlassen.',
            'recovery_action' => 'Fordern Sie einen neuen Snapshot an, wenn die Vorschau veraltet wirkt, und klären Sie Details im Chat.',
        ],
        'reconnecting' => [
            'label' => 'Verbindet neu',
            'message' => 'Die Besucherübertragung hat sich kürzlich neu verbunden; Vorschaudaten können kurz nachhinken.',
            'guidance' => 'Klären Sie im Chat alles, was von schnell wechselndem Seitenzustand abhängt.',
            'recovery_action' => 'Geben Sie dem Besucher-Widget einen Moment und fordern Sie dann einen neuen Snapshot an, wenn die Vorschau weiterhin nachhinkt.',
        ],
        'degraded' => [
            'label' => 'Eingeschränkt',
            'message' => 'Cobrowse-Berichte gehen ein, aber die Besucherseite ändert sich schneller, als Wayfindr sie vollständig wiedergeben kann.',
            'guidance' => 'Nutzen Sie die Vorschau zur Orientierung und klären Sie schnell wechselnde Details im Chat.',
            'recovery_action' => 'Fordern Sie einen neuen Snapshot an, sobald sich das Besucher-Widget beruhigt hat, und nutzen Sie den Chat für schnell wechselnde Details.',
        ],
        'live' => [
            'label' => 'Live',
            'message' => 'Cobrowse-Berichte gehen normal ein.',
            'guidance' => 'Die Vorschau ist aktuell genug, um sie neben dem Chat zu nutzen.',
            'guidance_pressure' => 'Klären Sie im Chat alles, was von schnell wechselndem Seitenzustand abhängt.',
            'recovery_action' => 'Keine Wiederherstellung erforderlich.',
        ],
    ],

    'consent' => [
        'unavailable' => [
            'label' => 'Nicht verfügbar',
            'message' => 'Der Besucher hat der Cobrowse-Nutzung nicht zugestimmt.',
        ],
        'pending' => [
            'label' => 'Zustimmung ausstehend',
            'message' => 'Warten auf die Zustimmung des Besuchers, bevor Cobrowse starten kann.',
        ],
        'granted' => [
            'label' => 'Zugestimmt',
            'message' => 'Der Besucher hat der Cobrowse-Nutzung zugestimmt.',
        ],
        'revoked' => [
            'label' => 'Widerrufen',
            'message' => 'Der Besucher hat die Zustimmung zur Cobrowse-Nutzung widerrufen.',
        ],
        'ended' => [
            'label' => 'Beendet',
            'message' => 'Die Cobrowse-Sitzung wurde beendet.',
        ],
    ],

    'actions' => [
        'cancel_request' => 'Anforderung abbrechen',
        'end' => 'Cobrowse beenden',
    ],

    'resync' => [
        'fulfilled' => [
            'label' => 'Neuer Snapshot empfangen',
            'message' => 'Das Besucher-Widget hat einen sauberen, maskierten Snapshot gesendet.',
        ],
        'exhausted' => [
            'label' => 'Wiederholungslimit für Snapshot erreicht',
            'message' => 'Das Besucher-Widget hat versucht, einen sauberen Snapshot zu senden, konnte dies aber nicht abschließen. Fordern Sie einen weiteren sauberen Snapshot an oder klären Sie den Seitenzustand im Chat.',
        ],
        'expired' => [
            'label' => 'Snapshot-Anforderung abgelaufen',
            'message' => 'Das Besucher-Widget hat nicht rechtzeitig geantwortet. Fordern Sie einen weiteren sauberen Snapshot an oder fahren Sie im Chat fort.',
        ],
        'delayed' => [
            'label' => 'Snapshot verzögert',
            'message' => 'Das Besucher-Widget hat noch nicht geantwortet. Fordern Sie einen weiteren sauberen Snapshot an oder klären Sie den Seitenzustand im Chat.',
        ],
        'pending' => [
            'label' => 'Neuer Snapshot angefordert',
            'message' => 'Warten darauf, dass das Besucher-Widget einen sauberen Seiten-Snapshot sendet.',
        ],
    ],

    'snapshot_recovery' => [
        'pending' => [
            'label' => 'Snapshot-Aktualisierung bereits angefordert',
            'message' => 'Beim Besucher-Widget wartet bereits eine Anforderung für einen neuen Snapshot. Nutzen Sie den Chat, während es aufholt.',
        ],
        'unknown' => [
            'label' => 'Snapshot-Zeit muss bestätigt werden',
            'message' => 'Fragen Sie den Besucher, was dort zu sehen ist, oder fordern Sie einen neuen Snapshot an, bevor Sie sich auf diese Vorschau verlassen.',
        ],
        'needs_refresh' => [
            'label' => 'Snapshot braucht möglicherweise eine Aktualisierung',
            'message' => 'Fordern Sie einen neuen Snapshot an, bevor Sie sich auf diese Vorschau verlassen, oder bestätigen Sie die Seite im Chat.',
        ],
    ],

    'timeline' => [
        'requested' => [
            'label' => 'Snapshot angefordert',
            'detail' => ':actor hat das Besucher-Widget um einen sauberen, maskierten Snapshot gebeten.',
            'badge' => 'Angefordert',
        ],
        'responded' => [
            'label' => 'Besucher-Widget hat geantwortet',
            'detail' => 'Eine neue Cobrowse-Snapshot-Antwort ist von der Besucherseite eingegangen.',
            'badge' => 'Wiederhergestellt',
        ],
        'refreshed' => [
            'label' => 'Maskierter Snapshot aktualisiert',
            'detail' => 'Der saubere Seiten-Snapshot steht in der Agentenvorschau bereit.',
            'badge' => 'Vorschau aktualisiert',
        ],
        'exhausted' => [
            'label' => 'Wiederholungslimit erreicht',
            'detail' => 'Das Besucher-Widget hat nach wiederholten Fehlversuchen aufgehört, diese Anforderungs-ID zu wiederholen.',
            'badge' => 'Ausgeschöpft',
        ],
        'expired' => [
            'label' => 'Anforderung abgelaufen',
            'detail' => 'Vor dem Ende des Wiederherstellungsfensters ist keine Antwort des Widgets eingegangen.',
            'badge' => 'Abgelaufen',
        ],
        'retry_available' => [
            'label' => 'Erneuter Versuch möglich',
            'detail' => 'Der Support kann einen weiteren sauberen Snapshot anfordern, ohne auf die erste Anforderung zu warten.',
            'badge' => 'Wiederholen',
        ],
        'expires' => [
            'label' => 'Anforderung läuft ab',
            'detail' => 'Wayfindr wird diese veraltete Anforderung nach Ablauf des Zeitfensters nicht mehr anbieten.',
            'badge' => 'Schutzgrenze',
        ],
        'waiting' => [
            'label' => 'Warten auf das Besucher-Widget',
            'detail' => 'Erneuter Versuch möglich :elapsed.',
            'detail_unknown' => 'Erneuter Versuch möglich, sobald sich das Wiederholungsfenster öffnet.',
            'badge' => 'Ausstehend',
        ],
        'ignored' => [
            'label' => 'Snapshot-Antwort ignoriert',
            'badge' => 'Ignoriert',
            'expired' => 'Eine Antwort des Widgets ist nach dem Ende des Wiederherstellungsfensters eingegangen.',
            'mismatched' => 'Eine Antwort des Widgets ist für eine andere Wiederherstellungsanforderung eingegangen.',
            'already_fulfilled' => 'Eine doppelte Antwort des Widgets ist eingegangen, nachdem Wayfindr bereits einen neuen Snapshot angenommen hatte.',
            'unmatched' => 'Eine Antwort des Widgets konnte der aktiven Wiederherstellungsanforderung nicht zugeordnet werden.',
        ],
    ],

    'freshness' => [
        'unknown' => [
            'label' => 'Zeit unbekannt',
            'message' => 'Klären Sie im Chat, was der Besucher sieht, bevor Sie sich auf diese Vorschau verlassen.',
        ],
        'stale' => [
            'label' => 'Veraltet',
            'message' => 'Der Snapshot ist älter als 5 Minuten. Klären Sie es im Chat oder fordern Sie einen neuen Snapshot an.',
        ],
        'aging' => [
            'label' => 'Altert',
            'message' => 'Der Snapshot ist einige Minuten alt. Fordern Sie einen neuen an, wenn sich diese Seite ändert.',
        ],
        'fresh' => [
            'label' => 'Aktuell',
            'message' => 'Der Snapshot wurde kürzlich gemeldet.',
        ],
        'reported' => 'Gemeldet :elapsed',
        'reported_unknown' => 'Meldezeit nicht verfügbar',
    ],

    'units' => [
        'untitled_page' => 'Seite ohne Titel',
        'applied' => ':count angewendet',
        'milliseconds' => ':count ms',
        'bytes' => ':count Bytes',
        'no_text_preview' => 'Keine Textvorschau gemeldet.',
        'viewport' => 'Besucher-Viewport :widthpx',
        'still_active' => 'Läuft noch',
        'not_granted_yet' => 'Noch nicht zugestimmt',
        'batches' => '{1} 1 Stapel|[2,*] :count Stapel',
        'mutations' => '{1} 1 Änderung|[2,*] :count Änderungen',
        'dropped' => ':count verworfen',
        'skipped' => ':count übersprungen',
        'sequence' => 'Sequenz :count',
        'nodes' => '{1} 1 Knoten|[2,*] :count Knoten',
        'masked' => ':count maskiert',
        'not_reported' => 'Nicht gemeldet',
        'focused' => 'Fokussiert',
        'not_focused' => 'Nicht fokussiert',
        'unknown_agent' => 'Unbekannter Agent',
        'visitor' => 'Besucher',
        'not_recorded' => 'Nicht erfasst',
    ],

    'drift' => [
        'steady' => [
            'label' => 'Ausgerichtet',
            'message' => 'Die Wiedergabe-Aktualisierungen treffen die erwarteten Knoten.',
        ],
        'watch' => [
            'label' => 'Leichte Abweichung',
            'message' => 'Einige Wiedergabe-Aktualisierungen passten nicht zu dieser Vorschau. Klären Sie schnell wechselnde Bereiche im Chat.',
        ],
        'drifting' => [
            'label' => 'Abweichend',
            'message' => 'Viele Wiedergabe-Aktualisierungen passen nicht mehr zu dieser Vorschau. Fordern Sie einen neuen Snapshot an, um neu zu synchronisieren.',
        ],
        'summary' => ':unresolved von :addressable abgewichen',
    ],

    'pressure' => [
        'dropped' => '{1} 1 verworfener Stapel|[2,*] :count verworfene Stapel',
        'skipped' => '{1} 1 übersprungene Änderung|[2,*] :count übersprungene Änderungen',
        'separator' => ', ',
        'none' => 'Keine Verwerfungen gemeldet',
        'none_recent' => 'Keine aktuellen Verwerfungen gemeldet',
    ],

    'labels' => [
        'retry_ready_help' => 'Es wird weiterhin gewartet. Sie können jetzt einen weiteren Snapshot anfordern.',
        'retry_ready_recovery' => 'Fordern Sie einen weiteren Snapshot an, wenn die Vorschau weiterhin veraltet wirkt.',
        'request_snapshot' => 'Neuen Snapshot anfordern',
        'request_another_snapshot' => 'Weiteren Snapshot anfordern',
        'requested_by' => 'Angefordert von :actor',
        'received' => 'Empfangen :elapsed',
        'expires' => 'Läuft ab :elapsed',
        'expired' => 'Abgelaufen :elapsed',
    ],

    'realtime' => [
        'listening' => 'Warten auf Live-Aktualisierungen der Unterhaltung.',
        'disconnected' => 'Live-Aktualisierungen getrennt. Verbindung wird wiederhergestellt …',
        'unavailable' => 'Live-Cobrowse-Aktualisierungen sind in diesem Browser nicht verfügbar.',
        'failed' => 'Live-Cobrowse-Aktualisierungen konnten keine Verbindung herstellen.',
        'unauthorized' => 'Die Broadcast-Autorisierung ist fehlgeschlagen.',
        'telemetry_updated' => 'Verbindungstelemetrie live aktualisiert.',
        'snapshot_received' => 'Neuer Snapshot empfangen. Die Vorschau wird aktualisiert …',
        'snapshot_received_idle' => 'Neuer Snapshot live empfangen. Aktualisieren Sie die Vorschau, wenn Sie bereit sind.',
        'changes_received' => 'Neue Cobrowse-Änderungen empfangen. Die Vorschau wird aktualisiert …',
        'update_available' => 'Neue Cobrowse-Aktualisierung verfügbar. Aktualisieren Sie die Vorschau, wenn Sie bereit sind.',
        'preview_updated' => 'Die Vorschau wurde mit den neuesten Cobrowse-Änderungen aktualisiert.',
        'preview_refreshing' => 'Die Vorschau wird aktualisiert …',
        'preview_refresh_failed' => 'Die Vorschau konnte nicht automatisch aktualisiert werden. Nutzen Sie „Vorschau aktualisieren“, um es erneut zu versuchen.',
        'preview_failed' => 'Aktualisierung der Vorschau fehlgeschlagen: :reason',
        'transcript_failed' => 'Aktualisierung des Verlaufs fehlgeschlagen: :reason',
        'retry_limit' => 'Wiederholungslimit für neue Snapshots erreicht. Fordern Sie einen weiteren an, wenn Sie bereit sind.',
    ],

    'visibility' => [
        'visible' => 'Sichtbar',
        'hidden' => 'Ausgeblendet',
        'prerender' => 'Wird vorgerendert',
        'unknown' => 'Nicht gemeldet',
    ],
];
