<?php

declare(strict_types=1);

/*
 * The parts of the translation policy a program can check.
 *
 * Prose lives in `docs/product/translation-policy.md`; this file is the same
 * policy in the form the pipeline consumes and a test asserts. Neither is the
 * summary of the other -- a rule that cannot be expressed here (register, the
 * agent-reads/agent-sends split) stays there, and a table that would be unusable
 * as prose stays here.
 *
 * Nothing reads this yet. It is written first on purpose: the term table is the
 * artifact with the most leverage in the whole effort -- thirty-odd decisions
 * that eight hundred strings inherit -- and it is the one a reviewer who reads
 * the language without speaking it can actually check.
 */
return [

    /*
     * Substituted at send time and restored afterwards, never translated.
     *
     * `:elapsed` and `:shown` are the reason this is mechanical rather than a
     * note in a brief: both have plausible readings as words, so an engine that
     * is merely ASKED to preserve them sometimes will not.
     */
    'protect' => [
        'laravel_placeholder' => '/:[a-z][a-z_]*/',
        'widget_token' => '/\{[a-z][a-zA-Z]*\}/',
        'plural_range' => '/(?:\{\d+\}|\[\d+,(?:\d+|\*)\])/',
    ],

    /*
     * Literal strings that pass through untouched wherever they appear.
     */
    'never_translate' => [
        'Wayfindr',
        'WF-ABC123',
        'Ticket #123',
        'RTT',
        'URL',
        'ID',
    ],

    /*
     * Identical in a GIVEN language on purpose.
     *
     * Per-locale, because cognate-ness is. A global list looked right while
     * German was the only language and shipped English into Italian the moment
     * there was a second: `Agent` and `Name` were skipped as "identical in
     * every catalogue" when Italian wants `Agente` and `Nome`, contradicting
     * this file's own term table three sections down.
     *
     * Ratified by use rather than proposed here, and now enforced: a test
     * fails when a declared cognate is not actually identical in that locale's
     * catalogue, so an entry cannot outlive the thing it excuses -- and
     * `Filter`, `Support` and `Widget` are gone, because no English value ever
     * matched them and the claim was never true.
     */
    'cognates' => [
        // Complete rather than illustrative: a test fails on any value that is
        // identical to its English source and not listed here, so this is the
        // whole record of what each language leaves alone and why.
        'de' => [
            'Agent',
            'Cobrowse',
            'Name',
            'Live',

            // Words German genuinely shares, several of them the same
            // loanwords the render guard already excuses on its own list.
            'Ticket',
            'Tickets',
            'Status',
            'Label',
            'Labels',
            'Normal',
            'System',
            ':count ms',
            'Status: :value',
            'Label: :value',
        ],

        // `Agent` and `Name` are deliberately ABSENT: Italian says `Agente`
        // and `Nome`, and leaving them here is what shipped English into the
        // Italian catalogue in the first place.
        'it' => [
            'Cobrowse',
            'Live',

            // Loanwords Italian tech writing uses as-is.
            'Ticket',
            'Email',
            'Account',
            'Admin',
            'Bug',
            'Payload',
            'Viewport',

            // The DOM/keyboard sense, which Italian borrows. `Messa a
            // fuoco` is the OPTICAL sense and would be wrong here -- this
            // label sits in the telemetry grid beside `Viewport` and
            // reports which element the visitor's page had focused.
            'Focus',
            'Prerendering',
            'Max RTT',
            ':count ms',

            // A coined product term rather than a loanword. Italian has no
            // natural equivalent for the cobrowse timeline sense, so it is
            // left as the product's own word -- worth a second opinion if
            // anyone reads it and disagrees.
            'Guardrail',
        ],
    ],

    /*
     * Pairs English keeps apart that a translation must not merge.
     *
     * Machine-checkable: the two sides must not resolve to the same term.
     */
    'collisions' => [
        ['site', 'page'],
        ['alert', 'message'],
        ['agent', 'operator'],
        ['open_state', 'open_action'],
        ['presence', 'status'],
    ],

    /*
     * Terms that are one English word and two target words.
     *
     * Each pair names a sense split the glossary already decides. Their point
     * here is cross-checking: two languages may disagree about the WORD and
     * must agree about which SENSE a key is. German rendered
     * `tickets.statuses.open` as the state and Italian as the imperative, and
     * nothing noticed -- the collision test proves the two terms differ, never
     * that a catalogue chose between them correctly.
     *
     * A pair belongs here when both sides are declared for every language that
     * has a term table, since the check compares like with like.
     */
    'senses' => [
        ['open_state', 'open_action'],
        ['attention_phrase', 'attention_badge'],
        ['owner_role', 'owner_assignee'],
        ['reply_noun', 'reply_action'],
    ],

    /*
     * English term -> target term.
     *
     * `confirm` marks the entries where the proposal is a judgement call rather
     * than the obvious word, and is the review queue: everything without it is
     * conventional, everything with it wants a second opinion from someone who
     * reads the language.
     *
     * `note` explains the entries where the reason is not self-evident, which is
     * mostly the ones where the obvious word is wrong.
     */
    'terms' => [

        'it' => [

            // --- the domain -------------------------------------------------
            'visitor_singular' => ['term' => 'visitatore', 'note' => 'Generic masculine, and Italian offers no alternative at all -- not even the neutral plural German gets from a participle. Policy section 8 reasoning applies with less to weigh: there is no second option to reject.'],
            'visitor_plural' => ['term' => 'visitatori', 'note' => 'Generic masculine plural. NOT a split by number the way German is -- Italian has one word and this table records that rather than inventing a distinction.'],
            'visitor_compound' => ['term' => 'ID visitatore', 'note' => 'Not a compound at all. German welds `Besucher-ID`; Italian postmodifies -- `ID visitatore`, `pagina del visitatore`, `widget del visitatore`. The entry exists so the shape is decided once instead of per string.'],
            'visit_abstract' => ['term' => 'visita', 'note' => 'Where a sentence can name the visit rather than the person.'],
            'conversation' => ['term' => 'conversazione', 'note' => 'The cognate is right here, unlike German, where `Konversation` was rejected as stilted. Same word, opposite ruling, for a reason that belongs to each language.'],
            'ticket' => ['term' => 'ticket', 'note' => 'Loanword, standard in Italian support tooling.'],
            'message' => ['term' => 'messaggio'],
            'reply_noun' => ['term' => 'risposta'],
            'reply_action' => ['term' => 'Rispondi', 'note' => 'Bare imperative -- see the register note on action labels.'],
            'site' => ['term' => 'sito', 'note' => 'Held apart from `pagina` by the collision pair. Italian makes this easy where German did not.'],
            'page' => ['term' => 'pagina'],
            'queue' => ['term' => 'coda'],
            'agent' => ['term' => 'agente', 'note' => 'The sharpest problem in this table. Italian helpdesk software overwhelmingly calls this person `operatore`, so the obvious word is spoken for by the OTHER role. `agente` is chosen to keep them apart and is slightly less idiomatic for it.'],
            'operator' => ['term' => 'gestore', 'note' => 'Runs the install. NOT `operatore` -- see `agent` above; in Italian support vocabulary that word means the person answering tickets, so using it here would swap the two roles for every Italian reader. `amministratore` is unavailable too, because `Admin` is already a distinct role in the catalogue.'],
            'account' => ['term' => 'account', 'note' => 'Loanword, standard. `conto` is a bank account and `profilo` is the settings page.'],
            'owner_role' => ['term' => 'titolare', 'note' => 'The account role.'],
            'owner_assignee' => ['term' => 'responsabile', 'note' => 'Whoever is responsible for a conversation or a ticket. The same split by SENSE that German makes with Inhaber/Zuständig.'],
            'email' => ['term' => 'email', 'note' => 'Invariable loanword. `indirizzo email` where the sentence needs the address specifically.'],
            'support_code' => ['term' => 'codice di supporto', 'note' => 'Against the compressed `codice supporto`, which reads more like a product name than a thing you can quote to someone.'],
            'request_action' => ['term' => 'Richiedi', 'note' => 'The verb: an agent asks the visitor widget for a snapshot or a session.'],
            'request_object' => ['term' => 'richiesta', 'note' => 'The thing that verb produces.'],
            'request_inquiry' => ['term' => 'richiesta', 'note' => 'Italian does NOT separate this the way German separates Anforderung from Anfrage -- both senses are `richiesta`. `domanda` is a question rather than a request and would be worse. Recorded as a deliberate merge so the next reader knows it was considered, not missed.'],
            'requester' => ['term' => 'richiedente', 'note' => 'The person who raised a ticket.'],
            'alert' => ['term' => 'avviso', 'note' => 'Held apart from `messaggio`.'],
            'digest' => ['term' => 'riepilogo'],
            'summary' => ['term' => 'sintesi', 'note' => 'Split from `digest` on purpose, the same collision German has between Zusammenfassung and a ticket summary. Italian has two words where German reaches for one.'],
            'snapshot' => ['term' => 'snapshot', 'note' => 'Loanword, as in German. `istantanea` is correct Italian and reads as photography.'],
            'lane' => ['term' => 'corsia', 'note' => 'The swimlane sense, and the direct counterpart of the German `Spur`. Italian diagramming says `corsie`.'],
            'presence' => ['term' => 'presenza', 'note' => 'No compound needed. German had to build `Präsenzstatus` because bare `Status` was taken; Italian `presenza` and `stato` are already different words, so the collision that forced the German compound does not exist here.'],
            'status' => ['term' => 'stato'],
            'attention_phrase' => ['term' => 'Richiede attenzione', 'note' => 'For sentences and full status strings.'],
            'attention_badge' => ['term' => 'Da gestire', 'note' => 'For badges, chips and column headers. Against `Azione richiesta`, which is accurate and puts a second `richiesta` on screen beside the request vocabulary.'],
            // --- states -----------------------------------------------------
            'open_state' => ['term' => 'Aperto', 'note' => 'The adjective.'],
            'open_action' => ['term' => 'Apri', 'note' => 'The imperative. Italian keeps these apart naturally, where English spells them the same.'],
            'closed' => ['term' => 'Chiuso'],
            'active' => ['term' => 'Attivo'],
            'pending' => ['term' => 'In attesa'],
            'assigned' => ['term' => 'Assegnato'],
            'unassigned' => ['term' => 'Non assegnato'],
            'read' => ['term' => 'Letto', 'note' => 'Past participle. The catalogue entry is a filter, not a command.'],
            'seen' => ['term' => 'Visto'],
            'ready' => ['term' => 'Pronto'],
            'ended' => ['term' => 'Terminato'],
            'quiet' => ['term' => 'Poco attivo', 'note' => 'A degree on the recency scale, not a binary. `presence.php` documents itself as how recently a visitor was SEEN -- Active recently, Recently active, Quiet, Not reported -- so this is presence recency rather than channel volume, which rules out `Silenzioso` and `Calmo`. `Inattivo` was rejected for collapsing the scale into the Attivo/Inattivo binary, and `Assente` for overlapping `Not reported`, which is already the rung below. German needs no equivalent adjustment: `Ruhig` sits outside the activity axis where `Inattivo` sits on it.'],
            'fresh' => ['term' => 'Nuovo', 'note' => 'The three-step freshness scale, reviewed together or not at all: Nuovo -> Vecchio -> Obsoleto.'],
            'aging' => ['term' => 'Vecchio'],
            'stale' => ['term' => 'Obsoleto'],
            // --- actions ----------------------------------------------------
            //
            // Bare second-person-singular imperative, always. A control commands
            // the machine rather than addressing the reader, so `Lei` never
            // appears here. See the register entry.
            'search' => ['term' => 'Cerca'],
            'copy' => ['term' => 'Copia'],
            'refresh' => ['term' => 'Aggiorna'],
            'retry' => ['term' => 'Riprova'],
            'close_action' => ['term' => 'Chiudi'],
            'sign_out' => ['term' => 'Esci'],
            'send_message' => ['term' => 'Invia messaggio', 'note' => 'The worked example: not `Invii un messaggio`, and not `Inviare un messaggio`.'],
        ],

        'de' => [

            // --- the domain -------------------------------------------------

            'visitor_plural' => ['term' => 'Besuchende', 'note' => 'Plural only, and the plural is the whole of it: five strings. An adjectival noun, neutral and screen-reader clean in "die Besuchenden".'],
            'visitor_singular' => ['term' => 'Besucher', 'note' => 'Every singular, sentence and label alike. NOT a fallback for clunkiness -- German has no neutral singular of the participle ("der/die Besuchende" carries gender in the article), and nineteen bare labels are forced to this word anyway, so a different word in sentences would put two names for one referent on one screen. Policy section 8 has the count.'],
            'visitor_compound' => ['term' => 'Besucher-', 'note' => 'Forty-seven of the ninety occurrences, where the word is a modifier rather than a noun: Besucher-ID, Besucherprofil, Besucherseite, Besucher-Widget. Not a decision -- "Besuchendenprofil" is not written.'],
            'visit_abstract' => ['term' => 'Besuch', 'note' => 'Where the sentence can name the visit rather than the person, it should. "Zugriff" serves the same purpose for access-shaped phrasing.'],
            'conversation' => ['term' => 'Unterhaltung', 'note' => '"Konversation" is a cognate and reads stilted; "Chat" belongs to the widget and would blur the two surfaces.'],
            'ticket' => ['term' => 'Ticket'],
            'message' => ['term' => 'Nachricht'],
            'reply_noun' => ['term' => 'Antwort'],
            'reply_action' => ['term' => 'Antworten'],
            'site' => ['term' => 'Website', 'note' => 'Never "Seite". German "Seite" is a PAGE, and the sites list exists to keep the two apart.'],
            'page' => ['term' => 'Seite'],
            'queue' => ['term' => 'Warteschlange'],
            'agent' => ['term' => 'Agent', 'note' => 'Cognate. Answers conversations -- not the operator.'],
            'operator' => ['term' => 'Betreiber', 'note' => 'Runs the install. Break-glass and operator settings depend on this not collapsing into "Agent".'],
            'account' => ['term' => 'Konto'],
            'owner_role' => ['term' => 'Inhaber', 'note' => 'The account role -- profile.roles.owner. "Besitzer" reads more like property than responsibility.'],
            'owner_assignee' => ['term' => 'Zuständig', 'note' => 'Whoever is responsible for a work item: a conversation, a ticket. Takes "zuständige Person" where the sentence needs a noun. A split by SENSE rather than by density, and the one case where the audit found the shipped German already correct and this table wrong -- ratified by use, not proposed.'],
            'email' => ['term' => 'E-Mail', 'note' => 'Hyphen and capital M. "Email" is a different German word.'],
            'support_code' => ['term' => 'Support-Code'],

            // English says `request` for four different things. German has the
            // words to keep them apart, and the catalogue drifted because
            // nothing said it had to.
            'request_action' => ['term' => 'anfordern', 'note' => 'The verb: an agent asks the visitor widget for a snapshot or a cobrowse session. Never "anfragen", which is to enquire rather than to requisition.'],
            'request_object' => ['term' => 'Anforderung', 'note' => 'The noun that follows the verb. If you "anfordern", the thing is an "Anforderung" -- pairing "anfordern" with "Anfrage" is the mismatch that produced the drift.'],
            'request_inquiry' => ['term' => 'Anfrage', 'note' => 'What a PERSON asks: the visitor\'s current request, an operational request, a standard support request. Deliberately not swept -- this is the sense "Anfrage" is right for.'],
            'requester' => ['term' => 'Anfragender', 'note' => 'The person who raised a ticket. A noun for a human, not for a message.'],
            'alert' => ['term' => 'Benachrichtigung', 'note' => 'Against "Warnung", which is louder than most of these are. Must stay distinct from "Nachricht".'],
            'digest' => ['term' => 'Zusammenfassung', 'note' => '"Digest" reads as un-localised English in a B2B interface. Deliberately NOT the time-bound compounds -- there is no daily or weekly variant in the catalogue, because a digest fires when the scheduler runs rather than on a calendar, so "Tageszusammenfassung" would assert a cadence the product does not have. Length watch: profile.readiness_cards.cadence_digest is the bare word "Digest" on a card, and this is nine characters longer.'],
            'summary' => ['term' => 'Ticket-Übersicht', 'confirm' => true, 'note' => 'Only one occurrence -- tickets.row.preview_summary, "Ticket summary". Flagged because the obvious German is "Ticket-Zusammenfassung", which merges it with digest. Weaker than the collision pairs above: the two never share a surface. Wave it through if the merge does not bother you.'],
            'snapshot' => ['term' => 'Snapshot', 'note' => 'Fifty-six occurrences, all cobrowse-technical. "Momentaufnahme" is correct German and may read as over-translation in a developer-facing panel.'],
            'lane' => ['term' => 'Spur', 'note' => 'Standard UI and diagramming vocabulary rather than the automotive reading -- swimlanes are Schwimmspuren. "Pfad" would be right if these were logical routing channels; they are visual grouping bands, so they are not.'],
            'presence' => ['term' => 'Präsenzstatus', 'note' => 'Bare "Status" is the better German and is unavailable: conversations.php:232-233 puts a Status column and a Presence column side by side, and tickets.php names a Status field. The compound is the price of keeping two columns on one screen from sharing a name.'],
            'status' => ['term' => 'Status', 'note' => 'The general entity state -- a ticket\'s, a conversation\'s. Held apart from presence by the collision pair above.'],
            'attention_phrase' => ['term' => 'Erfordert Aufmerksamkeit', 'note' => 'For full status strings and sentences. "Erfordert" carries active demand; "Benötigt" reads passive, as though a system wanted resources rather than a person wanted telling. Covers the empty states, the trans_choice counts, and the profile alert guidance.'],
            'attention_badge' => ['term' => 'Handlungsbedarf', 'note' => 'For badges, chips and column headers, where the full phrase does not fit and German software would not use it anyway. Covers conversations.columns.attention, detail.tones.attention, the new_activity lane, the cobrowse_attention filter, and tickets.filters.external.failed.'],

            // --- states -----------------------------------------------------

            'open_state' => ['term' => 'Offen', 'note' => 'The adjective. Three catalogue entries read \'open\' => \'Open\' and all three are states.'],
            'open_action' => ['term' => 'Öffnen', 'note' => 'The verb. English spells these the same; German does not.'],
            'closed' => ['term' => 'Geschlossen'],
            'active' => ['term' => 'Aktiv'],
            'pending' => ['term' => 'Ausstehend'],
            'assigned' => ['term' => 'Zugewiesen'],
            'unassigned' => ['term' => 'Nicht zugewiesen'],
            'read' => ['term' => 'Gelesen', 'note' => 'Past participle. The catalogue entry is a filter, not a command.'],
            'seen' => ['term' => 'Gesehen'],
            'ready' => ['term' => 'Bereit'],
            'ended' => ['term' => 'Beendet'],
            'quiet' => ['term' => 'Ruhig', 'note' => 'Reduced activity, not a binary connection state -- presence.php documents the scale as how recently a visitor was seen. "Inaktiv" would be the word if it were binary.'],

            // The cobrowse freshness scale. Reviewed together or not at all --
            // three steps that have to read as one progression.
            'fresh' => ['term' => 'Neu', 'note' => 'Never "Frisch", which is food. Watch this one in the rendered queue: the conversation lanes carry "Neue Aktivität" and a cobrowse freshness badge can sit on the same row, so two unrelated things are both "new". Different concepts, adjacent pixels.'],
            'aging' => ['term' => 'Alt'],
            'stale' => ['term' => 'Veraltet'],

            // --- actions ----------------------------------------------------
            //
            // Infinitive, always. A control names an action rather than
            // addressing anyone, so "Sie" never appears here. See policy §1.

            'search' => ['term' => 'Suchen'],
            'copy' => ['term' => 'Kopieren'],
            'refresh' => ['term' => 'Aktualisieren'],
            'retry' => ['term' => 'Erneut versuchen'],
            'close_action' => ['term' => 'Schließen'],
            'sign_out' => ['term' => 'Abmelden'],
            'send_message' => ['term' => 'Nachricht senden', 'note' => 'The worked example: not "Senden Sie eine Nachricht".'],
        ],
    ],

    /*
     * Terms a draft must not contain, and what was decided instead.
     *
     * Every entry here was ruled on and then observed coming back from a real
     * engine anyway -- `Konversation` twenty-one times, `Standort` eight,
     * `Momentaufnahme` six, in one run against Murf. An engine with nowhere to
     * receive a glossary cannot be blamed for that, which is exactly why the
     * check belongs on the way back in rather than in a request on the way out.
     *
     * Entries must be UNAMBIGUOUS. `Frisch` is rejected as a translation of
     * `fresh` and is a perfectly good word elsewhere; `älter` is wrong in a
     * badge and right in `älter als 5 Minuten`, which the catalogue says today.
     * Neither is listed, because a scorer that cries wolf is one nobody reads.
     */
    'rejected' => [
        // Italian starts empty, and that is the discipline rather than an
        // oversight. Every German entry here was ruled on AND then observed
        // coming back from a real engine; seeding a list from what an engine
        // might do produces entries nobody measured, which is how a scorer
        // starts crying wolf. It gets populated from the first scored run.
        // Filled from the first scored Italian run, which is the process this
        // list is supposed to follow: every entry was observed coming back from
        // a real engine rather than predicted. `inviare` is deliberately absent
        // -- the same run returned `File da inviare` and `a inviare uno
        // snapshot`, both correct, and an infinitive after a preposition is not
        // the same mistake as an infinitive on a button.
        'it' => [
            'bigliett' => 'ticket is `ticket`, invariable in the plural -- `biglietto` is a travel or cinema ticket',
            'istantanea' => 'snapshot is snapshot',
            'operatore' => 'agent is agente and operator is gestore -- in Italian support vocabulary this word means the agent, so it swaps the two roles',
            'proprietario' => 'owner_role is titolare, owner_assignee is responsabile',
        ],

        'de' => [
            'Konversation' => 'conversation is Unterhaltung',
            'Schnappschuss' => 'snapshot is Snapshot',
            'Momentaufnahme' => 'snapshot is Snapshot',
            'Standort' => 'site is Website -- Standort is a physical location',
            'Besucher:innen' => 'the colon form is rejected on accessibility grounds (policy section 8)',
            'angefragt' => 'request_action is angefordert',
            // `anfragen` is NOT listed, and the reason is the point of the rule
            // above: `Anfragender` -- the decided term for a ticket's requester
            // -- begins with those exact letters, so the entry flagged three
            // correct strings the first time it ran. German compounds make
            // substring matching load-bearing (`Konversation` must be caught
            // inside `Konversationswarteschlange`), which means word boundaries
            // cannot rescue it and the entry simply does not belong.

        ],
    ],

    /*
     * Patterns a draft is measured against, as data rather than as prose.
     *
     * The same shape as `protect` above, and the same reasoning: a rule stated
     * in a paragraph is a rule an engine ignores and a reviewer forgets.
     */
    'checks' => [
        // Italian has NO straight-quote check, and the omission is the point:
        // `dell'agente` and `un'attività` are correct Italian, so the German
        // rule would flag most of the catalogue. A check is per-locale because
        // the mistakes are.
        'it' => [
            // Pronouns alone are a weak net for Italian. `te` was missing and
            // let `assegnati a te` through; more usefully, the second-person
            // singular of the common auxiliaries and modals is unambiguous --
            // `stai`, `puoi`, `devi` cannot be anything else (`vai` and `fai` are excluded: those ARE legitimate imperative labels) -- and catches
            // informal prose that contains no pronoun at all, which is where
            // `Segna come in sospeso se stai aspettando` was hiding.
            // Pronouns, 2sg auxiliaries and modals, and the 2sg FUTURE.
            //
            // The future was the gap. `Riceverai avvisi` is squarely informal
            // and carries no pronoun, no auxiliary and no modal, so a
            // pronoun-and-modal net scored it clean -- and review found it on
            // a shipped string. Italian's 2sg future ends in `-rai` almost
            // uniquely, which makes it cheap to catch: measured at zero false
            // positives across the whole catalogue.
            'informal address' => '/\\b(tu|ti|te|tuo|tuoi|tua|tue|stai|sei|hai|puoi|devi|vuoi|sai)\\b|\\b\\w{3,}rai\\b/ui',

            // The two checks above look at PRONOUNS and VERB ENDINGS. Neither
            // sees the register mistake Italian actually makes, because the
            // informal imperative of an `-are` verb is spelled exactly like the
            // ordinary third-person indicative: `continua` is informal in
            // `o continua tramite chat` and formal in `la modalita' continua a
            // sopprimere`. The word alone cannot decide.
            //
            // What decides is POSITION. A shipped mistake was always a verb
            // coordinated onto a formal instruction (`Richieda ... o continua`,
            // `Crei o allega`, `Provi ... oppure cancella`) or one opening a
            // prose sentence (`Inserisci una risposta`). And the check applies
            // only to PROSE: `Cancella filtri` and `Apri ticket` are button
            // labels, where the bare imperative is exactly right, so the
            // lookahead requires sentence punctuation before anything fires.
            //
            // The verb list is the part that can go stale, and did: a first
            // pass listed only the verbs whose defects had already been found,
            // so it scored the catalogue clean while `Assegna questo ticket`
            // and `Consulta l'attivita'` sat in it. The list below was derived
            // instead from the CONTROL LABELS -- short, unpunctuated strings,
            // which is precisely where this product's imperative vocabulary
            // lives -- and then hand-filtered to verbs. Extend it the same way
            // rather than one defect at a time.
            //
            // It stays a net, not a proof: a verb the product has never used
            // on a button is not in it.
            'informal imperative in prose' => '/(?=.*[.!?])(?:\\b(?:o|oppure|e|ed)\\s+|(?:^|[.!?]\\s+))(aggiorna|allega|annulla|apri|applica|assegna|attendi|cambia|cancella|carica|cerca|chiudi|collega|conferma|consulta|continua|controlla|copia|crea|disconnetti|elimina|gestisci|imposta|includi|inserisci|invia|libera|mantieni|metti|modifica|mostra|prova|riapri|richiedi|rilascia|rimuovi|riprova|rispondi|rivedi|rivendica|salva|scegli|scorri|scrivi|segna|seleziona|termina|torna|trova|usa|verifica)\\b/uis',

            // The NEGATIVE informal imperative is the one shape Italian spells
            // unambiguously: `non` followed by a bare infinitive. Formal is
            // `non` plus the subjunctive, so `Non raccogliere` and `Non
            // includere` are informal no matter what surrounds them. The
            // `{4,}` keeps short adverbs that merely end in `-re` (`sempre`,
            // `oltre`) from matching. Zero false positives measured.
            'negative informal imperative' => '/\\bnon\\s+\\w{4,}re\\b/ui',

            // Straight DOUBLE quotes only. Italian typography declares
            // caporali, and unlike German this cannot also flag the apostrophe
            // in `dell'agente`, which is correct and everywhere.
            'straight double quote' => '/"/u',
        ],

        // What the informal-address pattern CANNOT see, stated rather than
        // papered over: a du-imperative carries no marker word. A real run
        // returned `Sende eine klare Antwort und setze das Ticket ...`, which is
        // squarely informal and contains no `du`, `dich`, `dir` or `dein`.
        // Detecting a bare German verb stem by regex means flagging half the
        // catalogue, so the check stays narrow and the gap is written down --
        // register in prose is a thing the reviewer reads for, per policy
        // section 9.
        'de' => [
            // Both halves, because they answer different failures.
            //
            // The 2sg VERB FORMS catch informal prose carrying no pronoun at
            // all -- Italian hid `se stai aspettando` from a pronoun-only net
            // for a whole draft. German is clean on them today and they are
            // here so it stays that way.
            //
            // The `i` FLAG catches the same pronouns capitalised, which is
            // where an engine actually puts them: `Er sieht die Seite nicht.`
            // and `Du bist angemeldet.` both scored clean without it.
            'informal address' => '/\b(du|dich|dir|dein[a-z]*|bist|hast|kannst|musst|willst|weißt|wirst)\b/ui',
            'generic masculine pronoun' => '/\b(er|ihn|ihm|seine|seinem|seinen|seiner|seines)\b/ui',
            'straight quote' => '/["\']/u',
            'unhonoured escape' => '/\\\\/u',
        ],
    ],

    /*
     * Register, in the form an engine can be handed.
     *
     * Prose reasoning is policy section 1. What matters here is the second
     * line: an engine told only "use the formal address" writes `Senden Sie eine
     * Nachricht` on a button, and 195 of 841 strings are buttons.
     */
    'register' => [
        'de' => [
            'address' => 'Formal (Sie/Ihnen/Ihr). Never du.',
            'action labels' => 'Infinitive, and no address at all: "Nachricht senden", never "Senden Sie eine Nachricht". A control names an action; it does not speak to anyone.',
            'gendered nouns' => 'Plural takes the participial form (Besuchende). Singular keeps the conventional noun (Besucher) -- German has no neutral singular. Never the colon form (Besucher:innen).',
            'pronouns' => 'Never the generic masculine. Reword to avoid the pronoun rather than choosing one.',
        ],
        'it' => [
            'address' => 'Formal (Lei) in prose. Never tu.',
            'action labels' => 'Bare second-person-singular imperative: "Salva", "Invia", "Cerca". NOT the Lei imperative ("Salvi", "Invii"), which reads wrong on a control, and NOT the infinitive ("Salvare"), which is the French convention. Same principle as German -- a control commands the machine rather than addressing the reader -- reached with a different form.',
            'descriptions' => 'Third person for tooltips and action explanations: "Apre un file", "Risponde a questo messaggio".',
            'gendered nouns' => 'Generic masculine. Italian has no neutral at all -- not even the neutral plural German gets from a participle -- so visitatore/visitatori throughout, and no split by number.',
        ],
    ],

    /*
     * Typographic rules the catalogue guards already enforce for German, kept
     * here so a new language states its own rather than inheriting these.
     */
    'typography' => [
        'it' => [
            'quotes' => ['open' => '«', 'close' => '»'],
            'no_backslash' => true,
        ],

        'de' => [
            'quotes' => ['open' => '„', 'close' => '“'],
            'no_backslash' => true,
        ],
    ],
];
