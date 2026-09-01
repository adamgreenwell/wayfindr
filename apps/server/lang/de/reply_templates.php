<?php

/*
 * Drafted from lang/en/reply_templates.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue de --catalogue=reply_templates --score`. Every
 * value here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * Two terms that have to stay apart, and are the reason this catalogue needed a
 * glossary entry of its own: `Antwortvorlage` is the account-managed template
 * this page edits, and `Antworthilfe` is the built-in composer helper named in
 * conversations.php. The empty state says one stays available until your team
 * adds the other, so collapsing them would make that sentence say nothing.
 *
 * Action labels are infinitive and address nobody ("Vorlage erstellen"), per the
 * register rule -- a control names an action rather than speaking to the reader.
 *
 * No plural strings in this catalogue.
 */

return [
    'title' => 'Antwortvorlagen',
    'subtitle' => 'Kontoweite Antworthilfen für häufige Besuchermeldungen verwalten.',
    'back' => 'Zurück zum Konto',

    'flash' => [
        'created' => 'Antwortvorlage erstellt.',
        'updated' => 'Antwortvorlage aktualisiert.',
        'archived' => 'Antwortvorlage archiviert.',
    ],

    'standards' => [
        'heading' => 'Vorlagenstandards',
        'lede' => 'Wiederverwendbar, sicher und trotzdem menschlich.',
        'calm' => 'Behandeln Sie Vorlagen als ruhige Ausgangspunkte, nicht als Skripte, die Agenten unverändert senden müssen.',
        'use_for' => 'Verwenden Sie Vorlagen für Bestätigungen, Statusmeldungen, nächste Schritte und häufige Rückfragen.',
        'keep_out' => 'Halten Sie für Besuchende sichtbare Vorlagen frei von Passwörtern, Zahlungsdaten, internen Übergabenotizen und Zusagen, die Ihr Team nicht halten kann.',
    ],

    'create' => [
        'heading' => 'Vorlage erstellen',
        'lede' => 'Kurz, wiederverwendbar und vor dem Senden weiterhin bearbeitbar.',
        'name' => 'Vorlagenname',
        'name_placeholder' => 'Rückfrage zur Abrechnung',
        'body' => 'Antworttext',
        'body_placeholder' => 'Danke für die Rückmeldung. Ich prüfe das jetzt und melde mich in Kürze.',
        'submit' => 'Vorlage erstellen',
    ],

    'list' => [
        'heading' => 'Vorlagen',
        'total' => ':count insgesamt',
        'column_template' => 'Vorlage',
        'column_body' => 'Text',
        'column_status' => 'Status',
        'column_manage' => 'Verwalten',
        'active' => 'Aktiv',
        'archived' => 'Archiviert',
    ],

    'empty' => [
        'heading' => 'Noch keine verwalteten Antwortvorlagen.',
        'body' => 'Die eingebauten Antworthilfen bleiben in den Antwortfeldern verfügbar, bis Ihr Team eigene Kontovorlagen anlegt. Legen Sie eine an, sobald Agenten dieselbe ruhige, hilfreiche Antwort immer wieder neu schreiben.',
        'action' => 'Erste Vorlage erstellen',
    ],

    'manage' => [
        'name' => 'Name',
        'body' => 'Text',
        'save' => 'Vorlage speichern',
        'archive' => 'Archivieren',
        'archived_note' => 'Archivierte Vorlagen erscheinen nicht in den Antworthilfen.',
    ],
];
