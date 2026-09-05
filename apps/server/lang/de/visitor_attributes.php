<?php

/* Entwurf aus lang/en/visitor_attributes.php. NOCH NICHT GEPRÜFT. */
return [
    'document_title' => 'Besucherattribute',
    'heading' => 'Besucherattribute',
    'subtitle' => 'Ausgewählte Host-Kontextdaten werden zu benannten, typisierten Kontaktdetails, die Agenten verstehen und filtern können.',
    'back' => 'Zurück zum Konto',
    'boundary' => [
        'heading' => 'Datengrenze',
        'lede' => 'Definitionen erfassen keine neuen Daten',
        'body' => 'Eine Definition interpretiert nur einen sicheren Kontextschlüssel, den die Host-Website bereits sendet. Halten Sie die Liste kurz, erwartbar und durch den Datenschutzhinweis der Website abgedeckt.',
        'delete' => 'Das Löschen einer Definition entfernt ihre Bezeichnung und ihren Filter. Der zugrunde liegende Host-Kontextwert wird nicht stillschweigend gelöscht.',
    ],
    'fields' => [
        'key' => 'Host-Kontextschlüssel',
        'key_help' => 'Verwenden Sie Kleinbuchstaben, Zahlen und Unterstriche. Das erste Zeichen muss ein Buchstabe sein.',
        'immutable_key' => 'Der Schlüssel bleibt fest, damit vorhandene Besucherwerte mit derselben Definition verknüpft bleiben.',
        'label' => 'Bezeichnung für Agenten',
        'label_placeholder' => 'Tarif',
        'type' => 'Werttyp',
        'type_help' => 'Eine Typänderung schreibt gespeicherte Werte nicht um. Nicht passende Werte erscheinen als nicht gesetzt.',
    ],
    'types' => ['text' => 'Textwert', 'number' => 'Zahl', 'boolean' => 'Ja oder Nein', 'date' => 'Datum'],
    'create' => ['heading' => 'Attribut definieren', 'lede' => 'Bis zu :count Definitionen pro Konto', 'submit' => 'Attribut definieren'],
    'existing' => [
        'heading' => 'Definierte Attribute',
        'count' => '{0} Keine Definitionen|{1} :count Definition|[2,*] :count Definitionen',
        'empty' => 'Noch wurden keine Besucherattribute definiert.',
        'save' => 'Definition speichern',
        'delete' => 'Definition löschen',
    ],
    'flash' => [
        'created' => 'Besucherattribut definiert.',
        'updated' => 'Definition des Besucherattributs aktualisiert.',
        'deleted' => 'Definition des Besucherattributs gelöscht. Gespeicherter Host-Kontext blieb unverändert.',
    ],
    'errors' => [
        'duplicate' => 'Dieser Host-Kontextschlüssel ist bereits definiert.',
        'limit' => 'Ein Konto kann bis zu :count Besucherattribute definieren.',
        'unsafe_key' => 'Wählen Sie einen nicht sensiblen Host-Kontextschlüssel. Identitäts-, Authentifizierungs-, Zahlungs- und Adressfelder werden hier nicht akzeptiert.',
    ],

    'filters' => [
        'attribute' => 'Attribut',
        'any_attribute' => 'Beliebiges Attribut',
        'value' => 'Exakter Wert',
        'value_placeholder' => 'Abzugleichender Wert',
        'help' => 'Attributwerte werden nach Anwendung des konfigurierten Typs exakt abgeglichen.',
        'invalid' => 'Geben Sie einen Wert ein, der dem ausgewählten Attributtyp entspricht.',
        'manage' => 'Besucherattribute verwalten',
    ],

    'profile' => [
        'heading' => 'Definierte Details',
        'lede' => 'Sicherer Host-Kontext, für dieses Konto benannt',
        'attribute' => 'Attribut',
        'value' => 'Wert',
        'not_set' => 'Nicht festgelegt',
        'yes' => 'Ja',
        'no' => 'Nein',
        'manage' => 'Definitionen verwalten',
    ],
];
