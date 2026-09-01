<?php

/*
 * Drafted from lang/en/reply_templates.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue it --catalogue=reply_templates --score`. Every
 * value here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * `modello di risposta` is the account-managed template this page edits;
 * `assistente di risposta` in conversations.php is the built-in composer
 * helper. The empty state names both in one sentence, which is why they need
 * separate words.
 *
 * Register: `Lei` in prose, and the bare imperative on controls ("Crea",
 * "Salva", "Archivia") -- not the Lei imperative, which reads wrong on a
 * button.
 *
 * No plural strings in this catalogue.
 */

return [
    'title' => 'Modelli di risposta',
    'subtitle' => 'Gestione dei modelli di risposta condivisi per gli aggiornamenti più comuni ai visitatori.',
    'back' => 'Torna al conto',

    'flash' => [
        'created' => 'Modello di risposta creato.',
        'updated' => 'Modello di risposta aggiornato.',
        'archived' => 'Modello di risposta archiviato.',
    ],

    'standards' => [
        'heading' => 'Criteri per i modelli',
        'lede' => 'Riutilizzabili, sicuri e comunque umani.',
        'calm' => 'Consideri i modelli come punti di partenza pacati, non come copioni che gli agenti devono inviare senza modifiche.',
        'use_for' => 'Usi i modelli per conferme di ricezione, aggiornamenti di stato, passi successivi e richieste di chiarimento ricorrenti.',
        'keep_out' => 'Mantenga i modelli visibili ai visitatori liberi da password, dati di pagamento, note interne di passaggio e promesse che il suo team non può mantenere.',
    ],

    'create' => [
        'heading' => 'Crea modello',
        'lede' => 'Breve, riutilizzabile e ancora modificabile prima dell\'invio.',
        'name' => 'Nome del modello',
        'name_placeholder' => 'Verifica sulla fatturazione',
        'body' => 'Testo della risposta',
        'body_placeholder' => 'Grazie per l\'aggiornamento. Sto verificando e le farò sapere a breve.',
        'submit' => 'Crea modello',
    ],

    'list' => [
        'heading' => 'Modelli',
        'total' => ':count in totale',
        'column_template' => 'Modello',
        'column_body' => 'Testo',
        'column_status' => 'Stato',
        'column_manage' => 'Gestisci',
        'active' => 'Attivo',
        'archived' => 'Archiviato',
    ],

    'empty' => [
        'heading' => 'Nessun modello di risposta gestito.',
        'body' => 'Gli assistenti di risposta integrati restano disponibili nei campi di risposta finché il suo team non aggiunge modelli propri. Ne aggiunga uno quando gli agenti continuano a riscrivere la stessa risposta pacata e utile.',
        'action' => 'Crea il primo modello',
    ],

    'manage' => [
        'name' => 'Nome',
        'body' => 'Testo',
        'save' => 'Salva modello',
        'archive' => 'Archivia',
        'archived_note' => 'I modelli archiviati non compaiono tra gli assistenti di risposta.',
    ],

    'validation' => [
        'name' => 'Assegni un nome a questo modello di risposta.',
        'body' => 'Aggiunga un testo della risposta.',
    ],
];
