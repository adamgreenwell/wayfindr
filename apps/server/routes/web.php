<?php

use App\Http\Controllers\AgentAccountAgentAccessController;
use App\Http\Controllers\AgentAccountAgentController;
use App\Http\Controllers\AgentAccountAgentRoleController;
use App\Http\Controllers\AgentAccountAuditController;
use App\Http\Controllers\AgentAccountController;
use App\Http\Controllers\AgentAccountIntegrationsController;
use App\Http\Controllers\AgentAlertController;
use App\Http\Controllers\AgentConversationAttachmentController;
use App\Http\Controllers\AgentConversationController;
use App\Http\Controllers\AgentConversationQueueController;
use App\Http\Controllers\AgentConversationTypingController;
use App\Http\Controllers\AgentDashboardController;
use App\Http\Controllers\AgentExternalIssueProviderConnectionController;
use App\Http\Controllers\AgentProfileController;
use App\Http\Controllers\AgentReadinessController;
use App\Http\Controllers\AgentReplyTemplateController;
use App\Http\Controllers\AgentSiteController;
use App\Http\Controllers\AgentSiteExternalIssueProjectController;
use App\Http\Controllers\AgentSupportCodeLookupController;
use App\Http\Controllers\AgentTicketController;
use App\Http\Controllers\AgentTicketExternalIssueController;
use App\Http\Controllers\AgentTicketExternalLinkController;
use App\Http\Controllers\AgentTicketLabelController;
use App\Http\Controllers\AgentTicketQueueController;
use App\Http\Controllers\AgentVisitorController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\FirstRunSetupController;
use App\Http\Controllers\OperatorDashboardController;
use App\Http\Controllers\OperatorReadinessConfirmationController;
use App\Http\Controllers\Widget\WidgetScriptController;
use App\Http\Middleware\EnsureAgentIsActive;
use App\Http\Middleware\EnsurePlatformOperator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/widget.js', WidgetScriptController::class)->name('widget.script');

