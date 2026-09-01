<?php

/*
 * Drafted from lang/en/ticket_labels.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue it --catalogue=ticket_labels --score`. Every
 * value here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * `ticket` is an invariant loanword in Italian -- one ticket, due ticket -- so
 * the plural segments differ only in the number, which is correct rather than
 * a copied line.
 *
 * Register: `Lei` in prose, bare imperative on controls ("Crea", "Salva").
 *
 * Plural strings: usage.tickets, usage.view_visible, manage.in_use.
 */

return [
    'title' => 'Etichette dei ticket',
    'subtitle' => 'Gestione delle etichette condivise usate per il triage dei ticket e i filtri della dashboard.',
    'back' => 'Torna all\'account',

    'flash' => [
        'created' => 'Etichetta del ticket creata.',
        'renamed' => 'Etichetta del ticket rinominata.',
        'deleted' => 'Etichetta del ticket inutilizzata eliminata.',
    ],

    'create' => [
        'heading' => 'Crea etichetta',
        'lede' => 'Crei un\'etichetta di triage riutilizzabile prima che un ticket ne abbia bisogno.',
        'name' => 'Nome dell\'etichetta',
        'name_placeholder' => 'Cliente VIP',
        'submit' => 'Crea etichetta',
    ],

    'list' => [
        'heading' => 'Etichette',
        'total' => ':count in totale',
        'column_label' => 'Etichetta',
        'column_slug' => 'Slug',
        'column_usage' => 'Utilizzo',
        'column_manage' => 'Gestisci',
    ],

    'usage' => [
        'tickets' => '{1} 1 ticket|[2,*] :count ticket',
        'view_visible' => '{1} Vedi 1 ticket visibile|[2,*] Vedi :count ticket visibili',
        'none_visible' => 'Nessun ticket visibile',
    ],

    'manage' => [
        'rename' => 'Rinomina :name',
        'save' => 'Salva etichetta',
        'in_use' => '{1} In uso su 1 ticket|[2,*] In uso su :count ticket',
        'delete' => 'Elimina inutilizzata',
    ],

    'empty' => [
        'heading' => 'Nessuna etichetta dei ticket gestita.',
        'body' => 'Usi le etichette quando i ticket hanno bisogno di contesto di triage ripetibile, segnali di escalation o raggruppamenti di flusso. Inizi con poche etichette che il suo team userà davvero.',
        'action' => 'Crea la prima etichetta',
    ],

    'validation' => [
        'duplicate' => 'Questa etichetta esiste già in questo account.',
        'in_use' => 'Rimuova questa etichetta dai ticket prima di eliminarla.',
        'empty' => 'Usi almeno una lettera o una cifra per l\'etichetta.',
        'reserved' => 'Questo nome di etichetta è riservato al filtro dei ticket.',
    ],
];
