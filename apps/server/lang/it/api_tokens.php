<?php

/*
 * Drafted from lang/en/api_tokens.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue it --catalogue=api_tokens --score`. Every value
 * here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * Terms this catalogue introduced, both now in the glossary: `token API` (the
 * qualifier follows the noun, and the loanword is invariable -- `i token`,
 * never `i tokens`) and `autorizzazione` for an ability, against `capacità`,
 * which is a personal capability rather than a permission a credential holds.
 *
 * `list.total` has two branches that are byte-identical, and that is correct
 * rather than an unfinished translation: `token` does not pluralise and the
 * string carries no article to agree with the count. It is listed as such in
 * the pipeline's invariable-plural exceptions, with that reason.
 *
 * Action labels are bare imperative ("Emetti token"), per the register rule.
 * The prose uses `Lei`.
 */

return [
    'title' => 'Token API',
    'subtitle' => 'Accesso programmatico ai dati di supporto di questo account, per integrazioni sviluppate da lei o da altri.',
    'back' => 'Torna all’account',

    // Italian DOES inflect it, and this was `:count attivi` flat -- which read
    // `1 attivi` for an admin with one usable token.
    'active' => '{1} :count attivo|[2,*] :count attivi',

    'flash' => [
        'created' => 'Token API creato. Lo copi ora — non potrà essere mostrato di nuovo.',
        'created_limited' => 'Token API creato, limitato ai siti che segue oggi. Lo copi ora — non potrà essere mostrato di nuovo.',
        'revoked' => 'Token API revocato.',
        'already_revoked' => 'Questo token API era già stato revocato.',
    ],

    'issued' => [
        'heading' => 'Lo copi ora',
        'once' => 'Mostrato una sola volta',
        'hashed' => 'Questa è l’unica volta in cui questo token viene mostrato. Wayfindr ne memorizza un hash, non il token stesso, quindi non può essere recuperato — se lo perde, lo revochi e ne emetta un altro.',
        'send_as' => 'Lo invii come :header. Lo tratti come una password: chiunque lo possieda può usare ogni autorizzazione concessa qui sotto.',
    ],

    'list' => [
        'heading' => 'Token',
        'total' => '{1} :count token|[2,*] :count token',
        'empty' => 'Ancora nessun token. Nulla al di fuori di questa dashboard può leggere i dati di supporto di questo account.',
        'column_name' => 'Nome',
        'column_token' => 'Token',
        'column_reaches' => 'Portata',
        'column_last_used' => 'Ultimo utilizzo',
        'column_state' => 'Stato',
        'column_action' => 'Azione',
        'created' => 'Creato :when',
        'created_by' => 'Creato :when da :name',
        'revoke' => 'Revoca',
        'revoking_keeps' => 'La revoca conserva la riga. A cosa serviva il token e quando è stato usato l’ultima volta è la parte che vale la pena conservare dopo che qualcuno lo ha disattivato.',
    ],

    'reaches' => [
        'purged' => 'Nessun sito — ogni sito a cui era limitato è stato eliminato definitivamente',
        'every_site' => 'Ogni sito di questo account',
        'unsupported' => 'siti che lei non segue',
        'no_abilities' => 'Nessuna autorizzazione',
    ],

    'abilities' => [
        'read' => 'Lettura',
        'write' => 'Scrittura',
    ],

    'last_used' => [
        'never' => 'Mai utilizzato',
    ],

    'state' => [
        'revoked' => 'Revocato :when',
        'expired' => 'Scaduto :when',
        'expires' => 'Scade :when',
        'active' => 'Attivo',
    ],

    'create' => [
        'heading' => 'Emetti un token',
        'read_only' => 'Lettura e scrittura sono separate',
        'name_label' => 'A cosa serve',
        'name_placeholder' => 'Sincronizzazione report',
        'name_help' => 'Scritto per chi troverà questa riga fra un anno e dovrà decidere se serve ancora.',
        'abilities_label' => 'Cosa può fare',
        'ability_read' => 'Leggere conversazioni, messaggi, ticket e visitatori',
        'ability_write' => 'Aprire conversazioni, inviare messaggi e creare o modificare lo stato dei ticket',
        'abilities_help' => 'Ogni autorizzazione è indipendente. La scrittura non concede la lettura e la lettura non concede mai la scrittura.',
        'expires_label' => 'Scade dopo',
        'expires_help' => 'Giorni. Se lasciato vuoto il token non scade mai, il che significa che non è più compito di nessuno accorgersene.',
        'sites_label' => 'Limita ai siti',
        'sites_help' => 'Se non ne seleziona nessuno, il token raggiunge ogni sito :today. Un sito creato in seguito non viene aggiunto — ne emetta uno nuovo quando vuole che copra di più. Un’integrazione che osserva un solo sito non dovrebbe essere una credenziale per tutti.',
        'sites_help_today' => 'che segue oggi',
        'submit' => 'Emetti token',
    ],

    'accountability' => 'Dietro un token non c’è una persona, quindi una lettura effettuata con esso non può rispondere a :who ha letto, come invece può fare una lettura dalla dashboard. Le scritture sono attribuite al token, mai alla persona che lo ha emesso. Per questo un token è limitato da ciò che può raggiungere — e per questo una concessione di accesso del gestore non lo amplia mai.',
    'accountability_who' => 'chi',
];
