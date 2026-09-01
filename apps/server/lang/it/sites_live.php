<?php

/*
 * Drafted from lang/en/sites_live.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue it --catalogue=sites_live --score`. Every value
 * here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * `sito` and `pagina` are different words, so the collision that forced the
 * German `Website`/`Seite` rule does not arise -- but the two still sit on this
 * one screen and are kept apart just as carefully.
 *
 * `visitatore`/`visitatori` is one word split only by number, NOT the sense
 * split German makes. `presenza` needs no compound: `stato` is already a
 * different word. The STATES themselves come from presence.php.
 *
 * Action label is bare imperative; the prose uses `Lei`.
 */

return [
    'document_title' => 'Visitatori in tempo reale',
    'heading' => 'Visitatori in tempo reale: :site',
    'subtitle' => 'Chi è su questo sito in questo momento, comprese le persone che non si sono ancora fatte vive.',

    'board' => [
        'heading' => 'Ora sul sito',
        'column_visitor' => 'Visitatore',
        'column_page' => 'Pagina',
        'column_duration' => 'Sul sito da',
        'column_presence' => 'Presenza',
        'empty' => 'In questo momento non c’è nessuno sul sito.',
        'stranger' => 'Nessun contatto finora',
        'no_page' => 'Non riportata',
        'unnamed' => 'Visitatore :id',
        'conversations' => '{1} :count conversazione|[2,*] :count conversazioni',
        'note' => '{1} Una persona compare qui finché il suo browser si segnala, e sparisce :count minuto dopo che smette. I visitatori ne sono informati nel widget e possono rifiutare.|[2,*] Una persona compare qui finché il suo browser si segnala, e sparisce :count minuti dopo che smette. I visitatori ne sono informati nel widget e possono rifiutare.',
    ],

    'disabled' => [
        'body' => 'Questo sito non registra i visitatori che non si sono ancora fatti vivi, quindi questa lavagna resta vuota per scelta.',
        'turn_on' => ':link per vedere le persone che navigano prima che si facciano vive.',
        'turn_on_link' => 'Attiva la presenza dei visitatori in tempo reale',
        'ask_admin' => 'I titolari dell’account e gli amministratori decidono se questo sito osserva i visitatori che non si sono ancora fatti vivi.',
    ],

    'status' => [
        'live' => 'Aggiornamento in tempo reale.',
        'no_realtime' => 'Questa installazione non esegue aggiornamenti in tempo reale, quindi questo elenco è aggiornato al momento del caricamento della pagina.',
        'unavailable' => 'Gli aggiornamenti in tempo reale non sono disponibili, quindi questo elenco è aggiornato al momento del caricamento della pagina.',
        'presence_off' => 'La presenza dei visitatori in tempo reale è disattivata per questo sito.',
        'reconnecting' => 'Riconnessione agli aggiornamenti in tempo reale.',
        'no_access' => 'Non ha più accesso a questo sito.',
    ],

    'duration' => [
        'seconds' => ':count s',
        'minutes' => ':count min',
        'hours' => ':count h :minutes min',
        'unknown' => '—',
    ],
];
