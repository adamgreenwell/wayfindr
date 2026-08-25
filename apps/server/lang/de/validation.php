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
        'reopen_note' => 'Notiz zur Wiedereröffnung',
        'reply_template' => 'Antworthilfe',
        'target_agent_id' => 'Eskalationsziel',
        'resolution_note' => 'Abschlussnotiz',
        'file' => 'Datei',
        'alert_cadence' => 'Häufigkeit',
        'alert_mode' => 'Benachrichtigungen',
        'current_password' => 'Aktuelles Passwort',
        'email' => 'E-Mail-Adresse',
        'locale' => 'Sprache',
        'name' => 'Name',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwortbestätigung',
    ],
];
