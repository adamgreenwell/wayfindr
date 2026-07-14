<?php

use App\Http\Controllers\Integrations\GitHubWebhookController;
use App\Http\Controllers\Integrations\GitLabWebhookController;
use App\Http\Controllers\Integrations\JiraWebhookController;
use App\Http\Controllers\Widget\BootstrapController;
use App\Http\Controllers\Widget\BroadcastAuthController;
use App\Http\Controllers\Widget\CobrowseConsentController;
use App\Http\Controllers\Widget\CobrowseMutationController;
use App\Http\Controllers\Widget\CobrowsePageStateController;
use App\Http\Controllers\Widget\CobrowseSnapshotController;
use App\Http\Controllers\Widget\CobrowseStatusController;
use App\Http\Controllers\Widget\CobrowseTelemetryController;
use App\Http\Controllers\Widget\ConversationAttachmentController;
use App\Http\Controllers\Widget\ConversationController;
use App\Http\Controllers\Widget\ConversationMessageController;
use App\Http\Controllers\Widget\ConversationTypingController;
use Illuminate\Support\Facades\Route;

Route::post('/widget/bootstrap', BootstrapController::class)
    ->middleware('throttle:widget-bootstrap')
    ->name('widget.bootstrap');
Route::post('/widget/broadcasting/auth', BroadcastAuthController::class)
    ->middleware('throttle:widget-broadcast-auth')
    ->name('widget.broadcasting.auth');
Route::post('/conversations', [ConversationController::class, 'store'])
    ->middleware('throttle:widget-conversation')
    ->name('conversations.store');

Route::middleware('throttle:widget-cobrowse')->group(function (): void {
    Route::get('/conversations/{supportCode}/cobrowse', CobrowseStatusController::class)
        ->name('conversations.cobrowse.show');
    Route::post('/conversations/{supportCode}/cobrowse-consent', [CobrowseConsentController::class, 'store'])
        ->name('conversations.cobrowse-consent.store');
    Route::post('/conversations/{supportCode}/cobrowse-telemetry', [CobrowseTelemetryController::class, 'store'])
        ->name('conversations.cobrowse-telemetry.store');
    Route::post('/conversations/{supportCode}/cobrowse-page-state', [CobrowsePageStateController::class, 'store'])
        ->name('conversations.cobrowse-page-state.store');
    Route::post('/conversations/{supportCode}/cobrowse-snapshot', [CobrowseSnapshotController::class, 'store'])
        ->name('conversations.cobrowse-snapshot.store');
    Route::post('/conversations/{supportCode}/cobrowse-mutations', [CobrowseMutationController::class, 'store'])
        ->name('conversations.cobrowse-mutations.store');
});

Route::middleware('throttle:widget-message')->group(function (): void {
    Route::get('/conversations/{supportCode}/messages', [ConversationMessageController::class, 'index'])
        ->name('conversations.messages.index');
    Route::post('/conversations/{supportCode}/messages', [ConversationMessageController::class, 'store'])
        ->name('conversations.messages.store');
    Route::post('/conversations/{supportCode}/typing', ConversationTypingController::class)
        ->name('conversations.typing.store');
});

Route::middleware('throttle:widget-attachment-upload')->group(function (): void {
    Route::post('/conversations/{supportCode}/attachments', [ConversationAttachmentController::class, 'store'])
        ->name('conversations.attachments.store');
});

Route::middleware('throttle:widget-attachment')->group(function (): void {
    Route::get('/conversations/{supportCode}/attachments/{attachment}', [ConversationAttachmentController::class, 'show'])
        ->whereNumber('attachment')
        ->name('conversations.attachments.show');
});

Route::post('/integrations/github/webhook/{connection}', GitHubWebhookController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('integrations.github.webhook');

Route::post('/integrations/gitlab/webhook/{connection}', GitLabWebhookController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('integrations.gitlab.webhook');

Route::post('/integrations/jira/webhook/{connection}', JiraWebhookController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('integrations.jira.webhook');
