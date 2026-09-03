<?php

use App\Http\Controllers\AgentAccountAgentAccessController;
use App\Http\Controllers\AgentAccountAgentController;
use App\Http\Controllers\AgentAccountAgentRoleController;
use App\Http\Controllers\AgentAccountApiTokenController;
use App\Http\Controllers\AgentAccountAuditController;
use App\Http\Controllers\AgentAccountBreakGlassController;
use App\Http\Controllers\AgentAccountController;
use App\Http\Controllers\AgentAccountCustomRoleController;
use App\Http\Controllers\AgentAccountIntegrationsController;
use App\Http\Controllers\AgentAccountOidcConnectionController;
use App\Http\Controllers\AgentAccountOutboundWebhookController;
use App\Http\Controllers\AgentAccountSecurityController;
use App\Http\Controllers\AgentAlertController;
use App\Http\Controllers\AgentArticleController;
use App\Http\Controllers\AgentConversationAttachmentController;
use App\Http\Controllers\AgentConversationController;
use App\Http\Controllers\AgentConversationQueueController;
use App\Http\Controllers\AgentConversationTypingController;
use App\Http\Controllers\AgentDashboardController;
use App\Http\Controllers\AgentExternalIssueProviderConnectionController;
use App\Http\Controllers\AgentProfileController;
use App\Http\Controllers\AgentProfileTwoFactorController;
use App\Http\Controllers\AgentReplyTemplateController;
use App\Http\Controllers\AgentReportController;
use App\Http\Controllers\AgentSiteController;
use App\Http\Controllers\AgentSiteExternalIssueProjectController;
use App\Http\Controllers\AgentSupportCodeLookupController;
use App\Http\Controllers\AgentTicketController;
use App\Http\Controllers\AgentTicketExternalIssueController;
use App\Http\Controllers\AgentTicketExternalLinkController;
use App\Http\Controllers\AgentTicketLabelController;
use App\Http\Controllers\AgentTicketQueueController;
use App\Http\Controllers\AgentVisitorController;
use App\Http\Controllers\Auth\OidcSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\FirstRunSetupController;
use App\Http\Controllers\OperatorBackupSettingsController;
use App\Http\Controllers\OperatorBreakGlassController;
use App\Http\Controllers\OperatorBreakGlassViewerController;
use App\Http\Controllers\OperatorDashboardController;
use App\Http\Controllers\OperatorLocalizationSettingsController;
use App\Http\Controllers\OperatorMailSettingsController;
use App\Http\Controllers\OperatorOnboardingController;
use App\Http\Controllers\OperatorReadinessConfirmationController;
use App\Http\Controllers\OperatorScanningSettingsController;
use App\Http\Controllers\OperatorStorageSettingsController;
use App\Http\Controllers\Widget\WidgetScriptController;
use App\Http\Middleware\EnsureAgentIsActive;
use App\Http\Middleware\EnsurePlatformOperator;
use App\Http\Middleware\EnsureTwoFactorPolicy;
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
    Route::post('/sso', [OidcSessionController::class, 'redirect'])
        ->middleware('throttle:oidc-redirect')
        ->name('oidc.redirect');
    Route::get('/sso/callback/{connectionPublicId}', [OidcSessionController::class, 'callback'])
        ->whereUuid('connectionPublicId')
        ->middleware('throttle:oidc-callback')
        ->name('oidc.callback');
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'create'])
        ->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor-challenge')
        ->name('two-factor.challenge.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])
        ->middleware('throttle:password-reset-request')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:password-reset-submit')
        ->name('password.update');
});

