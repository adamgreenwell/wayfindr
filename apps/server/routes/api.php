<?php

use App\Http\Controllers\Widget\BootstrapController;
use App\Http\Controllers\Widget\BroadcastAuthController;
use App\Http\Controllers\Widget\CobrowseConsentController;
use App\Http\Controllers\Widget\CobrowseTelemetryController;
use App\Http\Controllers\Widget\ConversationController;
use App\Http\Controllers\Widget\ConversationMessageController;
use Illuminate\Support\Facades\Route;

Route::post('/widget/bootstrap', BootstrapController::class)->name('widget.bootstrap');
Route::post('/widget/broadcasting/auth', BroadcastAuthController::class)->name('widget.broadcasting.auth');
Route::post('/conversations', [ConversationController::class, 'store'])->name('conversations.store');
Route::post('/conversations/{supportCode}/cobrowse-consent', [CobrowseConsentController::class, 'store'])
    ->name('conversations.cobrowse-consent.store');
Route::post('/conversations/{supportCode}/cobrowse-telemetry', [CobrowseTelemetryController::class, 'store'])
    ->name('conversations.cobrowse-telemetry.store');
Route::get('/conversations/{supportCode}/messages', [ConversationMessageController::class, 'index'])
    ->name('conversations.messages.index');
Route::post('/conversations/{supportCode}/messages', [ConversationMessageController::class, 'store'])
    ->name('conversations.messages.store');
