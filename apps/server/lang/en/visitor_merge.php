<?php

return [
    'heading' => 'Merge duplicate contact',
    'lede' => 'Choose the one contact record the team should keep',
    'boundary' => [
        'heading' => 'Permanent identity decision',
        'body' => 'The current contact will be deleted after its conversations, tickets, contact notes, uploads, and browser IDs move to the contact you choose.',
        'precedence' => 'The chosen contact keeps its populated identity and custom attribute values; the current contact fills blanks. Different populated host visitor IDs cannot be merged.',
        'continuity' => 'Old browser IDs remain private aliases of the chosen contact so open tabs and returning browsers do not recreate the duplicate.',
    ],
    'search' => [
        'label' => 'Find the contact to keep',
        'placeholder' => 'Name, email, host ID, or browser ID',
        'submit' => 'Find contacts',
        'clear' => 'Clear',
        'empty' => 'No other contacts on this site match that search.',
        'limit' => 'Showing up to 10 matches from this site.',
    ],
    'candidate' => [
        'contact' => 'Contact to keep',
        'email' => 'Email',
        'host_id' => 'Host visitor ID',
        'browser_id' => 'Browser ID',
        'last_seen' => 'Last seen',
        'not_provided' => 'Not provided',
        'confirm' => 'I checked that this is the same person and understand the merge cannot be undone.',
        'submit' => 'Merge into this contact',
    ],
    'errors' => [
        'target_required' => 'Choose a valid contact to keep.',
        'same_contact' => 'Choose a different contact to keep.',
        'external_id_conflict' => 'These contacts have different host visitor IDs. Resolve that identity conflict before merging them.',
        'alias_conflict' => 'One browser ID already belongs to another contact. No records were changed.',
    ],
    'flash' => [
        'merged' => 'Duplicate contact merged. Its support history and browser IDs now belong to this contact.',
    ],
];