Route::get('/setup', [FirstRunSetupController::class, 'create'])->name('setup.create');
Route::post('/setup', [FirstRunSetupController::class, 'store'])->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', EnsureAgentIsActive::class])->group(function () {
    Route::get('/dashboard', AgentDashboardController::class)->name('dashboard');
    Route::get('/dashboard/support-code', AgentSupportCodeLookupController::class)
        ->name('dashboard.support-code.lookup');
    Route::get('/dashboard/alerts', [AgentAlertController::class, 'index'])
        ->name('dashboard.alerts.index');
    Route::get('/dashboard/profile', [AgentProfileController::class, 'show'])
        ->name('dashboard.profile.show');
    Route::put('/dashboard/profile', [AgentProfileController::class, 'update'])
        ->name('dashboard.profile.update');
    Route::put('/dashboard/profile/alerts', [AgentProfileController::class, 'updateAlertPreferences'])
        ->name('dashboard.profile.alerts.update');
    Route::put('/dashboard/profile/password', [AgentProfileController::class, 'updatePassword'])
        ->name('dashboard.profile.password.update');
    Route::get('/dashboard/account', AgentAccountController::class)
        ->name('dashboard.account.show');
    Route::get('/dashboard/account/integrations', [AgentAccountIntegrationsController::class, 'show'])
        ->name('dashboard.account.integrations');
    Route::get('/dashboard/account/audit', [AgentAccountAuditController::class, 'index'])
        ->name('dashboard.account.audit.index');
    Route::get('/dashboard/account/audit/export', [AgentAccountAuditController::class, 'export'])
        ->name('dashboard.account.audit.export');
    Route::get('/dashboard/account/labels', [AgentTicketLabelController::class, 'index'])
        ->name('dashboard.account.labels.index');
    Route::post('/dashboard/account/labels', [AgentTicketLabelController::class, 'store'])
        ->name('dashboard.account.labels.store');
    Route::put('/dashboard/account/labels/{ticketLabel}', [AgentTicketLabelController::class, 'update'])
        ->name('dashboard.account.labels.update');
    Route::delete('/dashboard/account/labels/{ticketLabel}', [AgentTicketLabelController::class, 'destroy'])
        ->name('dashboard.account.labels.destroy');
    Route::get('/dashboard/account/reply-templates', [AgentReplyTemplateController::class, 'index'])
        ->name('dashboard.account.reply-templates.index');
    Route::post('/dashboard/account/reply-templates', [AgentReplyTemplateController::class, 'store'])
        ->name('dashboard.account.reply-templates.store');
    Route::put('/dashboard/account/reply-templates/{replyTemplate}', [AgentReplyTemplateController::class, 'update'])
        ->name('dashboard.account.reply-templates.update');
    Route::post('/dashboard/account/reply-templates/{replyTemplate}/archive', [AgentReplyTemplateController::class, 'archive'])
        ->name('dashboard.account.reply-templates.archive');
    Route::get('/dashboard/readiness', AgentReadinessController::class)
        ->name('dashboard.readiness.show');
    Route::post('/dashboard/readiness/confirmations', [OperatorReadinessConfirmationController::class, 'storeFromDashboard'])
        ->name('dashboard.readiness.confirmations.store');
    Route::post('/dashboard/account/agents', [AgentAccountAgentController::class, 'store'])
        ->name('dashboard.account.agents.store');
    Route::put('/dashboard/account/agents/{agent}/role', AgentAccountAgentRoleController::class)
        ->name('dashboard.account.agents.role.update');
    Route::post('/dashboard/account/agents/{agent}/deactivate', [AgentAccountAgentAccessController::class, 'deactivate'])
        ->name('dashboard.account.agents.deactivate');
    Route::post('/dashboard/account/agents/{agent}/reactivate', [AgentAccountAgentAccessController::class, 'reactivate'])
        ->name('dashboard.account.agents.reactivate');
    Route::post('/dashboard/alerts/read', [AgentAlertController::class, 'markAllRead'])
        ->name('dashboard.alerts.read-all');
    Route::post('/dashboard/alerts/{notification}/read', [AgentAlertController::class, 'markRead'])
        ->name('dashboard.alerts.read');
    Route::post('/dashboard/external-issue-provider-connections', [AgentExternalIssueProviderConnectionController::class, 'store'])
        ->name('dashboard.external-issue-provider-connections.store');
    Route::put('/dashboard/external-issue-provider-connections/{connection}/webhook-secret', [AgentExternalIssueProviderConnectionController::class, 'updateWebhookSecret'])
        ->name('dashboard.external-issue-provider-connections.webhook-secret.update');
    Route::put('/dashboard/external-issue-provider-connections/{connection}/capabilities', [AgentExternalIssueProviderConnectionController::class, 'updateCapabilities'])
        ->name('dashboard.external-issue-provider-connections.capabilities.update');
    Route::get('/dashboard/sites', [AgentSiteController::class, 'index'])
        ->name('dashboard.sites.index');
    Route::get('/dashboard/sites/new', [AgentSiteController::class, 'create'])
        ->name('dashboard.sites.create');
    Route::post('/dashboard/sites', [AgentSiteController::class, 'store'])
        ->name('dashboard.sites.store');
    Route::get('/dashboard/sites/{site}', [AgentSiteController::class, 'show'])
        ->name('dashboard.sites.show');
    Route::get('/dashboard/sites/{site}/tester', [AgentSiteController::class, 'tester'])
        ->name('dashboard.sites.tester');
    Route::put('/dashboard/sites/{site}', [AgentSiteController::class, 'update'])
        ->name('dashboard.sites.update');
    Route::put('/dashboard/sites/{site}/support-agents', [AgentSiteController::class, 'updateSupportAgents'])
        ->name('dashboard.sites.support-agents.update');
    Route::post('/dashboard/sites/{site}/external-issue-projects', [AgentSiteExternalIssueProjectController::class, 'store'])
        ->name('dashboard.sites.external-issue-projects.store');
    Route::delete('/dashboard/sites/{site}/external-issue-projects/{externalIssueProject}', [AgentSiteExternalIssueProjectController::class, 'destroy'])
        ->name('dashboard.sites.external-issue-projects.destroy');
    Route::get('/dashboard/conversations', AgentConversationQueueController::class)
        ->name('dashboard.conversations.index');
    Route::get('/dashboard/conversations/{supportCode}', [AgentConversationController::class, 'show'])
        ->name('dashboard.conversations.show');
    Route::get('/dashboard/visitors/{visitor}', [AgentVisitorController::class, 'show'])
        ->name('dashboard.visitors.show');
    Route::post('/dashboard/conversations/{supportCode}/close', [AgentConversationController::class, 'close'])
        ->name('dashboard.conversations.close');
    Route::post('/dashboard/conversations/{supportCode}/reopen', [AgentConversationController::class, 'reopen'])
        ->name('dashboard.conversations.reopen');
    Route::post('/dashboard/conversations/{supportCode}/claim', [AgentConversationController::class, 'claim'])
        ->name('dashboard.conversations.claim');
    Route::post('/dashboard/conversations/{supportCode}/release', [AgentConversationController::class, 'release'])
        ->name('dashboard.conversations.release');
    Route::post('/dashboard/conversations/{supportCode}/attachments', [AgentConversationAttachmentController::class, 'store'])
        ->name('dashboard.conversations.attachments.store');
    Route::get('/dashboard/conversations/{supportCode}/attachments/{attachment}', [AgentConversationAttachmentController::class, 'show'])
        ->whereNumber('attachment')
        ->name('dashboard.conversations.attachments.show');
    Route::delete('/dashboard/conversations/{supportCode}/attachments/{attachment}', [AgentConversationAttachmentController::class, 'destroy'])
        ->whereNumber('attachment')
        ->name('dashboard.conversations.attachments.destroy');
    Route::get('/dashboard/conversations/{supportCode}/messages', [AgentConversationController::class, 'messages'])
        ->name('dashboard.conversations.messages.index');
    Route::post('/dashboard/conversations/{supportCode}/messages', [AgentConversationController::class, 'storeMessage'])
        ->name('dashboard.conversations.messages.store');
    Route::post('/dashboard/conversations/{supportCode}/typing', AgentConversationTypingController::class)
        ->name('dashboard.conversations.typing.store');
    Route::post('/dashboard/conversations/{supportCode}/tickets', [AgentConversationController::class, 'storeTicket'])
        ->name('dashboard.conversations.tickets.store');
    Route::get('/dashboard/tickets', AgentTicketQueueController::class)
        ->name('dashboard.tickets.index');
    Route::get('/dashboard/tickets/{ticket}', [AgentTicketController::class, 'show'])
        ->name('dashboard.tickets.show');
    Route::put('/dashboard/tickets/{ticket}', [AgentTicketController::class, 'update'])
        ->name('dashboard.tickets.update');
    Route::post('/dashboard/tickets/{ticket}/notes', [AgentTicketController::class, 'storeNote'])
        ->name('dashboard.tickets.notes.store');
    Route::post('/dashboard/tickets/{ticket}/labels', [AgentTicketController::class, 'storeLabel'])
        ->name('dashboard.tickets.labels.store');
    Route::delete('/dashboard/tickets/{ticket}/labels/{ticketLabel}', [AgentTicketController::class, 'destroyLabel'])
        ->name('dashboard.tickets.labels.destroy');
    Route::post('/dashboard/tickets/{ticket}/replies', [AgentTicketController::class, 'storeReply'])
        ->name('dashboard.tickets.replies.store');
    Route::post('/dashboard/tickets/{ticket}/external-links', [AgentTicketExternalLinkController::class, 'store'])
        ->name('dashboard.tickets.external-links.store');
    Route::post('/dashboard/tickets/{ticket}/external-issues/github', [AgentTicketExternalIssueController::class, 'storeGithub'])
        ->name('dashboard.tickets.external-issues.github.store');
    Route::post('/dashboard/tickets/{ticket}/external-issues/gitlab', [AgentTicketExternalIssueController::class, 'storeGitlab'])
        ->name('dashboard.tickets.external-issues.gitlab.store');
    Route::post('/dashboard/tickets/{ticket}/external-issues/jira', [AgentTicketExternalIssueController::class, 'storeJira'])
        ->name('dashboard.tickets.external-issues.jira.store');
    Route::delete('/dashboard/tickets/{ticket}/external-links/{externalLink}', [AgentTicketExternalLinkController::class, 'destroy'])
        ->name('dashboard.tickets.external-links.destroy');
    Route::post('/dashboard/tickets/{ticket}/pending', [AgentTicketController::class, 'pending'])
        ->name('dashboard.tickets.pending');
    Route::post('/dashboard/tickets/{ticket}/close', [AgentTicketController::class, 'close'])
        ->name('dashboard.tickets.close');
    Route::post('/dashboard/tickets/{ticket}/reopen', [AgentTicketController::class, 'reopen'])
        ->name('dashboard.tickets.reopen');
    Route::put('/dashboard/tickets/{ticket}/assignee', [AgentTicketController::class, 'updateAssignee'])
        ->name('dashboard.tickets.assignee.update');
    Route::post('/dashboard/tickets/{ticket}/escalations', [AgentTicketController::class, 'storeEscalation'])
        ->name('dashboard.tickets.escalations.store');
    Route::get('/dashboard/conversations/{supportCode}/cobrowse/preview', [AgentConversationController::class, 'cobrowsePreview'])
        ->name('dashboard.conversations.cobrowse.preview');
    Route::post('/dashboard/conversations/{supportCode}/cobrowse/request', [AgentConversationController::class, 'requestCobrowse'])
        ->name('dashboard.conversations.cobrowse.request');
    Route::post('/dashboard/conversations/{supportCode}/cobrowse/resync', [AgentConversationController::class, 'requestCobrowseResync'])
        ->name('dashboard.conversations.cobrowse.resync');
    Route::post('/dashboard/conversations/{supportCode}/cobrowse/end', [AgentConversationController::class, 'endCobrowse'])
        ->name('dashboard.conversations.cobrowse.end');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth', EnsureAgentIsActive::class, EnsurePlatformOperator::class])
    ->prefix('operator')
    ->name('operator.')
    ->group(function (): void {
        Route::get('/', OperatorDashboardController::class)->name('dashboard');
        Route::post('/readiness/confirmations', [OperatorReadinessConfirmationController::class, 'storeFromOperator'])
            ->name('readiness.confirmations.store');
    });
