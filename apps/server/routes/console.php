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

Schedule::command('wayfindr:prune-cobrowse-content')
    ->hourly()
    ->description('Strip raw cobrowse page content from ended sessions past the retention window.');
