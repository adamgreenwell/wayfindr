<?php

return [
    'heading' => 'Contact notes',
    'lede' => 'Private context attached to this person, not one ticket',
    'count' => '{0} No notes|{1} :count note|[2,*] :count notes',
    'boundary' => [
        'heading' => 'Private team context',
        'body' => 'These notes are visible to team members who can open this contact record. They are never sent to the visitor or an external ticket.',
        'care' => 'Record only what the team needs for continuity. Avoid passwords, payment details, health information, and other unnecessary sensitive data.',
        'delete' => 'Deleting a note permanently removes its body. The account audit keeps only a body-free deletion receipt.',
    ],
    'form' => [
        'label' => 'Add a private contact note',
        'placeholder' => 'Context the next teammate should know',
        'help' => 'Up to 4,000 characters. Notes cannot be edited; add a correction or delete the note.',
        'submit' => 'Add contact note',
    ],
    'empty' => [
        'heading' => 'No contact notes yet',
        'body' => 'Durable team context about this person will appear here.',
    ],
    'author_unknown' => 'Former team member',
    'delete' => 'Delete note',
    'flash' => [
        'added' => 'Contact note added.',
        'deleted' => 'Contact note deleted. Its body was permanently removed.',
    ],
    'errors' => [
        'required' => 'Enter a contact note before saving.',
    ],
];
