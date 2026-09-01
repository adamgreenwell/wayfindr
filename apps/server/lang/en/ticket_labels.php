<?php

/*
 * The account ticket-labels page.
 *
 * Reached from the ticket queue, which already speaks the agent's language,
 * and it links straight back there -- so this page sitting in English put two
 * languages either side of one click.
 *
 * The counts are `trans_choice` rather than a number welded to a pluralised
 * noun. `Str::plural('ticket', $n)` is English grammar written in PHP: it
 * cannot produce `Tickets` for German or leave `ticket` invariant for Italian,
 * and no catalogue entry can rescue a noun that was already chosen before the
 * translation was consulted.
 */

return [
    'title' => 'Ticket labels',
    'subtitle' => 'Manage account-wide labels used for ticket triage and dashboard filters.',
    'back' => 'Back to account',

    'flash' => [
        'created' => 'Ticket label created.',
        'renamed' => 'Ticket label renamed.',
        'deleted' => 'Unused ticket label deleted.',
    ],

    'create' => [
        'heading' => 'Create label',
        'lede' => 'Make a reusable triage label before a ticket needs it.',
        'name' => 'Label name',
        'name_placeholder' => 'VIP Customer',
        'submit' => 'Create label',
    ],

    'list' => [
        'heading' => 'Labels',
        // `:count` arrives already grouped for the reader, so this must not
        // group it again.
        'total' => ':count total',
        'column_label' => 'Label',
        'column_slug' => 'Slug',
        'column_usage' => 'Usage',
        'column_manage' => 'Manage',
    ],

    'usage' => [
        'tickets' => '{1} 1 ticket|[2,*] :count tickets',
        'view_visible' => '{1} View 1 visible ticket|[2,*] View :count visible tickets',
        'none_visible' => 'No visible tickets',
    ],

    'manage' => [
        // Only a screen reader hears this, which is exactly why it needs
        // translating: the agent who cannot see the row is the one relying on
        // it to say which label the field belongs to.
        'rename' => 'Rename :name',
        'save' => 'Save label',
        'in_use' => '{1} In use on 1 ticket|[2,*] In use on :count tickets',
        'delete' => 'Delete unused',
    ],

    'empty' => [
        'heading' => 'No managed ticket labels yet.',
        'body' => 'Use labels when tickets need repeatable triage context, escalation cues, or workflow grouping. Start with a few labels your team will actually use.',
        'action' => 'Create the first label',
    ],
];
