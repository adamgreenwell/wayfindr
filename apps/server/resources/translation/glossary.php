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
     * Identical in every catalogue on purpose.
     *
     * Ratified by use rather than proposed here: these already appear unchanged
     * in `lang/de`, and the render-comparison test carries them as exclusions
     * with a companion test that fails when one stops being used. Adding to this
     * list is a glossary decision and needs the same review as a term.
     */
    'cognates' => [
        'Agent',
        'Cobrowse',
        'Name',
        'Widget',
        'Support',
        'Live',
        'Filter',
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
            'address' => 'Formal (Lei). Never tu.',
            'action labels' => 'UNDECIDED -- do not infer this from German. It is answered when Italian starts, against shipped Italian software.',
            'gendered nouns' => 'Same wall as German: visitatore/visitatrice has no neutral singular. Expect the German answer to be the right shape.',
        ],
    ],

    /*
     * Typographic rules the catalogue guards already enforce for German, kept
     * here so a new language states its own rather than inheriting these.
     */
    'typography' => [
        'de' => [
            'quotes' => ['open' => '„', 'close' => '“'],
            'no_backslash' => true,
        ],
    ],
];