Route::middleware(['auth', 'auth.session', EnsureAgentIsActive::class, EnsureTwoFactorPolicy::class])->group(function () {
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
    Route::post('/dashboard/profile/two-factor', [AgentProfileTwoFactorController::class, 'start'])
        ->name('dashboard.profile.two-factor.start');
    Route::put('/dashboard/profile/two-factor', [AgentProfileTwoFactorController::class, 'confirm'])
        ->middleware('throttle:two-factor-confirmation')
        ->name('dashboard.profile.two-factor.confirm');
    Route::delete('/dashboard/profile/two-factor/enrolment', [AgentProfileTwoFactorController::class, 'cancel'])
        ->name('dashboard.profile.two-factor.cancel');
    Route::post('/dashboard/profile/two-factor/recovery-codes', [AgentProfileTwoFactorController::class, 'regenerate'])
        ->middleware('throttle:two-factor-confirmation')
        ->name('dashboard.profile.two-factor.recovery-codes.regenerate');
    Route::delete('/dashboard/profile/two-factor', [AgentProfileTwoFactorController::class, 'disable'])
        ->middleware('throttle:two-factor-confirmation')
        ->name('dashboard.profile.two-factor.disable');
    Route::get('/dashboard/account', AgentAccountController::class)
        ->name('dashboard.account.show');
    Route::get('/dashboard/account/roles', [AgentAccountCustomRoleController::class, 'index'])
        ->name('dashboard.account.roles.index');
    Route::post('/dashboard/account/roles', [AgentAccountCustomRoleController::class, 'store'])
        ->name('dashboard.account.roles.store');
    Route::put('/dashboard/account/roles/{customRole}', [AgentAccountCustomRoleController::class, 'update'])
        ->whereNumber('customRole')
        ->name('dashboard.account.roles.update');
    Route::delete('/dashboard/account/roles/{customRole}', [AgentAccountCustomRoleController::class, 'destroy'])
        ->whereNumber('customRole')
        ->name('dashboard.account.roles.destroy');
    Route::get('/dashboard/account/security', [AgentAccountSecurityController::class, 'show'])
        ->name('dashboard.account.security.show');
    Route::put('/dashboard/account/security', [AgentAccountSecurityController::class, 'update'])
        ->name('dashboard.account.security.update');
    Route::put('/dashboard/account/security/oidc', [AgentAccountOidcConnectionController::class, 'update'])
        ->name('dashboard.account.security.oidc.update');
    Route::get('/dashboard/account/integrations', [AgentAccountIntegrationsController::class, 'show'])
        ->name('dashboard.account.integrations');
    Route::get('/dashboard/account/api-tokens', [AgentAccountApiTokenController::class, 'index'])
        ->name('dashboard.account.api-tokens.index');
    Route::post('/dashboard/account/api-tokens', [AgentAccountApiTokenController::class, 'store'])
        ->name('dashboard.account.api-tokens.store');
    Route::delete('/dashboard/account/api-tokens/{apiToken}', [AgentAccountApiTokenController::class, 'destroy'])
        // Numeric ids only. The controller takes the id raw so model binding
        // cannot answer before the authority check, which means a malformed id
        // would otherwise reach `whereKey()` as a string -- and PostgreSQL
        // raises on comparing that to a bigint, turning a bad URL into a 500
        // where the point was an indistinguishable 404. SQLite accepts it, so
        // the suite could never have shown this.
        ->whereNumber('apiToken')
        ->name('dashboard.account.api-tokens.destroy');
    Route::post('/dashboard/account/outbound-webhooks', [AgentAccountOutboundWebhookController::class, 'store'])
        ->name('dashboard.account.outbound-webhooks.store');
    Route::delete('/dashboard/account/outbound-webhooks/{webhookEndpoint}', [AgentAccountOutboundWebhookController::class, 'destroy'])
        ->whereNumber('webhookEndpoint')
        ->name('dashboard.account.outbound-webhooks.destroy');
    Route::post('/dashboard/account/outbound-webhook-deliveries/{webhookDelivery}/retry', [AgentAccountOutboundWebhookController::class, 'retry'])
        ->whereNumber('webhookDelivery')
        ->name('dashboard.account.outbound-webhooks.retry');
    Route::get('/dashboard/account/operator-access', [AgentAccountBreakGlassController::class, 'index'])
        ->name('dashboard.account.break-glass.index');
    Route::post('/dashboard/account/operator-access/{grant}/approve', [AgentAccountBreakGlassController::class, 'approve'])
        ->name('dashboard.account.break-glass.approve');
    Route::post('/dashboard/account/operator-access/{grant}/deny', [AgentAccountBreakGlassController::class, 'deny'])
        ->name('dashboard.account.break-glass.deny');
    Route::post('/dashboard/account/operator-access/{grant}/close', [AgentAccountBreakGlassController::class, 'close'])
        ->name('dashboard.account.break-glass.close');
    Route::get('/dashboard/account/audit', [AgentAccountAuditController::class, 'index'])
        ->name('dashboard.account.audit.index');
    Route::get('/dashboard/account/audit/export', [AgentAccountAuditController::class, 'export'])
        ->name('dashboard.account.audit.export');
    Route::get('/dashboard/reports', [AgentReportController::class, 'index'])
        ->name('dashboard.reports.index');
    Route::get('/dashboard/reports/export', [AgentReportController::class, 'export'])
        ->name('dashboard.reports.export');
    Route::get('/dashboard/account/labels', [AgentTicketLabelController::class, 'index'])
        ->name('dashboard.account.labels.index');
    Route::post('/dashboard/account/labels', [AgentTicketLabelController::class, 'store'])
        ->name('dashboard.account.labels.store');
    Route::put('/dashboard/account/labels/{ticketLabel}', [AgentTicketLabelController::class, 'update'])
        ->name('dashboard.account.labels.update');
    Route::delete('/dashboard/account/labels/{ticketLabel}', [AgentTicketLabelController::class, 'destroy'])
        ->name('dashboard.account.labels.destroy');
    Route::get('/dashboard/account/articles', [AgentArticleController::class, 'index'])
        ->name('dashboard.account.articles.index');
    Route::post('/dashboard/account/articles', [AgentArticleController::class, 'store'])
        ->name('dashboard.account.articles.store');
    Route::get('/dashboard/account/articles/{article}', [AgentArticleController::class, 'show'])
        ->name('dashboard.account.articles.show');
    Route::put('/dashboard/account/articles/{article}', [AgentArticleController::class, 'update'])
        ->name('dashboard.account.articles.update');
    Route::post('/dashboard/account/articles/{article}/publish', [AgentArticleController::class, 'publish'])
        ->name('dashboard.account.articles.publish');
    Route::delete('/dashboard/account/articles/{article}', [AgentArticleController::class, 'destroy'])
        ->name('dashboard.account.articles.destroy');
    Route::get('/dashboard/account/reply-templates', [AgentReplyTemplateController::class, 'index'])
        ->name('dashboard.account.reply-templates.index');
    Route::post('/dashboard/account/reply-templates', [AgentReplyTemplateController::class, 'store'])
        ->name('dashboard.account.reply-templates.store');
    Route::put('/dashboard/account/reply-templates/{replyTemplate}', [AgentReplyTemplateController::class, 'update'])
        ->name('dashboard.account.reply-templates.update');
    Route::post('/dashboard/account/reply-templates/{replyTemplate}/archive', [AgentReplyTemplateController::class, 'archive'])
        ->name('dashboard.account.reply-templates.archive');
    // Readiness is an instance report about mail, queues, storage and
    // scanning -- an operator's job, not an account's. This route predates the
    // operator console and served the same report to account admins, who then
    // got a 403 on every settings page that could act on it. The console owns
    // it now; operators following an old link land there, and everyone else
    // gets the same 403 the rest of /operator gives them.
    Route::redirect('/dashboard/readiness', '/operator')
        ->name('dashboard.readiness.show');
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
    Route::put('/dashboard/sites/{site}/intake', [AgentSiteController::class, 'updateIntake'])
        ->name('dashboard.sites.intake.update');

    Route::put('/dashboard/sites/{site}/rating', [AgentSiteController::class, 'updateRating'])
        ->name('dashboard.sites.rating.update');
    Route::get('/dashboard/sites/{site}/live', [AgentSiteController::class, 'live'])
        ->name('dashboard.sites.live');
    Route::put('/dashboard/sites/{site}/presence', [AgentSiteController::class, 'updatePresence'])
        ->name('dashboard.sites.presence.update');
    Route::put('/dashboard/sites/{site}/language', [AgentSiteController::class, 'updateLanguage'])
        ->name('dashboard.sites.language.update');
    Route::put('/dashboard/sites/{site}/availability', [AgentSiteController::class, 'updateAvailability'])
        ->name('dashboard.sites.availability.update');
    Route::put('/dashboard/sites/{site}/inbound-address', [AgentSiteController::class, 'updateInboundAddress'])
        ->name('dashboard.sites.inbound-address.update');
    Route::put('/dashboard/sites/{site}/appearance', [AgentSiteController::class, 'updateAppearance'])
        ->name('dashboard.sites.appearance.update');
    Route::post('/dashboard/sites/{site}/availability/close', [AgentSiteController::class, 'closeAvailability'])
        ->name('dashboard.sites.availability.close');
    Route::delete('/dashboard/sites/{site}/availability/close', [AgentSiteController::class, 'reopenAvailability'])
        ->name('dashboard.sites.availability.reopen');
    Route::put('/dashboard/sites/{site}/details', [AgentSiteController::class, 'updateDetails'])
        ->name('dashboard.sites.details.update');
    Route::post('/dashboard/sites/{site}/archive', [AgentSiteController::class, 'archive'])
        ->name('dashboard.sites.archive');
    Route::post('/dashboard/sites/{site}/unarchive', [AgentSiteController::class, 'unarchive'])
        ->name('dashboard.sites.unarchive');
    Route::delete('/dashboard/sites/{site}', [AgentSiteController::class, 'purge'])
        ->name('dashboard.sites.purge');
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
    Route::get('/dashboard/visitors', [AgentVisitorController::class, 'index'])
        ->name('dashboard.visitors.index');
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

Route::middleware(['auth', 'auth.session', EnsureAgentIsActive::class, EnsureTwoFactorPolicy::class, EnsurePlatformOperator::class])
    ->prefix('operator')
    ->name('operator.')
    ->group(function (): void {
        Route::get('/', OperatorDashboardController::class)->name('dashboard');
        Route::get('/onboarding', OperatorOnboardingController::class)->name('onboarding');
        Route::get('/settings/mail', [OperatorMailSettingsController::class, 'edit'])
            ->name('settings.mail.edit');
        Route::post('/settings/mail', [OperatorMailSettingsController::class, 'update'])
            ->name('settings.mail.update');
        Route::post('/settings/mail/test', [OperatorMailSettingsController::class, 'test'])
            ->name('settings.mail.test');
        Route::get('/settings/storage', [OperatorStorageSettingsController::class, 'edit'])
            ->name('settings.storage.edit');
        Route::post('/settings/storage', [OperatorStorageSettingsController::class, 'update'])
            ->name('settings.storage.update');
        Route::post('/settings/storage/test', [OperatorStorageSettingsController::class, 'test'])
            ->name('settings.storage.test');
        Route::get('/settings/localization', [OperatorLocalizationSettingsController::class, 'edit'])
            ->name('settings.localization.edit');
        Route::post('/settings/localization', [OperatorLocalizationSettingsController::class, 'update'])
            ->name('settings.localization.update');
        Route::get('/settings/scanning', [OperatorScanningSettingsController::class, 'edit'])
            ->name('settings.scanning.edit');
        Route::post('/settings/scanning', [OperatorScanningSettingsController::class, 'update'])
            ->name('settings.scanning.update');
        Route::post('/settings/scanning/test', [OperatorScanningSettingsController::class, 'test'])
            ->name('settings.scanning.test');
        Route::get('/settings/backups', [OperatorBackupSettingsController::class, 'edit'])
            ->name('settings.backups.edit');
        Route::get('/settings/backups/history', [OperatorBackupSettingsController::class, 'history'])
            ->name('settings.backups.history');
        Route::post('/settings/backups', [OperatorBackupSettingsController::class, 'update'])
            ->name('settings.backups.update');
        Route::post('/settings/backups/test', [OperatorBackupSettingsController::class, 'test'])
            ->name('settings.backups.test');
        Route::post('/settings/backups/run', [OperatorBackupSettingsController::class, 'run'])
            ->name('settings.backups.run');
        Route::get('/settings/backups/restore', [OperatorBackupSettingsController::class, 'restore'])
            ->name('settings.backups.restore');
        Route::post('/settings/backups/restore', [OperatorBackupSettingsController::class, 'restoreRun'])
            ->name('settings.backups.restore.run');
        Route::post('/readiness/confirmations', [OperatorReadinessConfirmationController::class, 'storeFromOperator'])
            ->name('readiness.confirmations.store');
        Route::get('/break-glass', [OperatorBreakGlassController::class, 'index'])
            ->name('break-glass.index');
        Route::post('/break-glass', [OperatorBreakGlassController::class, 'store'])
            ->name('break-glass.store');
        Route::post('/break-glass/{grant}/approve', [OperatorBreakGlassController::class, 'approve'])
            ->name('break-glass.approve');
        Route::post('/break-glass/{grant}/close', [OperatorBreakGlassController::class, 'close'])
            ->name('break-glass.close');
        Route::get('/break-glass/{grant}', [OperatorBreakGlassViewerController::class, 'show'])
            ->name('break-glass.show');
        Route::get('/break-glass/{grant}/conversations/{conversation}', [OperatorBreakGlassViewerController::class, 'conversation'])
            ->name('break-glass.conversations.show');
        Route::get('/break-glass/{grant}/tickets/{ticket}', [OperatorBreakGlassViewerController::class, 'ticket'])
            ->name('break-glass.tickets.show');
    });
