<?php

declare(strict_types=1);

return [
    // The cobrowse panel's vocabulary.
    //
    // These strings are produced by support classes that also run outside a
    // request -- a broadcast payload, a console command, the operator readiness
    // page -- so those classes keep answering in English and name the copy they
    // mean with a `copy` key. The surface translates. A model that called __()
    // here would answer in whatever locale the last request happened to leave
    // behind. See docs/product/dashboard-language.md.
    'transport' => [
        'recovery_locked' => 'Fresh snapshot already requested. Wait for the visitor widget before retrying.',
        'inactive' => [
            'label' => 'Unavailable',
            'message' => 'Cobrowse transport is not active.',
            'guidance' => 'Wait for an active cobrowse session before relying on cobrowse.',
            'recovery_action' => 'Wait for the visitor page to report before requesting recovery.',
        ],
        'no_reports' => [
            'label' => 'Unavailable',
            'message' => 'No cobrowse transport reports have arrived yet.',
            'guidance' => 'Wait for the visitor page to report before relying on cobrowse.',
            'recovery_action' => 'Wait for the visitor page to report before requesting recovery.',
        ],
        'stale' => [
            'label' => 'Stale',
            'message' => 'No cobrowse report has arrived in the last 2 minutes.',
            'guidance' => 'Ask the visitor to confirm what they see before relying on the preview.',
            'recovery_action' => 'Request a fresh snapshot if the preview looks out of date, and confirm details through chat.',
        ],
        'reconnecting' => [
            'label' => 'Reconnecting',
            'message' => 'The visitor transport has reconnected recently; preview data may briefly lag.',
            'guidance' => 'Use chat to confirm anything that depends on fast-changing page state.',
            'recovery_action' => 'Give the visitor widget a moment, then request a fresh snapshot if the preview still lags.',
        ],
        'degraded' => [
            'label' => 'Degraded',
            'message' => 'Cobrowse reports are arriving, but the visitor page is changing faster than Wayfindr can fully replay.',
            'guidance' => 'Use the preview for orientation and confirm fast-changing details through chat.',
            'recovery_action' => 'Request a fresh snapshot once the visitor widget settles, and use chat for fast-changing details.',
        ],
        'live' => [
            'label' => 'Live',
            'message' => 'Cobrowse reports are arriving normally.',
            'guidance' => 'Preview is current enough to use alongside chat.',
            'guidance_pressure' => 'Use chat to confirm anything that depends on fast-changing page state.',
            'recovery_action' => 'No recovery action needed.',
        ],
    ],

    'consent' => [
        'unavailable' => [
            'label' => 'Unavailable',
            'message' => 'Visitor has not granted cobrowse consent.',
        ],
        'pending' => [
            'label' => 'Pending consent',
            'message' => 'Waiting for visitor consent before cobrowsing can start.',
        ],
        'granted' => [
            'label' => 'Granted',
            'message' => 'Visitor granted cobrowse consent.',
        ],
        'revoked' => [
            'label' => 'Revoked',
            'message' => 'Visitor revoked cobrowse consent.',
        ],
        'ended' => [
            'label' => 'Ended',
            'message' => 'Cobrowse session ended.',
        ],
    ],

    'actions' => [
        'cancel_request' => 'Cancel request',
        'end' => 'End cobrowse',
    ],

    'resync' => [
        'fulfilled' => [
            'label' => 'Fresh snapshot received',
            'message' => 'The visitor widget sent a clean masked snapshot.',
        ],
        'exhausted' => [
            'label' => 'Fresh snapshot retry limit reached',
            'message' => 'The visitor widget tried to send a clean snapshot but could not complete it. Request another clean snapshot or confirm the page state through chat.',
        ],
        'expired' => [
            'label' => 'Fresh snapshot expired',
            'message' => 'The visitor widget did not answer in time. Request another clean snapshot or continue through chat.',
        ],
        'delayed' => [
            'label' => 'Fresh snapshot delayed',
            'message' => 'The visitor widget has not answered yet. Request another clean snapshot or confirm the page state through chat.',
        ],
        'pending' => [
            'label' => 'Fresh snapshot requested',
            'message' => 'Waiting for the visitor widget to send a clean page snapshot.',
        ],
    ],

    'snapshot_recovery' => [
        'pending' => [
            'label' => 'Snapshot refresh already requested',
            'message' => 'A fresh snapshot request is already waiting on the visitor widget. Use chat while it catches up.',
        ],
        'unknown' => [
            'label' => 'Snapshot time needs confirmation',
            'message' => 'Ask the visitor what they see or request a fresh snapshot before relying on this preview.',
        ],
        'needs_refresh' => [
            'label' => 'Snapshot may need refresh',
            'message' => 'Request a fresh snapshot before relying on this preview, or confirm the page through chat.',
        ],
    ],

    'timeline' => [
        'requested' => [
            'label' => 'Snapshot requested',
            'detail' => ':actor asked the visitor widget for a clean masked snapshot.',
            'badge' => 'Requested',
        ],
        'responded' => [
            'label' => 'Visitor widget responded',
            'detail' => 'A fresh cobrowse snapshot response arrived from the visitor page.',
            'badge' => 'Recovered',
        ],
        'refreshed' => [
            'label' => 'Masked snapshot refreshed',
            'detail' => 'The clean page snapshot is available in the agent preview.',
            'badge' => 'Preview updated',
        ],
        'exhausted' => [
            'label' => 'Retry limit reached',
            'detail' => 'The visitor widget stopped retrying this request ID after repeated failures.',
            'badge' => 'Exhausted',
        ],
        'expired' => [
            'label' => 'Request expired',
            'detail' => 'No widget response arrived before the recovery window closed.',
            'badge' => 'Expired',
        ],
        'retry_available' => [
            'label' => 'Retry available',
            'detail' => 'Support can request another clean snapshot without waiting on the first request.',
            'badge' => 'Retry',
        ],
        'expires' => [
            'label' => 'Request expires',
            'detail' => 'Wayfindr will stop advertising this stale request after the expiration window.',
            'badge' => 'Guardrail',
        ],
        'waiting' => [
            'label' => 'Waiting on visitor widget',
            'detail' => 'Retry opens :elapsed.',
            'detail_unknown' => 'Retry opens when the retry window opens.',
            'badge' => 'Pending',
        ],
        'ignored' => [
            'label' => 'Snapshot response ignored',
            'badge' => 'Ignored',
            'expired' => 'A widget response arrived after the recovery window closed.',
            'mismatched' => 'A widget response arrived for a different recovery request.',
            'already_fulfilled' => 'A duplicate widget response arrived after Wayfindr had already accepted a fresh snapshot.',
            'unmatched' => 'A widget response could not be matched to the active recovery request.',
        ],
    ],

    'freshness' => [
        'unknown' => [
            'label' => 'Time unknown',
            'message' => 'Use chat to confirm what the visitor sees before relying on this preview.',
        ],
        'stale' => [
            'label' => 'Stale',
            'message' => 'Snapshot is older than 5 minutes. Confirm through chat or request a fresh snapshot.',
        ],
        'aging' => [
            'label' => 'Aging',
            'message' => 'Snapshot is a few minutes old. Request a fresh snapshot if this page is changing.',
        ],
        'fresh' => [
            'label' => 'Fresh',
            'message' => 'Snapshot was reported recently.',
        ],
        'reported' => 'Reported :elapsed',
        'reported_unknown' => 'Report time unavailable',
    ],

    // Counts the panel shows beside a number. The class keeps building the
    // English -- it is broadcast and printed by a console command, neither of
    // which has a locale -- and also reports the raw number so the surface can
    // pluralise it. German does not always agree with English about which
    // words take a plural, which is the whole reason these are trans_choice
    // rather than ':count batches'.
    'units' => [
        'applied' => ':count applied',
        'milliseconds' => ':count ms',
        'bytes' => ':count bytes',
        'no_text_preview' => 'No text preview reported.',
        'viewport' => 'Visitor viewport :widthpx',
        'still_active' => 'Still active',
        'not_granted_yet' => 'Not granted yet',
        'batches' => '{1} 1 batch|[2,*] :count batches',
        'mutations' => '{1} 1 mutation|[2,*] :count mutations',
        'dropped' => ':count dropped',
        'skipped' => ':count skipped',
        'sequence' => 'Sequence :count',
        'nodes' => '{1} 1 node|[2,*] :count nodes',
        'masked' => ':count masked',
        'not_reported' => 'Not reported',
        'focused' => 'Focused',
        'not_focused' => 'Not focused',
        'unknown_agent' => 'Unknown agent',
        'visitor' => 'Visitor',
        'not_recorded' => 'Not recorded',
    ],

    'drift' => [
        'steady' => [
            'label' => 'Aligned',
            'message' => 'Replay updates are landing on the expected nodes.',
        ],
        'watch' => [
            'label' => 'Minor drift',
            'message' => 'Some replay updates did not match this preview. Confirm fast-changing areas through chat.',
        ],
        'drifting' => [
            'label' => 'Drifting',
            'message' => 'Many replay updates no longer match this preview. Request a fresh snapshot to resync.',
        ],
        'summary' => ':unresolved of :addressable drifted',
    ],

    // The English builds this sentence by gluing parts together with ', ' and
    // an English pluraliser. The structure is the part that does not travel,
    // so the surface composes it from counts instead.
    'pressure' => [
        'dropped' => '{1} 1 dropped batch|[2,*] :count dropped batches',
        'skipped' => '{1} 1 skipped mutation|[2,*] :count skipped mutations',
        'separator' => ', ',
        'none' => 'No drops reported',
        'none_recent' => 'No recent drops reported',
    ],

    'labels' => [
        'request_snapshot' => 'Request fresh snapshot',
        'request_another_snapshot' => 'Request another fresh snapshot',
        'requested_by' => 'Requested by :actor',
        'received' => 'Received :elapsed',
        'expires' => 'Expires :elapsed',
        'expired' => 'Expired :elapsed',
    ],
];
