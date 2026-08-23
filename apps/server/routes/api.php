<?php

use App\Http\Controllers\Api\V1\ConversationController as ApiConversationController;
use App\Http\Controllers\Api\V1\TicketController as ApiTicketController;
use App\Http\Controllers\Api\V1\TokenController as ApiTokenController;
use App\Http\Controllers\Api\V1\VisitorController as ApiVisitorController;
use App\Http\Controllers\Integrations\GitHubWebhookController;
use App\Http\Controllers\Integrations\GitLabWebhookController;
use App\Http\Controllers\Integrations\JiraWebhookController;
use App\Http\Controllers\Mail\InboundMailController;
use App\Http\Controllers\Widget\AppearanceController;
use App\Http\Controllers\Widget\ArticleController;
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
use App\Http\Controllers\Widget\ConversationRatingController;
use App\Http\Controllers\Widget\ConversationTypingController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

Route::post('/widget/bootstrap', BootstrapController::class)
    ->middleware('throttle:widget-bootstrap')
    ->name('widget.bootstrap');
Route::post('/widget/broadcasting/auth', BroadcastAuthController::class)
    ->middleware('throttle:widget-broadcast-auth')
    ->name('widget.broadcasting.auth');
Route::middleware('throttle:widget-bootstrap')->group(function (): void {
    Route::get('/widget/appearance', AppearanceController::class)
        ->name('widget.appearance');
    Route::get('/widget/articles', [ArticleController::class, 'index'])
        ->name('widget.articles.index');
    Route::get('/widget/articles/{slug}', [ArticleController::class, 'show'])
        ->where('slug', '[A-Za-z0-9-]+')
        ->name('widget.articles.show');
});

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
    Route::post('/conversations/{supportCode}/rating', ConversationRatingController::class)
        ->name('conversations.rating.store');
});

Route::middleware('throttle:widget-attachment-upload')->group(function (): void {
    Route::post('/conversations/{supportCode}/attachments', [ConversationAttachmentController::class, 'store'])
        ->name('conversations.attachments.store');
    Route::delete('/conversations/{supportCode}/attachments/{attachment}', [ConversationAttachmentController::class, 'destroy'])
        ->whereNumber('attachment')
        ->name('conversations.attachments.destroy');
});

Route::middleware('throttle:widget-attachment')->group(function (): void {
    Route::get('/conversations/{supportCode}/attachments/{attachment}', [ConversationAttachmentController::class, 'show'])
        ->whereNumber('attachment')
        ->name('conversations.attachments.show');
});

Route::post('/mail/inbound', InboundMailController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('mail.inbound');

Route::post('/integrations/github/webhook/{connection}', GitHubWebhookController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('integrations.github.webhook');

Route::post('/integrations/gitlab/webhook/{connection}', GitLabWebhookController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('integrations.gitlab.webhook');

Route::post('/integrations/jira/webhook/{connection}', JiraWebhookController::class)
    ->middleware('throttle:integrations-webhook')
    ->name('integrations.jira.webhook');

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| The only routes in this file that are a public contract (ADR 0018).
| Everything above is the widget talking to its own backend or a provider
| posting inbound -- internal surfaces that happen to be reachable over HTTP,
| and deliberately NOT frozen by this version.
|
| Read-only for now. Writes and outbound webhooks follow separately, and stay
| narrower than the dashboard.
|
*/
Route::prefix('v1')
    ->middleware([AuthenticateApiToken::class.':'.ApiToken::ABILITY_READ, 'throttle:api-token'])
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/me', ApiTokenController::class)->name('me');

        Route::get('/conversations', [ApiConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{supportCode}', [ApiConversationController::class, 'show'])->name('conversations.show');
        Route::get('/conversations/{supportCode}/messages', [ApiConversationController::class, 'messages'])->name('conversations.messages');

        Route::get('/tickets', [ApiTicketController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/{ticket}', [ApiTicketController::class, 'show'])->whereNumber('ticket')->name('tickets.show');

        Route::get('/visitors', [ApiVisitorController::class, 'index'])->name('visitors.index');
        Route::get('/visitors/{visitor}', [ApiVisitorController::class, 'show'])->whereNumber('visitor')->name('visitors.show');
    });
