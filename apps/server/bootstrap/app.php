<?php

use App\Console\Commands\AlertDigestPreviewCommand;
use App\Console\Commands\BootstrapWayfindrCommand;
use App\Console\Commands\CobrowseTransportSmokeCommand;
use App\Console\Commands\CreateAgentCommand;
use App\Console\Commands\MailTestCommand;
use App\Console\Commands\PruneCobrowseContentCommand;
use App\Console\Commands\SendAlertDigestsCommand;
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
        BootstrapWayfindrCommand::class,
        CobrowseTransportSmokeCommand::class,
        CreateAgentCommand::class,
        MailTestCommand::class,
        PruneCobrowseContentCommand::class,
        SendAlertDigestsCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
