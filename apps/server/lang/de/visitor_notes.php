<?php

/* Entwurf aus lang/en/visitor_notes.php. NOCH NICHT GEPRÜFT. */
return [
    'heading' => 'Kontaktnotizen',
    'lede' => 'Privater Kontext zu dieser Person, nicht zu einem einzelnen Ticket',
    'count' => '{0} Keine Notizen|{1} :count Notiz|[2,*] :count Notizen',
    'boundary' => [
        'heading' => 'Privater Teamkontext',
        'body' => 'Diese Notizen sind für Teammitglieder sichtbar, die diesen Kontaktdatensatz öffnen dürfen. Sie werden niemals an den Besucher oder ein externes Ticket gesendet.',
        'care' => 'Erfassen Sie nur, was das Team für die Kontinuität benötigt. Vermeiden Sie Passwörter, Zahlungsdaten, Gesundheitsinformationen und andere unnötige sensible Daten.',
        'delete' => 'Beim Löschen einer Notiz wird ihr Inhalt dauerhaft entfernt. Das Kontoprotokoll behält nur einen Löschbeleg ohne Inhalt.',
    ],
    'form' => [
        'label' => 'Private Kontaktnotiz hinzufügen',
        'placeholder' => 'Kontext für das nächste Teammitglied',
        'help' => 'Bis zu 4.000 Zeichen. Notizen können nicht bearbeitet werden; fügen Sie eine Korrektur hinzu oder löschen Sie die Notiz.',
        'submit' => 'Kontaktnotiz hinzufügen',
    ],
    'empty' => [
        'heading' => 'Noch keine Kontaktnotizen',
        'body' => 'Dauerhafter Teamkontext zu dieser Person erscheint hier.',
    ],
    'stale_page' => [
        'heading' => 'Diese Notizseite ist nicht mehr verfügbar.',
        'action' => 'Zur letzten Notizseite wechseln',
    ],
    'author_unknown' => 'Ehemaliges Teammitglied',
    'delete' => 'Notiz löschen',
    'flash' => [
        'added' => 'Kontaktnotiz hinzugefügt.',
        'deleted' => 'Kontaktnotiz gelöscht. Ihr Inhalt wurde dauerhaft entfernt.',
    ],
    'errors' => [
        'required' => 'Geben Sie vor dem Speichern eine Kontaktnotiz ein.',
    ],
];
