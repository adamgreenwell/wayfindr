<?php

/*
 * Drafted from lang/en/ticket_labels.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue de --catalogue=ticket_labels --score`. Every
 * value here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * `Label` and `Ticket` are declared cognates -- German uses both words -- so a
 * value here reading identically to the English is the decision, not a gap.
 *
 * Action labels are infinitive and address nobody ("Label erstellen").
 *
 * Plural strings: usage.tickets, usage.view_visible, manage.in_use.
 */

return [
    'title' => 'Ticket-Labels',
    'subtitle' => 'Kontoweite Labels für die Ticket-Triage und Dashboard-Filter verwalten.',
    'back' => 'Zurück zum Konto',

    'flash' => [
        'created' => 'Ticket-Label erstellt.',
        'renamed' => 'Ticket-Label umbenannt.',
        'deleted' => 'Ungenutztes Ticket-Label gelöscht.',
    ],

    'create' => [
        'heading' => 'Label erstellen',
        'lede' => 'Legen Sie ein wiederverwendbares Triage-Label an, bevor ein Ticket es braucht.',
        'name' => 'Labelname',
        'name_placeholder' => 'VIP-Kunde',
        'submit' => 'Label erstellen',
    ],

    'list' => [
        'heading' => 'Labels',
        'total' => ':count insgesamt',
        'column_label' => 'Label',
        'column_slug' => 'Slug',
        'column_usage' => 'Verwendung',
        'column_manage' => 'Verwalten',
    ],

    'usage' => [
        'tickets' => '{1} 1 Ticket|[2,*] :count Tickets',
        'view_visible' => '{1} 1 sichtbares Ticket anzeigen|[2,*] :count sichtbare Tickets anzeigen',
        'none_visible' => 'Keine sichtbaren Tickets',
    ],

    'manage' => [
        'rename' => ':name umbenennen',
        'save' => 'Label speichern',
        'in_use' => '{1} Wird für 1 Ticket verwendet|[2,*] Wird für :count Tickets verwendet',
        'delete' => 'Ungenutztes löschen',
    ],

    'empty' => [
        'heading' => 'Noch keine verwalteten Ticket-Labels.',
        'body' => 'Verwenden Sie Labels, wenn Tickets wiederkehrenden Triage-Kontext, Eskalationshinweise oder eine Gruppierung im Arbeitsablauf brauchen. Beginnen Sie mit wenigen Labels, die Ihr Team wirklich nutzt.',
        'action' => 'Erstes Label erstellen',
    ],

    'validation' => [
        'duplicate' => 'Dieses Label gibt es in diesem Konto bereits.',
        'in_use' => 'Entfernen Sie dieses Label von den Tickets, bevor Sie es löschen.',
        'empty' => 'Verwenden Sie mindestens einen Buchstaben oder eine Ziffer für das Label.',
        'reserved' => 'Dieser Labelname ist für die Ticket-Filterung reserviert.',
    ],
];
