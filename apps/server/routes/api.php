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
use App\Http\Controllers\Widget\PresenceController;
use App\Http\Controllers\Widget\ProactiveMessageController;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use Illuminate\Support\Facades\Route;

Route::post('/widget/presence', PresenceController::class)
    ->middleware('throttle:widget-presence')
    ->name('widget.presence');
Route::post('/widget/bootstrap', BootstrapController::class)
    ->middleware('throttle:widget-bootstrap')
    ->name('widget.bootstrap');
Route::post('/widget/broadcasting/auth', BroadcastAuthController::class)
    ->middleware('throttle:widget-broadcast-auth')
    ->name('widget.broadcasting.auth');
// Its own budget, not bootstrap's. This is now read on every PAGE LOAD --
// presence configuration has to reach a visitor who never opens the panel --
// while bootstrap is read once somebody does. Sharing a bucket meant passive
// browsing from a busy shared address could exhaust it and leave visitors who
// then tried to start a chat unable to initialise one.
Route::get('/widget/appearance', AppearanceController::class)
    ->middleware('throttle:widget-config')
    ->name('widget.appearance');
Route::post('/widget/proactive-messages/{rulePublicId}/authorize', [ProactiveMessageController::class, 'authorizeDisplay'])
    ->whereUuid('rulePublicId')
    ->middleware('throttle:widget-proactive')
    ->name('widget.proactive-messages.authorize');
Route::post('/widget/proactive-messages/{deliveryPublicId}/outcomes', [ProactiveMessageController::class, 'recordOutcome'])
    ->whereUuid('deliveryPublicId')
    ->middleware('throttle:widget-proactive')
    ->name('widget.proactive-messages.outcomes.store');

Route::middleware('throttle:widget-bootstrap')->group(function (): void {
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
| Reads and the deliberately narrow write surface are public. Outbound
| webhooks follow separately.
|
*/
Route::prefix('v1')
    // The per-token limit only. Failed authentication is bounded inside the
    // authentication middleware, because middleware priority sorts it ahead
    // of any throttle placed before it.
    ->middleware('throttle:api-token')
    ->name('api.v1.')
    ->group(function (): void {
        Route::middleware(AuthenticateApiToken::class.':'.ApiToken::ABILITY_READ)
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

        Route::middleware(AuthenticateApiToken::class.':'.ApiToken::ABILITY_WRITE)
            ->group(function (): void {
                Route::post('/conversations', [ApiConversationController::class, 'store'])->name('conversations.store');
                Route::post('/conversations/{supportCode}/messages', [ApiConversationController::class, 'storeMessage'])->name('conversations.messages.store');

                Route::post('/tickets', [ApiTicketController::class, 'store'])->name('tickets.store');
                Route::patch('/tickets/{ticket}', [ApiTicketController::class, 'update'])->whereNumber('ticket')->name('tickets.update');
            });
    });
