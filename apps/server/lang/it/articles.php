<?php

/*
 * Drafted from lang/en/articles.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue it --catalogue=articles --score`. Every value
 * here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * The markup inside `write.body_placeholder` is SYNTAX, not prose: `##`, `**`
 * and the link brackets are what the writer types, so they stay while the words
 * around them translate.
 *
 * Register: `Lei` in prose, bare imperative on controls ("Crea", "Salva").
 *
 * Plural strings: list.count.
 */

return [
    'title' => 'Articoli',
    'subtitle' => 'Risposte che un visitatore può trovare senza chiedere.',
    'back_to_account' => 'Torna all\'account',
    'back_to_articles' => 'Torna agli articoli',

    'flash' => [
        'created' => 'Articolo creato come bozza.',
        'saved' => 'Articolo salvato.',
        'published' => 'Articolo pubblicato. Ora i visitatori possono trovarlo.',
        'unpublished' => 'Pubblicazione ritirata. I visitatori non possono più trovare l\'articolo.',
        'deleted' => 'Articolo eliminato.',
    ],

    'validation' => [
        'title' => 'Assegni un titolo all\'articolo.',
        'body' => 'Scriva qualcosa che un visitatore possa leggere.',
    ],

    'state' => [
        'published' => 'Pubblicato',
        'draft' => 'Bozza',
    ],

    'write' => [
        'heading' => 'Scrivi un articolo',
        'lede' => 'Salvato come bozza. Nulla raggiunge un visitatore finché non lo pubblica.',
        'title_label' => 'Titolo',
        'title_placeholder' => 'Come funzionano i rimborsi',
        'body_label' => 'Testo',
        'body_placeholder' => "## Rimborsi\n\nRimborsiamo entro **14 giorni**. Scriva a [supporto](mailto:help@example.com).",
        'markup_hint' => 'Intestazioni con :headings, elenchi con :bullets, link come :links, enfasi con :emphasis. Tutto il resto è letto come testo ordinario.',
        'markup_links' => '[parole](https://…)',
        'markup_emphasis' => '**grassetto**',
        'submit' => 'Crea bozza',
    ],

    'list' => [
        'heading' => 'Tutto quanto scritto finora',
        'lede' => 'Prima le bozze, perché sono quelle che chiedono ancora lavoro.',
        'count' => '{1} :count articolo|[2,*] :count articoli',
        'search_label' => 'Cerca',
        'search_placeholder' => 'Per titolo',
        'search_submit' => 'Cerca articoli',
        'column_article' => 'Articolo',
        'column_state' => 'Stato',
        'column_edited' => 'Ultima modifica',
        'no_match' => 'Nessun titolo di articolo corrisponde a “:search”.',
        'empty' => 'Non è ancora stato scritto nulla. Il primo articolo è di solito la domanda a cui il suo team risponde più spesso.',
    ],

    'detail' => [
        'subtitle' => 'Modifichi la risposta, poi decida chi può vederla.',
        'visibility_heading' => 'Chi può vederlo',
        'visible' => 'I visitatori lo trovano nel widget quando cercano.',
        'hidden' => 'Una bozza. Solo questo account può vederla.',
        'slug' => 'Indicato come :slug, che resta invariato se cambia il titolo — così un link già inviato da un agente continua a funzionare.',
        'publish' => 'Pubblica',
        'unpublish' => 'Ritira la pubblicazione',
        'edit_heading' => 'La risposta',
        'save' => 'Salva articolo',
        'preview_heading' => 'Ciò che vede un visitatore',
        'preview_lede' => 'Costruito con gli stessi blocchi che costruisce il widget: questo è l\'articolo, non un\'impressione di esso.',
        'delete_heading' => 'Elimina',
        'delete_lede' => 'Rimuove del tutto l\'articolo. Ritirare la pubblicazione è l\'opzione reversibile.',
        'delete' => 'Elimina questo articolo',
    ],
];
