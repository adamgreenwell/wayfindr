<?php

declare(strict_types=1);

return [
    // Copy the reply composer writes while an agent is working: upload states,
    // attachment errors, and the label a submit button takes while in flight.
    //
    // This partial is shared with the ticket page, which is not an extracted
    // route -- there the locale is the install default and these answer in
    // English, correctly. A shared VIEW may read the catalogue because it only
    // ever renders inside a request; a shared MODEL may not.
    'sending' => 'Sending...',
    'sending_reply' => 'Sending reply...',
    'sending_visitor_reply' => 'Sending visitor reply...',
    'uploading' => 'Uploading…',
    'attach_failed' => 'That file could not be attached.',
    'waiting_uploads' => 'Waiting for uploads to finish…',
    'remove' => 'Remove :name',
    'attachment' => 'attachment',
];
