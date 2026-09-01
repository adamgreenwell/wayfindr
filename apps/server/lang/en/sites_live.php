<?php

/*
 * The live-visitors board for one site.
 *
 * Unlike the account pages extracted before it, most of this page's copy is
 * rendered by JAVASCRIPT rather than by Blade: the board rewrites itself as a
 * socket delivers presence. The script is Blade-rendered, so those strings come
 * from here through `@json(__(...))`, the pattern the reply composer already
 * uses -- no second mechanism, and the words are still chosen per request, in
 * the language of the agent who asked for the page.
 *
 * The presence states are NOT here. They live in `presence.php`, which is
 * shared vocabulary rather than one page's copy -- the conversation queue names
 * them in a filter and on every row, and that catalogue's own docblock
 * anticipated this page naming them again. Two copies would drift the first
 * time a translator improved one of them.
 *
 * The presence LABELS are handed to the script by the controller rather than
 * looked up in it, and that is deliberate and pre-existing: one socket message
 * reaches every agent watching a site and they do not all read the same
 * language, so the payload carries a state and the page picks the sentence.
 *
 * ONE COPY PROBLEM PRESERVED, not fixed here. A duration is rendered verbose by
 * the server on load (`3 minutes`, from `diffForHumans`) and abbreviated by the
 * script from the first tick onward (`3m`). Both are translated now; they are
 * still two formats for one column, and which one a reader sees depends on how
 * long they have had the page open. Making them agree changes what the page
 * says, so it is a decision rather than a side effect of this change.
 */

return [
    'document_title' => 'Live visitors',
    // The site's name is the account's, so the view marks that fragment and
    // this string keeps the word order.
    'heading' => 'Live visitors: :site',
    'subtitle' => 'Who is on this site right now, including people who have not got in touch.',

    'board' => [
        'heading' => 'On the site now',
        'column_visitor' => 'Visitor',
        'column_page' => 'Page',
        'column_duration' => 'On site for',
        'column_presence' => 'Presence',
        'empty' => 'Nobody is on the site right now.',
        // No link and no invented name: there is nothing on the other side yet,
        // and a name we were never told is not one to make up.
        'stranger' => 'Not in touch yet',
        'no_page' => 'Not reported',
        'unnamed' => 'Visitor :id',
        'conversations' => '{1} :count conversation|[2,*] :count conversations',
        'note' => '{1} Somebody appears here while their browser reports in, and drops off :count minute after it stops. Visitors are told in the widget and can decline.|[2,*] Somebody appears here while their browser reports in, and drops off :count minutes after it stops. Visitors are told in the widget and can decline.',
    ],

    'disabled' => [
        // Not an empty board. An operator looking at nothing deserves to know
        // whether nobody is here or nothing is being recorded.
        'body' => 'This site does not record visitors who have not made contact, so this board stays empty by design.',
        // One sentence with the link as a parameter, so a language that puts
        // the clause first can still do so.
        'turn_on' => ':link to see people browsing before they get in touch.',
        'turn_on_link' => 'Turn on live visitor presence',
        'ask_admin' => 'Account owners and admins decide whether this site watches visitors who have not made contact.',
    ],

    'status' => [
        'live' => 'Updating live.',
        'no_realtime' => 'This install does not run realtime updates, so this list is correct as of when the page loaded.',
        'unavailable' => 'Live updates are unavailable, so this list is correct as of when the page loaded.',
        'presence_off' => 'Live visitor presence is off for this site.',
        'reconnecting' => 'Reconnecting to live updates.',
        'no_access' => 'You no longer have access to this site.',
    ],

    // Abbreviations, because the column is scanned rather than read. An em dash
    // where the browser never reported a start.
    'duration' => [
        'seconds' => ':count s',
        'minutes' => ':count m',
        'hours' => ':count h :minutes m',
        'unknown' => '—',
    ],
];
