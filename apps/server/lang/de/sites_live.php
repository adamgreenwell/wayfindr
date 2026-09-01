<?php

/*
 * Drafted from lang/en/sites_live.php. NOT YET REVIEWED.
 *
 * Written by hand against the glossary in resources/translation/glossary.php
 * and the rules in docs/product/translation-policy.md, then measured with
 * `wayfindr:translate-catalogue de --catalogue=sites_live --score`. Every value
 * here is a proposal.
 *
 * Review order that actually finds things: the glossary terms first, then the
 * short strings against the rendered surface, then register in the prose.
 * Placeholders and plural segments are held by the pipeline and are not worth
 * your attention.
 *
 * `site` is `Website` throughout, never `Seite` -- and on this page that rule
 * earns its keep, because a `Seite` column (the page a visitor is on) sits four
 * columns from the site the board belongs to.
 *
 * `visitor` splits by number, per the glossary: `Besucher` in every singular
 * and bare label, `Besuchende` in the plural. Both appear here.
 *
 * `Präsenzstatus` for the presence column, because bare `Status` is taken --
 * see the glossary note. The STATES themselves come from presence.php.
 *
 * The duration abbreviations are German ones: `Sek.`, `Min.`, `Std.`, with the
 * point, because a bare `h` is not how German writes an hour.
 */

return [
    'document_title' => 'Live-Besuchende',
    'heading' => 'Live-Besuchende: :site',
    'subtitle' => 'Wer gerade auf dieser Website ist, einschließlich Personen, die sich noch nicht gemeldet haben.',

    'board' => [
        'heading' => 'Gerade auf der Website',
        'column_visitor' => 'Besucher',
        'column_page' => 'Seite',
        'column_duration' => 'Auf der Website seit',
        'column_presence' => 'Präsenzstatus',
        'empty' => 'Gerade ist niemand auf der Website.',
        'stranger' => 'Noch kein Kontakt',
        'no_page' => 'Nicht gemeldet',
        'unnamed' => 'Besucher :id',
        'conversations' => '{1} :count Unterhaltung|[2,*] :count Unterhaltungen',
        'note' => '{1} Jemand erscheint hier, solange sein Browser sich meldet, und verschwindet :count Minute, nachdem das aufhört. Besuchende werden im Widget darauf hingewiesen und können ablehnen.|[2,*] Jemand erscheint hier, solange sein Browser sich meldet, und verschwindet :count Minuten, nachdem das aufhört. Besuchende werden im Widget darauf hingewiesen und können ablehnen.',
    ],

    'disabled' => [
        'body' => 'Diese Website zeichnet keine Besuchenden auf, die noch keinen Kontakt aufgenommen haben, deshalb bleibt diese Übersicht absichtlich leer.',
        'turn_on' => ':link, um Personen zu sehen, die sich umsehen, bevor sie sich melden.',
        'turn_on_link' => 'Live-Präsenz von Besuchenden einschalten',
        'ask_admin' => 'Kontoinhaber und Admins entscheiden, ob diese Website Besuchende beobachtet, die noch keinen Kontakt aufgenommen haben.',
    ],

    'status' => [
        'live' => 'Wird live aktualisiert.',
        'no_realtime' => 'Diese Installation führt keine Echtzeit-Aktualisierungen aus, deshalb ist diese Liste auf dem Stand des Seitenaufrufs.',
        'unavailable' => 'Live-Aktualisierungen sind nicht verfügbar, deshalb ist diese Liste auf dem Stand des Seitenaufrufs.',
        'presence_off' => 'Die Live-Präsenz von Besuchenden ist für diese Website ausgeschaltet.',
        'reconnecting' => 'Verbindung zu den Live-Aktualisierungen wird wiederhergestellt.',
        'no_access' => 'Sie haben keinen Zugriff mehr auf diese Website.',
    ],

    'duration' => [
        'seconds' => ':count Sek.',
        'minutes' => ':count Min.',
        'hours' => ':count Std. :minutes Min.',
        'unknown' => '—',
    ],
];
