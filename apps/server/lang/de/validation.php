<?php

/**
 * German validation messages.
 *
 * Laravel's own English messages live in the framework, and the translator
 * falls back to them per key -- so this file covers the rules the dashboard
 * actually validates with rather than restating all hundred of them. A rule
 * added later without a line here still produces a correct English message,
 * never a raw key.
 *
 * `attributes` matters more than it looks. Without it a German agent is told
 * "current_password muss ausgefüllt werden", which is worse than English:
 * it reads as a system error rather than as a form asking for something.
 */
return [
    'accepted' => ':attribute muss akzeptiert werden.',
    'after' => ':attribute muss ein Datum nach :date sein.',
    'array' => ':attribute muss eine Liste sein.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Einträge haben.',
        'file' => ':attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss ja oder nein sein.',
    'confirmed' => 'Die Bestätigung für :attribute stimmt nicht überein.',
    'current_password' => 'Das Passwort ist nicht korrekt.',
    'date' => ':attribute muss ein gültiges Datum sein.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Der gewählte Wert für :attribute existiert nicht.',
    'file' => ':attribute muss eine Datei sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'json' => ':attribute muss gültiges JSON sein.',
    'max' => [
        'array' => ':attribute darf höchstens :max Einträge haben.',
        'file' => ':attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'mimes' => ':attribute muss eine Datei des Typs :values sein.',
    'min' => [
        'array' => ':attribute muss mindestens :min Einträge haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'numeric' => ':attribute muss eine Zahl sein.',
    'prohibited' => ':attribute ist nicht erlaubt.',
    'required' => ':attribute muss ausgefüllt werden.',
    'required_if' => ':attribute muss ausgefüllt werden, wenn :other den Wert :value hat.',
    'string' => ':attribute muss Text sein.',
    'timezone' => ':attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'url' => ':attribute muss eine gültige URL sein.',

    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute ist in einem Datenleck aufgetaucht. Bitte wählen Sie ein anderes.',
    ],

    'custom' => [],

    /*
     * Field names as an agent sees them on the form, not as the database
     * spells them.
     */
    'attributes' => [
        // Every field a German page can submit, because the framework rules
        // interpolate `:attribute` and an unnamed one puts the column name into
        // the middle of a German sentence: "body darf höchstens 4000 Zeichen
        // lang sein." The house terms come from the shipped catalogues -- a
        // reply helper is an Antworthilfe, not a Vorlage.
        'assignee_id' => 'Zuweisung',
        'attachment_ids' => 'Anhänge',
        'body' => 'Antwort',
        'label_name' => 'Label',
        'message' => 'Nachricht',
        'note_template' => 'Notizhilfe',
        'pending_note' => 'Notiz zum Wartestatus',
        'post_to_external' => 'Veröffentlichung im verknüpften Issue',
        'reason' => 'Grund',
        'scope_type' => 'Zugriffsbereich',
        'account_id' => 'Konto',
        'site_id' => 'Website',
        'support_code' => 'Support-Code',
        'requested_minutes' => 'Zugriffsdauer',
        'reopen_note' => 'Notiz zur Wiedereröffnung',
        'reply_template' => 'Antworthilfe',
        'target_agent_id' => 'Eskalationsziel',
        'resolution_note' => 'Abschlussnotiz',
        'file' => 'Datei',
        'alert_cadence' => 'Häufigkeit',
        'alert_mode' => 'Benachrichtigungen',
        'current_password' => 'Aktuelles Passwort',
        'encryption' => 'Verschlüsselung',
        'email' => 'E-Mail-Adresse',
        'driver' => 'Scanner',
        'fail_closed' => 'Fail-Closed-Einstellung',
        'from_address' => 'Absenderadresse',
        'from_name' => 'Absendername',
        'host' => 'SMTP-Host',
        'locale' => 'Sprache',
        'language' => 'Sprache',
        'mailer' => 'Transport',
        'name' => 'Name',
        'no_password' => 'Kein Passwort',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwortbestätigung',
        'port' => 'SMTP-Port',
        'socket' => 'ClamAV-Socket',
        'timezone' => 'Zeitzone',
        'to' => 'Empfängeradresse',
        'username' => 'SMTP-Benutzername',
        'disk' => 'Speichermedium',
        'bucket' => 'Bucket-Name',
        'region' => 'Bucket-Region',
        'endpoint' => 'Endpunkt-URL',
        'acl' => 'Objekt-ACL',
        's3_access_key' => 'Zugangsschlüssel-ID',
        's3_secret_key' => 'Geheimer Zugangsschlüssel',
        's3_no_keys' => 'Löschen gespeicherter Zugangsschlüssel',
        's3_confirm_migrated' => 'Bestätigung der Speichermigration',
        'use_path_style' => 'Pfadbasierte Adressierung',
        'retention_days' => 'Aufbewahrungsdauer',
        'prefix' => 'Sicherungspräfix',
        'root' => 'Schlüsselpräfix',
        'archive' => 'Sicherungsarchiv',
        'confirm_name' => 'Instanzname',
        'acknowledge' => 'Bestätigung des Datenverlusts',
        'workers_stopped' => 'Bestätigung angehaltener Schreibvorgänge',
        'key' => 'Bereitschaftsschritt',
        'note' => 'Bestätigungsnotiz',

        // The articles page. `body` was already here for the reply composer;
        // `title` was not, so an over-long article title produced a correct
        // German sentence with the English column name sitting inside it.
        'title' => 'Titel',

        // The API-tokens page. `abilities` and `site_ids` arrive as arrays.
        // The per-entry `abilities.*` and `site_ids.*` forms are deliberately
        // NOT here: a literal dotted key cannot coexist with its own parent in
        // a catalogue that nests on dots, and neither rule is reachable from
        // the form -- the ability checkbox has a fixed value and the site ids
        // come from rendered checkboxes, so only a hand-built request fails
        // them, and that request gets the framework's own wording.
        'expires_in_days' => 'Gültigkeitsdauer in Tagen',
        'abilities' => 'Berechtigungen',
        'site_ids' => 'Websites',

        // The integrations page. Provider and capability values come from
        // fixed controls; these names cover the fields an admin can type.
        'provider' => 'Anbieter',
        'base_url' => 'Basis-URL',
        'credential_token' => 'Token oder Zugangsdaten',
        'webhook_secret' => 'Webhook-Geheimnis',
        'capabilities' => 'Verbindungsfunktionen',

        // The account roster page.
        'account_role' => 'Kontorolle',
        'send_welcome_email' => 'Willkommens-E-Mail',
    ],
];
