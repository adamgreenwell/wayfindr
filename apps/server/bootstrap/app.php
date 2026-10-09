<?php

use App\Console\Commands\AlertDigestPreviewCommand;
use App\Console\Commands\BackupCommand;
use App\Console\Commands\BootstrapWayfindrCommand;
use App\Console\Commands\CobrowseTransportSmokeCommand;
use App\Console\Commands\CreateAgentCommand;
use App\Console\Commands\ExpireBreakGlassGrantsCommand;
use App\Console\Commands\HostUpdaterStatusCommand;
use App\Console\Commands\MailTestCommand;
use App\Console\Commands\MeasureAttachmentRetentionCommand;
use App\Console\Commands\ProtectiveBackupCommand;
use App\Console\Commands\PruneCobrowseContentCommand;
use App\Console\Commands\QueueAgentRealtimeEvictionsCommand;
use App\Console\Commands\QueueConversationReplyDeliveriesCommand;
use App\Console\Commands\RestoreCommand;
use App\Console\Commands\SanitiseStoredPageUrlsCommand;
use App\Console\Commands\SendAlertDigestsCommand;
use App\Console\Commands\SendUnattendedConversationAlertsCommand;
use App\Console\Commands\SweepOrphanedAttachmentsCommand;
use App\Console\Commands\TranslateCatalogueCommand;
use App\Console\Commands\UpdatePlanCommand;
use App\Console\Commands\UpgradeGuardCommand;
use App\Console\Commands\UpgradeWindowCommand;
use App\Http\Middleware\EnsureAgentIsActive;
use App\Http\Middleware\EnsureTwoFactorPolicy;
use App\Http\Middleware\RefuseServingDuringManagedUpdate;
use App\Http\Middleware\RefuseServingWhileErasuresAreOutstanding;
use App\Http\Middleware\RefuseServingWithUnmetRequirements;
use App\Http\Middleware\RefuseUnreadableJson;
use App\Http\Middleware\SerializeAgentBroadcastAuthorization;
use App\Http\Middleware\SetDashboardLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Secret-bearing values pass through framework and dependency call frames
// that Wayfindr cannot annotate. Omitting arguments from every exception trace
// keeps passwords, TOTP secrets, recovery codes, and provider credentials out
// of local debug pages and remote exception reporters alike.
ini_set('zend.exception_ignore_args', '1');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Public assets, with NO middleware group at all -- not `web`, so no
        // session is started and no cookie is set for a visitor who has only
        // loaded a page. See routes/asset.php for why that matters (#955).
        then: function (): void {
            Route::group([], __DIR__.'/../routes/asset.php');
        },
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'middleware' => [
            'web',
            'auth',
            'auth.session',
            EnsureAgentIsActive::class,
            EnsureTwoFactorPolicy::class,
            SerializeAgentBroadcastAuthorization::class,
        ],
    ])
    ->withCommands([
        AlertDigestPreviewCommand::class,
        BackupCommand::class,
        BootstrapWayfindrCommand::class,
        CobrowseTransportSmokeCommand::class,
        CreateAgentCommand::class,
        ExpireBreakGlassGrantsCommand::class,
        HostUpdaterStatusCommand::class,
        MailTestCommand::class,
        MeasureAttachmentRetentionCommand::class,
        ProtectiveBackupCommand::class,
        PruneCobrowseContentCommand::class,
        QueueAgentRealtimeEvictionsCommand::class,
        QueueConversationReplyDeliveriesCommand::class,
        RestoreCommand::class,
        SanitiseStoredPageUrlsCommand::class,
        SendAlertDigestsCommand::class,
        SendUnattendedConversationAlertsCommand::class,
        SweepOrphanedAttachmentsCommand::class,
        TranslateCatalogueCommand::class,
        UpgradeGuardCommand::class,
        UpgradeWindowCommand::class,
        UpdatePlanCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // This operation-owned hold has no bypass cookies or excluded paths.
        // Prepend it so no PHP request reaches session or route writers first.
        $middleware->prepend(RefuseServingDuringManagedUpdate::class);

        // Only containerized behind-proxy installs set TRUSTED_PROXIES (the
        // self-hosting env generator's --behind-proxy mode); everywhere else
        // this is null and no proxy is trusted.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));

        // Refuses traffic while an after-start requirement is outstanding
        // (ADR 0013). Appended globally rather than to a route group, because a
        // release that is not fit to serve is not fit to serve anything.
        $middleware->append(RefuseServingWithUnmetRequirements::class);

        // And while a restore's erasures are not re-applied yet (ADR 0026 §8):
        // the restored database holds contacts who asked to be erased.
        $middleware->append(RefuseServingWhileErasuresAreOutstanding::class);

        // After the session is available, so there is an agent to read a
        // language preference from. Web only: the widget carries its own
        // catalogue and the public API answers machines, neither of which has
        // a signed-in person to have a preference.
        $middleware->web(append: SetDashboardLocale::class);

        // Before any widget controller reads its input: an undecodable JSON
        // body otherwise arrives as an empty one and is answered as whatever
        // its missing fields imply -- 404 "Site not found." for the widget.
        $middleware->api(append: RefuseUnreadableJson::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The public API answers in JSON whether or not the caller asked for
        // it (ADR 0018). Laravel decides that from `Accept`, so without this a
        // client that omits the header -- including the curl example in our own
        // docs -- gets an HTML error page or a redirect where the contract
        // promises a 422 or a 404. Every test uses `getJson()`, which sets the
        // header, so the suite could never have shown it.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/v1/*') || $request->expectsJson(),
        );

        // On a validation failure Laravel flashes the request input to the
        // session as old input. Keep operator secrets (storage, integrations,
        // and Web Push private keys) out of
        // that plaintext flash, alongside the framework's password defaults —
        // they are encrypted at rest and must never land in the session store.
        // `credential_token` and `webhook_secret` are the provider connection
        // form's: a typo in its base URL used to flash both. Every password
        // field in a view is held to this list by SecretInputFlashTest.
        $exceptions->dontFlash(['s3_access_key', 's3_secret_key', 'one_time_code', 'client_secret', 'private_key', 'api_key', 'credential_token', 'webhook_secret']);
    })->create();
