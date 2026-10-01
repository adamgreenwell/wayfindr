<?php

return [
    'section' => [
        'heading' => 'Export this contact',
        'lede' => 'For a request to see everything held about this person',
        'body' => 'Download a ZIP of everything Wayfindr holds about this contact: their identity, conversations, files and tickets, the alerts and audit entries about them, and text kept in passing that quotes them. Agents are named by role, not by name. Review it before you send it: it includes contact notes, which your law may let you withhold.',
        'link' => 'Download export',
    ],
    'before_erasing' => 'If the person also asked for a copy of their data, download it before you erase them: erasing cannot be undone.',
    'errors' => [
        'gone' => 'This contact was erased or merged while the export was being prepared, so nothing was downloaded.',
        'too_large' => 'This contact’s history is larger than one export archive can hold, so nothing was downloaded.',
    ],
    'readme' => [
        'title' => 'Everything Wayfindr holds about one contact',
        'intro' => 'This archive was exported from Wayfindr, a customer-support platform, to answer a request from the person it describes. Every file in it describes the same moment.',
        'moment' => 'Exported at :time (UTC).',
        'contents_heading' => 'What is included',
        'contents' => [
            'visitor.json: the contact record with every attribute and the last page address, their browser IDs, and the team’s contact notes.',
            'conversations/: one file per conversation, with its messages, ratings, file details, cobrowse sessions, AI assistant output, and the email replies sent to them.',
            'cobrowse.json: cobrowse sessions of theirs on a conversation that is not theirs, when there are any.',
            'proactive.json: the proactive messages shown to them, and when each was shown, engaged with or dismissed.',
            'tickets/: their tickets, with notes, where each note was sent and how that went, and the linked issues.',
            'alerts.json: the alerts agents received about them.',
            'audit.json: the record of what happened to them, their conversations and their tickets.',
            'incidental.json: text kept in passing that can quote them: webhook responses, automation errors, saved queue searches and failed jobs.',
            'break_glass.json: access to their data by the platform operator, and why.',
            'attachments/: the files shared in their conversations.',
        ],
        'roles' => 'People are named by role, not by name: “visitor” is the person themselves, and “agent”, “platform operator”, “integration” and “system” say who else acted. The identity of the staff involved is data about them; the installation adds it where the law requires naming who saw the data.',
        'review' => 'The installation’s operator reviews this archive before sending it, and may remove anything their law lets them withhold.',
        'not_reached_heading' => 'What this archive cannot include',
        'not_reached' => [
            'backups;',
            'external issue trackers;',
            'mail already sent;',
            'what the AI provider kept;',
            'what API integrations already fetched;',
            'files an agent uploaded to a reply that has not been sent;',
            'logs, failed-job text that does not name one of their support codes, and the operator’s infrastructure.',
        ],
        'counts_heading' => 'How much is included',
        'pruned' => 'These files were removed by retention after the export started, so they are listed but not included:',
        'withheld' => 'These files were not finished uploading, or the malware scanner holds them, so they are listed but not included:',
    ],
];
