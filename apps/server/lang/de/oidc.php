<?php

return [
    'settings' => [
        'heading' => 'Single Sign-on mit OpenID Connect',
        'enabled' => 'Aktiviert',
        'disabled' => 'Nicht aktiviert',
        'help' => 'Verbinden Sie einen Identitätsanbieter für vorhandene Agenten. SSO erstellt keine Benutzer und ändert keine Rollen.',
        'callback_help' => 'Registrieren Sie diese Rückruf-URL beim Identitätsanbieter:',
        'name' => 'Name des Anbieters',
        'issuer_url' => 'Aussteller-URL',
        'client_id' => 'Client-ID',
        'client_secret' => 'Client-Geheimnis',
        'secret_help' => 'Wayfindr verschlüsselt dieses Geheimnis und zeigt es nicht erneut an.',
        'secret_keep_help' => 'Leer lassen, um das gespeicherte Geheimnis beizubehalten.',
        'enable_checkbox' => 'Agenten die Anmeldung mit diesem Anbieter erlauben',
        'save' => 'Single Sign-on speichern',
        'secret_required' => 'Geben Sie beim Verbinden eines Anbieters das Client-Geheimnis ein.',
        'public_https_required' => 'Der Aussteller muss eine öffentlich auflösbare HTTPS-URL sein.',
    ],
    'sign_in' => [
        'failed' => 'Single Sign-on konnte nicht abgeschlossen werden. Prüfen Sie den Kontonamen oder wenden Sie sich an die Administration.',
    ],
    'flash' => [
        'connection_updated' => 'Single-Sign-on-Einstellungen aktualisiert.',
    ],
];
