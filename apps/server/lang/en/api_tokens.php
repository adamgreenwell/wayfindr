<?php

/*
 * The account API-tokens page.
 *
 * Reached from the account home, which already speaks the agent's language, so
 * before this the page that hands out credentials was the one place an admin
 * switched back to English -- on the surface where misreading a sentence has
 * the highest cost on this platform.
 *
 * Three kinds of string on this page are NOT copy and are handled as such:
 *
 *   - `Authorization: Bearer <token>` is a wire format. It is sent verbatim or
 *     the request fails, so it is passed into the sentence as a parameter and
 *     is identical in every language on purpose.
 *   - The token hint (`wf_…a1b2`) is the credential's own identifier.
 *   - Token names, site names and the issuing agent's name are the account's
 *     own data.
 *
 * The abilities ARE translated, which is the one judgement call here. The
 * `read` slug is what the API takes, but nobody types it: it is a checkbox on
 * this very page, and the column exists so an admin can see at a glance what a
 * token is allowed to do. A German admin reading `Lesen` is better served than
 * one reading `read`.
 */

return [
    'title' => 'API and webhooks',
    'subtitle' => "Scoped API access and signed event delivery for this account's integrations.",
    'back' => 'Back to account',

    // Usable rather than merely un-revoked: a token past its expiry is refused
    // at authentication and labelled Expired in the table, so counting it as
    // active would contradict the same page.
    // The token noun matters now that this page also counts webhook endpoints.
    'active' => '{1} :count active token|[2,*] :count active tokens',

    'flash' => [
        'created' => 'API token created. Copy it now — it cannot be shown again.',
        'created_limited' => 'API token created, limited to the sites you support today. Copy it now — it cannot be shown again.',
        'revoked' => 'API token revoked.',
        'already_revoked' => 'That API token was already revoked.',
    ],

    'issued' => [
        'heading' => 'Copy this now',
        'once' => 'Shown once',
        'hashed' => 'This is the only time this token is shown. Wayfindr stores a hash of it, not the token itself, so it cannot be recovered — if you lose it, revoke it and issue another.',
        'send_as' => 'Send it as :header. Treat it like a password: anyone holding it can use every ability you grant below.',
    ],

    'list' => [
        'heading' => 'Tokens',
        'total' => '{1} :count token|[2,*] :count tokens',
        'empty' => 'No tokens yet. Nothing outside this dashboard can read this account’s support data.',
        'column_name' => 'Name',
        'column_token' => 'Token',
        'column_reaches' => 'Reaches',
        'column_last_used' => 'Last used',
        'column_state' => 'State',
        'column_action' => 'Action',
        // Two forms rather than one with an optional tail: German puts the
        // agent's name in a different place from English, and a sentence
        // assembled by concatenation cannot move it.
        'created' => 'Created :when',
        'created_by' => 'Created :when by :name',
        'revoke' => 'Revoke',
        'revoking_keeps' => 'Revoking keeps the row. What it existed for and when it was last used is the part worth keeping after somebody turns it off.',
    ],

    'reaches' => [
        // Restricted, and every site it named has since been purged. This
        // reaches NOTHING -- the opposite of what an empty site list means for
        // an unrestricted token, which is why it cannot share that string.
        'purged' => 'No sites — every site it was limited to has been purged',
        'every_site' => 'Every site on this account',
        'unsupported' => 'sites you do not support',
        'no_abilities' => 'No abilities',
    ],

    'abilities' => [
        'read' => 'Read',
        'write' => 'Write',
    ],

    'last_used' => [
        'never' => 'Never used',
    ],

    'state' => [
        'revoked' => 'Revoked :when',
        'expired' => 'Expired :when',
        'expires' => 'Expires :when',
        'active' => 'Active',
    ],

    'create' => [
        'heading' => 'Issue a token',
        'read_only' => 'Read and write are separate',
        'name_label' => 'What is it for',
        'name_placeholder' => 'Reporting sync',
        'name_help' => 'Written for whoever finds this row in a year and has to decide whether it is still needed.',
        'abilities_label' => 'What it may do',
        'ability_read' => 'Read conversations, messages, tickets and visitors',
        'ability_write' => 'Open conversations, post messages, and create or transition tickets',
        'abilities_help' => 'Each ability stands alone. Write does not grant read, and read never grants write.',
        'abilities_limited' => 'Abilities your role cannot perform are unavailable.',
        'expires_label' => 'Expires after',
        'expires_help' => 'Days. Left empty the token never expires, which means it stops being anybody’s job to notice it.',
        'sites_label' => 'Restrict to sites',
        // Said where the decision is made rather than only in the docs: a token
        // is always pinned to a list, cannot reach further than the person
        // issuing it, and does not widen later as the account grows.
        'sites_help' => 'Tick none and the token reaches every site :today. A site created afterwards is not added to it — issue a new token when you want one to cover more. An integration that watches one site should not be a credential for all of them.',
        'sites_help_today' => 'you support today',
        'submit' => 'Issue token',
    ],

    'accountability' => 'A token has no person behind it, so a read made with one cannot answer :who read it the way a dashboard read can. Writes are attributed to the token, never to the person who issued it. That is why a token is limited by what it can reach — and why an operator access grant never widens one.',
    'accountability_who' => 'who',
];
