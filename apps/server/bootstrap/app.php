<?php

use App\Console\Commands\AlertDigestPreviewCommand;
use App\Console\Commands\BackupCommand;
use App\Console\Commands\BootstrapWayfindrCommand;
use App\Console\Commands\CobrowseTransportSmokeCommand;
use App\Console\Commands\CreateAgentCommand;
use App\Console\Commands\ExpireBreakGlassGrantsCommand;
use App\Console\Commands\MailTestCommand;
use App\Console\Commands\PruneCobrowseContentCommand;
use App\Console\Commands\RestoreCommand;
use App\Console\Commands\SendAlertDigestsCommand;
use App\Console\Commands\SendUnattendedConversationAlertsCommand;
use App\Console\Commands\SweepOrphanedAttachmentsCommand;
use App\Console\Commands\UpgradeGuardCommand;
use App\Http\Middleware\RefuseServingWithUnmetRequirements;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        AlertDigestPreviewCommand::class,
        BackupCommand::class,
        BootstrapWayfindrCommand::class,
        CobrowseTransportSmokeCommand::class,
        CreateAgentCommand::class,
        ExpireBreakGlassGrantsCommand::class,
        MailTestCommand::class,
        PruneCobrowseContentCommand::class,
        RestoreCommand::class,
        SendAlertDigestsCommand::class,
        SendUnattendedConversationAlertsCommand::class,
        SweepOrphanedAttachmentsCommand::class,
        UpgradeGuardCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Only containerized behind-proxy installs set TRUSTED_PROXIES (the
        // self-hosting env generator's --behind-proxy mode); everywhere else
        // this is null and no proxy is trusted.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));

        // Refuses traffic while an after-start requirement is outstanding
        // (ADR 0013). Appended globally rather than to a route group, because a
        // release that is not fit to serve is not fit to serve anything.
        $middleware->append(RefuseServingWithUnmetRequirements::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // On a validation failure Laravel flashes the request input to the
        // session as old input. Keep operator secrets (S3 access keys) out of
        // that plaintext flash, alongside the framework's password defaults —
        // they are encrypted at rest and must never land in the session store.
        $exceptions->dontFlash(['s3_access_key', 's3_secret_key']);
    })->create();
