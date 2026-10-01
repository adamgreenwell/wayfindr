<?php

return [
    'section' => [
        'heading' => 'Erase this contact',
        'lede' => 'For a request to delete everything held about this person',
        'body' => 'Erasing deletes this contact’s identity, conversations, messages, uploaded files, contact notes and ratings. Tickets stay as work items with the person removed. It cannot be undone.',
        'link' => 'Review what will be erased',
    ],
    'page' => [
        'title' => 'Erase contact',
        'heading' => 'Erase this contact',
        'lede' => 'Check what will go, what stays, and what Wayfindr cannot reach, then confirm.',
        'back' => 'Back to contact',
    ],
    'deleted' => [
        'heading' => 'Deleted',
        'identity' => 'Name, email, host visitor ID, browser IDs and custom attributes',
        'conversations' => 'Conversations',
        'messages' => 'Messages',
        'attachments' => 'Uploaded files',
        'notes' => 'Contact notes',
        'ratings' => 'Ratings',
        'cobrowse_sessions' => 'Cobrowse sessions',
        'also' => 'Also removed: alerts that mention these conversations, pending webhook deliveries for them, and the text of every audit entry about them. Audit entries keep who did what and when.',
    ],
    'tickets' => [
        'heading' => 'Tickets kept with the person removed',
        'body' => 'Each keeps its status, priority, labels, assignee and external issue link. Its subject becomes “Ticket #N (requester erased)”, and its description, visitor details, support code and note text are removed.',
        'empty' => 'This contact has no tickets.',
    ],
    'unreachable' => [
        'heading' => 'What erasure cannot reach',
        'backups' => 'Backups taken before now still hold this person’s data. Restoring one through Wayfindr erases them again, but only while this installation keeps the storage volume that records its erasures.',
        'external' => 'Linked external issues keep whatever was copied into them:',
        'mail' => 'Replies already emailed to them.',
        'ai' => 'Anything the AI provider kept of copilot requests.',
        'api' => 'Anything an API integration has already fetched.',
        'future' => 'If they come back they are a new contact, and a host page that sends their visitor ID recreates the record.',
    ],
    'form' => [
        'confirm' => 'Type :word to confirm',
        'password' => 'Your current password',
        'submit' => 'Erase this contact permanently',
    ],
    'errors' => [
        'confirm' => 'Type :word exactly to confirm.',
        'alert_mail_sending' => 'An alert email about this contact\'s conversations or tickets is being sent to an agent right now. Nothing was erased. Try again in a couple of minutes.',
        'copilot_running' => 'The AI assistant is working on one of this contact\'s conversations right now, so their transcript may be on its way to the AI provider. Nothing was erased. Try again in a few minutes.',
        'ledger_unwritable' => 'Wayfindr could not record this erasure on its storage volume, so nothing was erased. Ask whoever runs this installation to check that its storage is writable, then try again.',
        'note_posting' => 'A note on one of this contact\'s tickets is being posted to its linked issue right now. Nothing was erased. Try again in a couple of minutes.',
    ],
    'ticket_subject' => 'Ticket #:number (requester erased)',
    'flash' => [
        'erased' => 'Contact erased.',
        'receipt' => 'Receipt reference: :receipt',
    ],
];
