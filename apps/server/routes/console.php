<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wayfindr:send-alert-digests')
    ->hourly()
    ->description('Queue metadata-only Wayfindr alert digest email.');

Schedule::command('wayfindr:send-unattended-conversation-alerts')
    ->everyFiveMinutes()
    ->description('Email agents when a visitor message waits unseen past the threshold.');

Schedule::command('wayfindr:expire-idle-cobrowse-sessions')
    ->everyFiveMinutes()
    ->description('End idle cobrowse sessions so abandoned sessions stop reading active and become prunable.');

Schedule::command('wayfindr:prune-cobrowse-content')
    ->hourly()
    ->description('Strip raw cobrowse page content from ended sessions past the retention window.');

Schedule::command('wayfindr:expire-break-glass-grants')
    ->everyFiveMinutes()
    ->description('Stamp overdue break-glass grants as expired and audit the transition.');

Schedule::command('wayfindr:sweep-orphaned-attachments')
    ->hourly()
    ->description('Remove abandoned/failed unbound attachment uploads and orphaned storage objects.');

Schedule::command('wayfindr:prune-api-idempotency-keys')
    ->hourly()
    ->description('Delete expired public API write receipts after their 24-hour retry window.');

Schedule::command('wayfindr:queue-conversation-reply-deliveries')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Recover durable conversation emails whose queue handoff did not complete.');

Schedule::command('wayfindr:queue-outbound-webhooks')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Recover durable outbound webhooks whose queue handoff did not complete.');

Schedule::command('wayfindr:queue-agent-realtime-evictions')
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Recover agent realtime evictions whose queue handoff did not complete.');

// Daily rather than only at deploy time, because the deploy's own sweep cannot
// be the last word. `$ACTIVATE_RELEASE()` stops NEW requests reaching the old
// release; it does not cancel requests already executing, and one of those is
// still running the unsanitised writer. If it writes after the sweep passed its
// row, the credential is back and nothing looks wrong.
//
// This also covers the install shapes that never run the Forge script at all --
// Docker and Compose upgrade without it, and would otherwise have only the
// migration's pass.
//
// It is a cheap scan that reports nothing on every run after the first, and it
// can be retired once no install can still be carrying pre-sanitiser rows.
Schedule::command('wayfindr:sanitise-page-urls')
    ->daily()
    ->description('Rewrite stored visitor page addresses that still carry a query string.');

// Retention for presence-only visitors (ADR 0019 §4). Daily, because the window
// is measured in days and an hourly pass would scan for nothing 23 times over.
Schedule::command('wayfindr:prune-presence-visitors')
    ->daily()
    ->description('Delete visitors who never made contact and whose last heartbeat is past the retention window.');
