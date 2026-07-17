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
