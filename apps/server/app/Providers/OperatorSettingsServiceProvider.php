<?php

namespace App\Providers;

use App\Support\Settings\OperatorSettings;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Applies operator-set config overrides at runtime (ADR 0011).
 *
 * Overrides are applied on actual work — per web request, per artisan command,
 * and before each queued job — but NEVER during `config:cache` (or `optimize`)
 * serialization. That command bootstraps a fresh app only to read and serialize
 * its config, running no request/command/job on it, so it never fires the
 * events below and the cache file it writes stays a clean env baseline that
 * runtime overrides layer on top of (rather than baking today's DB values in).
 *
 * applyOverrides() itself is fail-safe — an unreadable store or a corrupt secret
 * lands the env baseline, never a stale or half-applied config — and this catch
 * is a final guard around resolution.
 */
class OperatorSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OperatorSettings::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            // A command (mail-test, scheduled mail, queue:work) applies on start;
            // queued jobs re-apply before each job. config:cache's fresh app runs
            // no command, so nothing here fires while it serializes config.
            Event::listen(CommandStarting::class, fn () => $this->applyOverrides());
            Event::listen(JobProcessing::class, fn () => $this->applyOverrides());

            return;
        }

        // Web runs per request (FrankenPHP classic, no Octane): apply now.
        $this->applyOverrides();
    }

    private function applyOverrides(): void
    {
        try {
            $this->app->make(OperatorSettings::class)->applyOverrides();
        } catch (Throwable) {
            // Database/cache not reachable yet (fresh boot or mid-migration):
            // env defaults stand until the operator_settings table exists.
        }
    }
}
